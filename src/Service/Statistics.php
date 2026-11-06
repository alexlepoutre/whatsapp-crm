<?php

namespace App\Service;

use App\Entity\Organization;
use App\Enum\ConversationStatus;
use App\Enum\DeliveryStatus;
use App\Enum\MessageSender;
use Doctrine\DBAL\Connection;

/**
 * Sans organisation, le rapport couvre tout le service : réservé au super-admin.
 *
 * Les dates sont en base à l'heure du serveur et les jours se comptent à l'heure de Paris :
 * les regroupements se font ici plutôt qu'en SQL, où MariaDB n'a pas forcément les fuseaux.
 */
final class Statistics
{
    private const DAILY_LIMIT = 92;
    private const REALLY_SENT = [DeliveryStatus::SENT, DeliveryStatus::DELIVERED, DeliveryStatus::READ];

    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array<string, mixed> */
    public function report(?Organization $organization, StatisticsPeriod $period): array
    {
        $scope = $organization ? ' AND c.organization_id = :organization' : '';
        $parameters = [
            'from' => $this->storedDate($period->from),
            'end' => $this->storedDate($period->end()),
        ] + ($organization ? ['organization' => $organization->getId()] : []);

        return $this->conversations($scope, $parameters)
            + $this->clients($scope, $parameters)
            + $this->messages($scope, $parameters, $period)
            + $this->replies($scope, $parameters)
            + $this->closures($scope, $parameters)
            + $this->waiting($scope, $parameters);
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array{newConversations: int, whatsapp: int, form: int}
     */
    private function conversations(string $scope, array $parameters): array
    {
        $counts = $this->connection->fetchAllKeyValue(
            'SELECT v.channel, COUNT(*) FROM conversation v JOIN client c ON c.id = v.client_id
             WHERE v.created_at >= :from AND v.created_at < :end'.$scope.' GROUP BY v.channel',
            $parameters,
        );

        return [
            'newConversations' => (int) array_sum($counts),
            'whatsapp' => (int) ($counts['whatsapp'] ?? 0),
            'form' => (int) ($counts['form'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array{newClients: int}
     */
    private function clients(string $scope, array $parameters): array
    {
        return ['newClients' => (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM client c WHERE c.created_at >= :from AND c.created_at < :end'.$scope,
            $parameters,
        )];
    }

    /**
     * « Envoyé » ne compte que ce qui est vraiment parti : un message simulé ou en échec n'y entre pas.
     *
     * @param array<string, mixed> $parameters
     *
     * @return array{received: int, sent: int, automatic: int, unsent: int, series: list<array{label: string, received: int, sent: int}>, monthly: bool}
     */
    private function messages(string $scope, array $parameters, StatisticsPeriod $period): array
    {
        $monthly = $period->days() > self::DAILY_LIMIT;
        $series = $this->emptySeries($period, $monthly);
        $totals = ['received' => 0, 'sent' => 0, 'automatic' => 0, 'unsent' => 0];
        $timezone = new \DateTimeZone(StatisticsPeriod::TIMEZONE);
        $reallySent = array_map(static fn (DeliveryStatus $status) => $status->value, self::REALLY_SENT);

        $rows = $this->connection->iterateAssociative(
            'SELECT m.created_at, m.sender, m.delivery_status FROM message m
             JOIN conversation v ON v.id = m.conversation_id JOIN client c ON c.id = v.client_id
             WHERE m.created_at >= :from AND m.created_at < :end'.$scope,
            $parameters,
        );

        foreach ($rows as $row) {
            $key = (new \DateTimeImmutable($row['created_at']))->setTimezone($timezone)->format($monthly ? 'Y-m' : 'Y-m-d');

            if (MessageSender::CLIENT->value === $row['sender']) {
                ++$totals['received'];
                ++$series[$key]['received'];
            } elseif (!\in_array($row['delivery_status'], $reallySent, true)) {
                ++$totals['unsent'];
            } elseif (MessageSender::BOT->value === $row['sender']) {
                ++$totals['automatic'];
            } else {
                ++$totals['sent'];
                ++$series[$key]['sent'];
            }
        }

        return $totals + ['series' => array_values($series), 'monthly' => $monthly];
    }

    /** @return array<string, array{label: string, received: int, sent: int}> */
    private function emptySeries(StatisticsPeriod $period, bool $monthly): array
    {
        $series = [];
        $step = $monthly ? $period->from->modify('first day of this month') : $period->from;

        while ($step <= $period->to) {
            $series[$step->format($monthly ? 'Y-m' : 'Y-m-d')] = [
                'label' => $step->format($monthly ? 'm/Y' : 'd/m'),
                'received' => 0,
                'sent' => 0,
            ];
            $step = $step->modify($monthly ? '+1 month' : '+1 day');
        }

        return $series;
    }

    /**
     * Le délai court du premier message client resté sans réponse jusqu'à la réponse de l'équipe,
     * et compte le jour de cette réponse. Les réponses automatiques et les envois en échec n'en sont pas.
     *
     * @param array<string, mixed> $parameters
     *
     * @return array{replyAverage: ?float, replyMedian: ?float, replies: int}
     */
    private function replies(string $scope, array $parameters): array
    {
        $rows = $this->connection->iterateAssociative(
            'SELECT m.conversation_id, m.sender, m.created_at FROM message m
             JOIN conversation v ON v.id = m.conversation_id JOIN client c ON c.id = v.client_id
             WHERE m.created_at < :end AND (m.sender = :client OR (m.sender = :owner AND COALESCE(m.delivery_status, \'\') <> :failed))
             AND m.conversation_id IN (SELECT DISTINCT conversation_id FROM message WHERE sender = :owner AND created_at >= :from AND created_at < :end)'.$scope.'
             ORDER BY m.conversation_id, m.created_at, m.id',
            $parameters + [
                'client' => MessageSender::CLIENT->value,
                'owner' => MessageSender::OWNER->value,
                'failed' => DeliveryStatus::FAILED->value,
            ],
        );

        $delays = [];
        $conversation = null;
        $waitingSince = null;

        foreach ($rows as $row) {
            if ($row['conversation_id'] !== $conversation) {
                $conversation = $row['conversation_id'];
                $waitingSince = null;
            }

            $at = new \DateTimeImmutable($row['created_at']);

            if (MessageSender::CLIENT->value === $row['sender']) {
                $waitingSince ??= $at;
            } elseif ($waitingSince) {
                if ($row['created_at'] >= $parameters['from']) {
                    $delays[] = $at->getTimestamp() - $waitingSince->getTimestamp();
                }
                $waitingSince = null;
            }
        }

        return [
            'replyAverage' => $delays ? (float) array_sum($delays) / \count($delays) : null,
            'replyMedian' => $this->median($delays),
            'replies' => \count($delays),
        ];
    }

    /** @param list<int> $values */
    private function median(array $values): ?float
    {
        if (!$values) {
            return null;
        }

        sort($values);
        $middle = intdiv(\count($values), 2);

        return \count($values) % 2 ? (float) $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2.0;
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array{closed: int, closureAverage: ?float}
     */
    private function closures(string $scope, array $parameters): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS total, AVG(TIMESTAMPDIFF(SECOND, v.created_at, v.closed_at)) AS average
             FROM conversation v JOIN client c ON c.id = v.client_id
             WHERE v.closed_at >= :from AND v.closed_at < :end'.$scope,
            $parameters,
        );

        return [
            'closed' => (int) $row['total'],
            'closureAverage' => null === $row['average'] ? null : (float) $row['average'],
        ];
    }

    /**
     * L'état présent : il ne dépend pas de la plage choisie.
     *
     * @param array<string, mixed> $parameters
     *
     * @return array{waiting: int, oldestWaiting: ?float}
     */
    private function waiting(string $scope, array $parameters): array
    {
        unset($parameters['from'], $parameters['end']);

        $row = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS total, MIN(v.last_inbound_at) AS oldest FROM conversation v
             JOIN client c ON c.id = v.client_id WHERE v.status = :open'.$scope,
            $parameters + ['open' => ConversationStatus::OPEN->value],
        );

        return [
            'waiting' => (int) $row['total'],
            'oldestWaiting' => $row['oldest'] ? (float) (time() - (new \DateTimeImmutable($row['oldest']))->getTimestamp()) : null,
        ];
    }

    private function storedDate(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }
}
