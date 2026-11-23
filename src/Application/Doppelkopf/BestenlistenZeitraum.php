<?php

declare(strict_types=1);

namespace App\Application\Doppelkopf;

/**
 * Ein Kalendermonat oder Kalenderjahr nach deutscher Zeit, für den eine Bestenliste
 * geführt wird. Maßgeblich ist das Spielende.
 */
final class BestenlistenZeitraum
{
    public const ZEITZONE = 'Europe/Berlin';

    public const MONAT = 'monat';
    public const JAHR  = 'jahr';

    private const MONATE = [
        1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
        'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember',
    ];

    private function __construct(
        public readonly string $art,
        public readonly int $jahr,
        /** 1–12; beim Jahr immer 1. */
        public readonly int $monat,
    ) {}

    public static function monat(int $jahr, int $monat): self
    {
        if ($monat < 1 || $monat > 12) {
            throw new \InvalidArgumentException('Monat muss zwischen 1 und 12 liegen.');
        }

        return new self(self::MONAT, $jahr, $monat);
    }

    public static function jahr(int $jahr): self
    {
        return new self(self::JAHR, $jahr, 1);
    }

    /** Der laufende Monat bzw. das laufende Jahr nach deutscher Zeit. */
    public static function aktuell(string $art, \DateTimeImmutable $jetzt): self
    {
        $lokal = $jetzt->setTimezone(new \DateTimeZone(self::ZEITZONE));
        $jahr  = (int) $lokal->format('Y');

        return $art === self::JAHR ? self::jahr($jahr) : self::monat($jahr, (int) $lokal->format('n'));
    }

    /**
     * Zeitraum aus Anfrage-Parametern. Unbrauchbare oder zukünftige Angaben fallen auf
     * den laufenden Zeitraum zurück.
     */
    public static function ausAnfrage(string $art, ?int $jahr, ?int $monat, \DateTimeImmutable $jetzt): self
    {
        $art     = $art === self::JAHR ? self::JAHR : self::MONAT;
        $aktuell = self::aktuell($art, $jetzt);

        if ($jahr === null || $jahr < 2000) {
            return $aktuell;
        }
        if ($art === self::MONAT && ($monat === null || $monat < 1 || $monat > 12)) {
            return $aktuell;
        }

        $gewuenscht = $art === self::JAHR ? self::jahr($jahr) : self::monat($jahr, (int) $monat);

        return $gewuenscht->istNach($aktuell) ? $aktuell : $gewuenscht;
    }

    /**
     * Anfang (inklusive) und Ende (exklusive) des Zeitraums, umgerechnet in UTC wie in
     * der Datenbank gespeichert.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public function grenzen(): array
    {
        $von = (new \DateTimeImmutable('now', new \DateTimeZone(self::ZEITZONE)))
            ->setDate($this->jahr, $this->monat, 1)
            ->setTime(0, 0);
        $bis = $this->art === self::JAHR ? $von->modify('+1 year') : $von->modify('first day of next month');

        $utc = new \DateTimeZone('UTC');

        return [$von->setTimezone($utc), $bis->setTimezone($utc)];
    }

    public function vorheriger(): self
    {
        if ($this->art === self::JAHR) {
            return self::jahr($this->jahr - 1);
        }

        return $this->monat === 1 ? self::monat($this->jahr - 1, 12) : self::monat($this->jahr, $this->monat - 1);
    }

    public function naechster(): self
    {
        if ($this->art === self::JAHR) {
            return self::jahr($this->jahr + 1);
        }

        return $this->monat === 12 ? self::monat($this->jahr + 1, 1) : self::monat($this->jahr, $this->monat + 1);
    }

    /** Liegt dieser Zeitraum nach dem anderen (gleiche Art vorausgesetzt)? */
    public function istNach(self $anderer): bool
    {
        return [$this->jahr, $this->monat] > [$anderer->jahr, $anderer->monat];
    }

    /** Monatsname, z. B. „November“. */
    public function monatsname(): string
    {
        return self::MONATE[$this->monat];
    }

    /** „November 2026“ bzw. „2026“. */
    public function label(): string
    {
        return $this->art === self::JAHR ? (string) $this->jahr : $this->monatsname() . ' ' . $this->jahr;
    }

    /** @return array{zeitraum: string, jahr: int, monat?: int} Parameter für Links. */
    public function parameter(): array
    {
        return $this->art === self::JAHR
            ? ['zeitraum' => self::JAHR, 'jahr' => $this->jahr]
            : ['zeitraum' => self::MONAT, 'jahr' => $this->jahr, 'monat' => $this->monat];
    }
}
