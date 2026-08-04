<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\ValueObject;

/**
 * Schalttafel für die einzelnen Gedächtnis-Regeln der
 * {@see \App\Domain\Doppelkopf\Service\FortgeschritteneHeuristik}.
 *
 * Jede Regel ist genau eine Abweichung von der {@see \App\Domain\Doppelkopf\Service\BotHeuristik}
 * (Anfänger). Sind alle Regeln aus, spielt die fortgeschrittene Engine Karte für Karte
 * dasselbe wie die Anfänger-Engine — das prüft `FortgeschritteneRegelnTest`.
 *
 * Zweck: Der Self-Play-Benchmark hat gezeigt, dass FORTGESCHRITTEN die Karten **schlechter**
 * spielt als ANFAENGER (−0,24 Punkte je Partie). Welche der Regeln das verursacht, lässt
 * sich nur messen, wenn man sie einzeln abschalten kann (`app:bot:turnier --regeln-a=…`).
 */
final readonly class FortgeschritteneRegeln
{
    /** Regelname => Kurzbeschreibung (Reihenfolge = Reihenfolge in der Engine). */
    public const BESCHREIBUNGEN = [
        'sichere-stiche'      => 'Anspielen: sichere Fehlfarbe mit Augen kassieren',
        'ass-nur-sicher'      => 'Anspielen: Fehlfarben-Ass nur, wenn kein Gegner in der Farbe frei ist',
        'trumpf-ziehen'       => 'Anspielen: höchsten Trumpf ziehen, solange Gegner Trumpf haben',
        'sicheres-abwerfen'   => 'Anspielen: beim Abwerfen Farben meiden, in denen ein Gegner frei ist',
        'schmier-sicherheit'  => 'Partner führt: auch vor dem letzten Platz schmieren, wenn der Stich unschlagbar ist',
        'stich-lohnt-sich'    => 'Gegner führt, letzter Platz: nur stechen, wenn der Stich Augen bringt',
        'nur-unschlagbar'     => 'Gegner führt, nicht letzter Platz: nur mit unüberbietbarer Karte stechen',
    ];

    public function __construct(
        public bool $sichereStiche = true,
        public bool $assNurSicher = true,
        public bool $trumpfZiehen = true,
        public bool $sicheresAbwerfen = true,
        public bool $schmierSicherheit = true,
        public bool $stichLohntSich = true,
        public bool $nurUnschlagbar = true,
    ) {}

    /** Alle Regeln an (Produktivverhalten der Stufe FORTGESCHRITTEN). */
    public static function alle(): self
    {
        return new self();
    }

    /** Keine Regel an — verhält sich exakt wie die Anfänger-Heuristik. */
    public static function keine(): self
    {
        return new self(false, false, false, false, false, false, false);
    }

    /** Nur die genannten Regeln an. */
    public static function nur(string ...$namen): self
    {
        $an = self::keine()->alsFlags();
        foreach ($namen as $name) {
            $an[self::pruefe($name)] = true;
        }

        return self::ausFlags($an);
    }

    /** Alle Regeln außer den genannten. */
    public static function ohne(string ...$namen): self
    {
        $an = self::alle()->alsFlags();
        foreach ($namen as $name) {
            $an[self::pruefe($name)] = false;
        }

        return self::ausFlags($an);
    }

    /**
     * Kommandozeilen-Schreibweise:
     *   `alle` · `keine` · `sichere-stiche,trumpf-ziehen` (nur diese)
     *   · `-trumpf-ziehen,-nur-unschlagbar` (alle außer diesen).
     * Mischen von An- und Abwahl ist nicht erlaubt.
     */
    public static function ausListe(string $liste): self
    {
        $liste = trim($liste);
        if ($liste === '' || $liste === 'alle') {
            return self::alle();
        }
        if ($liste === 'keine') {
            return self::keine();
        }

        $teile = array_values(array_filter(array_map('trim', explode(',', $liste)), fn(string $t) => $t !== ''));
        $abwahl = array_map(fn(string $t) => str_starts_with($t, '-'), $teile);

        if (in_array(true, $abwahl, true) && in_array(false, $abwahl, true)) {
            throw new \InvalidArgumentException(
                'Regel-Liste: entweder nur Auswahl ("a,b") oder nur Abwahl ("-a,-b"), nicht gemischt.',
            );
        }

        return $abwahl[0]
            ? self::ohne(...array_map(fn(string $t) => substr($t, 1), $teile))
            : self::nur(...$teile);
    }

    /** Kurzform für die Benchmark-Ausgabe, z. B. „ohne trumpf-ziehen“. */
    public function beschreibung(): string
    {
        $aus = array_keys(array_filter($this->alsFlags(), fn(bool $an) => !$an));
        if ($aus === []) {
            return 'alle Regeln';
        }

        $an = array_keys(array_filter($this->alsFlags()));
        if ($an === []) {
            return 'keine Regeln';
        }

        return count($aus) <= count($an)
            ? 'ohne ' . implode(', ', $aus)
            : 'nur ' . implode(', ', $an);
    }

    /** @return array<string, bool> Regelname => an? */
    public function alsFlags(): array
    {
        return [
            'sichere-stiche'     => $this->sichereStiche,
            'ass-nur-sicher'     => $this->assNurSicher,
            'trumpf-ziehen'      => $this->trumpfZiehen,
            'sicheres-abwerfen'  => $this->sicheresAbwerfen,
            'schmier-sicherheit' => $this->schmierSicherheit,
            'stich-lohnt-sich'   => $this->stichLohntSich,
            'nur-unschlagbar'    => $this->nurUnschlagbar,
        ];
    }

    /** @param array<string, bool> $flags */
    private static function ausFlags(array $flags): self
    {
        return new self(
            $flags['sichere-stiche'],
            $flags['ass-nur-sicher'],
            $flags['trumpf-ziehen'],
            $flags['sicheres-abwerfen'],
            $flags['schmier-sicherheit'],
            $flags['stich-lohnt-sich'],
            $flags['nur-unschlagbar'],
        );
    }

    private static function pruefe(string $name): string
    {
        if (!array_key_exists($name, self::BESCHREIBUNGEN)) {
            throw new \InvalidArgumentException(sprintf(
                'Unbekannte Regel "%s". Bekannt: %s',
                $name,
                implode(', ', array_keys(self::BESCHREIBUNGEN)),
            ));
        }

        return $name;
    }
}
