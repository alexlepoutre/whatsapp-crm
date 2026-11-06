<?php

namespace App\Tests\Functional;

use App\Entity\Client;
use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\Organization;
use App\Entity\User;
use App\Enum\Channel;
use App\Enum\DeliveryStatus;
use App\Enum\MessageSender;
use App\Service\Statistics;
use App\Service\StatisticsPeriod;

class StatisticsTest extends AppTestCase
{
    private function conversation(Organization $organization, Channel $channel = Channel::WHATSAPP): Conversation
    {
        $client = new Client($organization);
        $conversation = new Conversation($client, $channel);
        $this->em->persist($client);
        $this->em->persist($conversation);

        return $conversation;
    }

    private function message(Conversation $conversation, MessageSender $sender, string $at, ?DeliveryStatus $status = DeliveryStatus::SENT): void
    {
        $message = new Message($conversation, $sender, 'Texte');

        if (MessageSender::CLIENT !== $sender) {
            $message->markDelivery($status);
        }

        $this->em->persist($message);
        $this->em->flush();
        $this->em->getConnection()->update('message', ['created_at' => $at], ['id' => $message->getId()]);
    }

    /** @return array<string, mixed> */
    private function report(?Organization $organization, string $from = '2026-10-01', string $to = '2026-10-31'): array
    {
        return static::getContainer()->get(Statistics::class)->report($organization, StatisticsPeriod::fromStrings($from, $to));
    }

    public function testCountsOnlyWhatReallyLeft(): void
    {
        $conversation = $this->conversation($this->itroom);
        $this->message($conversation, MessageSender::CLIENT, '2026-10-10 08:00:00');
        $this->message($conversation, MessageSender::BOT, '2026-10-10 08:00:01');
        $this->message($conversation, MessageSender::OWNER, '2026-10-10 09:00:00', DeliveryStatus::READ);
        $this->message($conversation, MessageSender::OWNER, '2026-10-10 09:05:00', DeliveryStatus::SIMULATED);
        $this->message($conversation, MessageSender::OWNER, '2026-10-10 09:06:00', DeliveryStatus::FAILED);
        $this->message($conversation, MessageSender::CLIENT, '2026-11-20 08:00:00');

        $report = $this->report($this->itroom);

        self::assertSame(1, $report['received']);
        self::assertSame(1, $report['sent']);
        self::assertSame(1, $report['automatic']);
        self::assertSame(2, $report['unsent']);
    }

    public function testReplyDelayStartsAtFirstUnansweredMessageAndIgnoresTheBot(): void
    {
        $conversation = $this->conversation($this->itroom);
        // Le client écrit fin septembre, la réponse arrive dans la plage : elle compte.
        $this->message($conversation, MessageSender::CLIENT, '2026-09-30 23:00:00');
        $this->message($conversation, MessageSender::BOT, '2026-09-30 23:00:01');
        $this->message($conversation, MessageSender::CLIENT, '2026-09-30 23:30:00');
        $this->message($conversation, MessageSender::OWNER, '2026-10-01 01:00:00');
        // Une seconde réponse sans nouveau message du client ne mesure rien.
        $this->message($conversation, MessageSender::OWNER, '2026-10-01 02:00:00');
        $this->message($conversation, MessageSender::CLIENT, '2026-10-02 10:00:00');
        $this->message($conversation, MessageSender::OWNER, '2026-10-02 11:00:00');

        $report = $this->report($this->itroom);

        self::assertSame(2, $report['replies']);
        self::assertSame(5400.0, $report['replyAverage']);
        self::assertSame(5400.0, $report['replyMedian']);
    }

    public function testDaysFollowParisTime(): void
    {
        $conversation = $this->conversation($this->itroom);
        // 22 h 30 en temps universel : déjà le 11 à Paris, en heure d'été.
        $this->message($conversation, MessageSender::CLIENT, '2026-10-10 22:30:00');

        $series = array_column($this->report($this->itroom)['series'], 'received', 'label');

        self::assertSame(0, $series['10/10']);
        self::assertSame(1, $series['11/10']);
    }

    public function testClosureDelayIsForgottenOnReopen(): void
    {
        $conversation = $this->conversation($this->itroom);
        $conversation->close();
        $this->em->flush();
        self::assertNotNull($conversation->getClosedAt());

        $conversation->reopen();
        self::assertNull($conversation->getClosedAt());

        $conversation->close();
        $this->em->flush();
        $connection = $this->em->getConnection();
        $connection->update('conversation', ['created_at' => '2026-10-10 08:00:00', 'closed_at' => '2026-10-10 10:00:00'], ['id' => $conversation->getId()]);

        $report = $this->report($this->itroom);

        self::assertSame(1, $report['closed']);
        self::assertSame(7200.0, $report['closureAverage']);
    }

    public function testOrganizationOnlySeesItsOwnFigures(): void
    {
        $other = $this->createOrganization('dufermont', 'Dufermont');
        $this->message($this->conversation($this->itroom), MessageSender::CLIENT, '2026-10-10 08:00:00');
        $this->message($this->conversation($other), MessageSender::CLIENT, '2026-10-10 08:00:00');
        $this->message($this->conversation($other), MessageSender::CLIENT, '2026-10-10 08:00:00');

        self::assertSame(1, $this->report($this->itroom)['received']);
        self::assertSame(2, $this->report($other)['received']);
        self::assertSame(3, $this->report(null)['received']);
        self::assertSame(3, $this->report(null)['waiting']);
        self::assertSame(1, $this->report($this->itroom)['waiting']);
    }

    public function testOrganizationAccountReachesItsStatisticsButNotTheOverview(): void
    {
        $this->loginAsOwner();

        $this->client->request('GET', '/admin/statistiques');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Statistiques');

        $this->client->request('GET', '/admin/statistiques/organisations');
        self::assertResponseStatusCodeSame(403);
    }

    public function testSuperAdminSeesEveryOrganization(): void
    {
        $this->createOrganization('dufermont', 'Dufermont');
        $user = (new User('super@itroom.test'))->setPassword('inutilisé')->setSuperAdmin(true);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/admin/statistiques/organisations');

        self::assertResponseIsSuccessful();
        self::assertAnySelectorTextContains('tbody th', 'Dufermont');
        self::assertAnySelectorTextContains('tbody th', 'IT-Room');
    }

    public function testChartAppearsWithMessages(): void
    {
        $this->message($this->conversation($this->itroom), MessageSender::CLIENT, '2026-10-10 08:00:00');
        $this->loginAsOwner();

        $this->client->request('GET', '/admin/statistiques?du=2026-10-01&au=2026-10-31');

        self::assertSelectorExists('[data-chart] svg');
    }

    public function testLongPeriodIsGroupedByMonthAndUnreadableDatesAreIgnored(): void
    {
        self::assertTrue($this->report($this->itroom, '2025-01-01', '2026-10-31')['monthly']);
        self::assertSame(30, StatisticsPeriod::fromStrings('nimporte', 'quoi')->days());
        self::assertSame('2026-10-01', StatisticsPeriod::fromStrings('2026-10-31', '2026-10-01')->fromParameter());
    }
}
