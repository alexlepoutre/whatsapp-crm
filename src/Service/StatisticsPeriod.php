<?php

namespace App\Service;

/** Une plage de jours entiers, à l'heure de Paris comme tout l'affichage. */
final class StatisticsPeriod
{
    public const TIMEZONE = 'Europe/Paris';
    private const DEFAULT_DAYS = 30;

    private function __construct(
        public readonly \DateTimeImmutable $from,
        public readonly \DateTimeImmutable $to,
    ) {
    }

    /** Une date absente ou illisible retombe sur les 30 derniers jours. */
    public static function fromStrings(?string $from, ?string $to): self
    {
        $today = self::today();
        $end = self::parse($to) ?? $today;
        $start = self::parse($from) ?? $end->modify('-'.(self::DEFAULT_DAYS - 1).' days');

        return $start <= $end ? new self($start, $end) : new self($end, $start);
    }

    /** @return array<string, array{from: string, to: string}> */
    public static function presets(): array
    {
        $today = self::today();
        $month = $today->modify('first day of this month');
        $previous = $month->modify('-1 month');

        return [
            '7 derniers jours' => self::preset($today->modify('-6 days'), $today),
            '30 derniers jours' => self::preset($today->modify('-29 days'), $today),
            'Ce mois-ci' => self::preset($month, $today),
            'Mois dernier' => self::preset($previous, $month->modify('-1 day')),
            'Cette année' => self::preset($today->setDate((int) $today->format('Y'), 1, 1), $today),
        ];
    }

    public function fromParameter(): string
    {
        return $this->from->format('Y-m-d');
    }

    public function toParameter(): string
    {
        return $this->to->format('Y-m-d');
    }

    public function days(): int
    {
        return (int) $this->from->diff($this->to)->days + 1;
    }

    /** Fin exclue : le lendemain du dernier jour, à minuit. */
    public function end(): \DateTimeImmutable
    {
        return $this->to->modify('+1 day');
    }

    private static function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today', new \DateTimeZone(self::TIMEZONE));
    }

    private static function parse(?string $date): ?\DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $date, new \DateTimeZone(self::TIMEZONE));

        return $parsed && $parsed->format('Y-m-d') === $date ? $parsed : null;
    }

    /** @return array{from: string, to: string} */
    private static function preset(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
    }
}
