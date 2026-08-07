<?php

declare(strict_types=1);

namespace App\Tests\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\Regel\Normalspiel\NormalspielTrumpfOrdnung;
use App\Domain\Doppelkopf\Service\AnsageHeuristik;
use App\Domain\Doppelkopf\Service\BlattBewertung;
use App\Domain\Doppelkopf\ValueObject\AnsageAussichten;
use App\Domain\Doppelkopf\ValueObject\AnsageRollout;
use App\Domain\Doppelkopf\ValueObject\AnsageSchwellen;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\AnsageTyp;
use App\Enum\BotStaerke;
use App\Enum\Team;
use PHPUnit\Framework\TestCase;

final class AnsageHeuristikTest extends TestCase
{
    private AnsageHeuristik $heuristik;
    private NormalspielTrumpfOrdnung $ordnung;

    protected function setUp(): void
    {
        $this->heuristik = new AnsageHeuristik();
        $this->ordnung   = new NormalspielTrumpfOrdnung();
    }

    private function karten(array $ids): array
    {
        return array_map(fn(string $id) => Karte::vonId($id), $ids);
    }

    /** 8 Trümpfe inkl. Kreuz-Dame → starke Hand. */
    private function starkeHand(): array
    {
        return $this->karten([
            'KREUZ_DAME_1', 'PIK_DAME_1', 'HERZ_DAME_1', 'KARO_DAME_1',
            'KREUZ_BUBE_1', 'PIK_BUBE_1', 'KARO_ASS_1', 'KARO_ZEHN_1',
            'KREUZ_ASS_1', 'PIK_ASS_1',
        ]);
    }

    /** Genau 7 Trümpfe inkl. Kreuz-Dame – reicht für Re, nicht für Contra. */
    private function mittlereHand(): array
    {
        return $this->karten([
            'KREUZ_DAME_1', 'PIK_DAME_1', 'HERZ_DAME_1', 'KARO_DAME_1',
            'KREUZ_BUBE_1', 'PIK_BUBE_1', 'KARO_ASS_1',
            'KREUZ_ASS_1', 'PIK_ASS_1',
        ]);
    }

    private function schwacheHand(): array
    {
        return $this->karten(['KREUZ_ASS_1', 'PIK_ASS_1', 'HERZ_ASS_1', 'KREUZ_KOENIG_1', 'PIK_KOENIG_1']);
    }

    public function testAnfaengerSagtNieAn(): void
    {
        self::assertNull($this->heuristik->willAnsagen(BotStaerke::ANFAENGER, $this->starkeHand(), Team::RE, $this->ordnung));
    }

    public function testOhneTeamKeineAnsage(): void
    {
        self::assertNull($this->heuristik->willAnsagen(BotStaerke::PROFI, $this->starkeHand(), null, $this->ordnung));
    }

    public function testStarkeHandReSagtRe(): void
    {
        self::assertSame(AnsageTyp::RE, $this->heuristik->willAnsagen(BotStaerke::FORTGESCHRITTEN, $this->starkeHand(), Team::RE, $this->ordnung));
    }

    public function testStarkeHandKontraSagtContra(): void
    {
        self::assertSame(AnsageTyp::CONTRA, $this->heuristik->willAnsagen(BotStaerke::PROFI, $this->starkeHand(), Team::KONTRA, $this->ordnung));
    }

    public function testSchwacheHandKeineAnsage(): void
    {
        self::assertNull($this->heuristik->willAnsagen(BotStaerke::PROFI, $this->schwacheHand(), Team::RE, $this->ordnung));
    }

    /**
     * Kern der Essener Asymmetrie: dasselbe Blatt reicht für Re, aber nicht für Contra.
     * KONTRA steht strukturell schwächer und braucht deshalb mehr.
     */
    public function testContraBrauchtMehrAlsRe(): void
    {
        $hand       = $this->mittlereHand();
        $trumpfzahl = AnsageSchwellen::nachTrumpfzahl();

        self::assertSame(
            AnsageTyp::RE,
            $this->heuristik->willAnsagen(BotStaerke::PROFI, $hand, Team::RE, $this->ordnung, $trumpfzahl),
        );
        self::assertNull(
            $this->heuristik->willAnsagen(BotStaerke::PROFI, $hand, Team::KONTRA, $this->ordnung, $trumpfzahl),
        );
    }

    /** Dieselbe Asymmetrie gilt für die Blattbewertung in Augen (Default). */
    public function testContraBrauchtMehrAlsReAuchNachAugen(): void
    {
        // Schwelle so gelegt, dass das Blatt genau dazwischen liegt.
        $augen     = (new BlattBewertung())->augen($this->mittlereHand(), $this->ordnung);
        $schwellen = (new AnsageSchwellen())->mitAugenSchwellen($augen - 1, $augen + 1);

        self::assertSame(
            AnsageTyp::RE,
            $this->heuristik->willAnsagen(BotStaerke::PROFI, $this->mittlereHand(), Team::RE, $this->ordnung, $schwellen),
        );
        self::assertNull(
            $this->heuristik->willAnsagen(BotStaerke::PROFI, $this->mittlereHand(), Team::KONTRA, $this->ordnung, $schwellen),
        );
    }

    /** Ein schwaches Blatt reicht auch nach Augen nicht. */
    public function testSchwachesBlattReichtAuchNachAugenNicht(): void
    {
        self::assertNull(
            $this->heuristik->willAnsagen(BotStaerke::PROFI, $this->schwacheHand(), Team::RE, $this->ordnung),
        );
    }

    /**
     * Mit Fristfunktion sagt der Bot erst an, wenn seine Hand auf die Frist abgeschmolzen
     * ist (Re/Contra: 11 Karten) – nicht schon mit voller Hand.
     */
    public function testWartetBisZumLetztmoeglichenZeitpunkt(): void
    {
        $frist = static fn(AnsageTyp $typ): int => $typ === AnsageTyp::RE || $typ === AnsageTyp::CONTRA ? 11 : 10;
        $voll  = $this->starkeHand();

        // Frist künstlich über die Handgröße legen → noch zu früh.
        $zuFrueh = static fn(AnsageTyp $typ): int => 5;
        self::assertNull(
            $this->heuristik->willAnsagen(BotStaerke::PROFI, $voll, Team::RE, $this->ordnung, null, [], $zuFrueh),
            'Mit voller Hand ist der letztmögliche Zeitpunkt noch nicht erreicht.',
        );

        // Frist erreicht → jetzt wird angesagt.
        self::assertSame(
            AnsageTyp::RE,
            $this->heuristik->willAnsagen(BotStaerke::PROFI, $voll, Team::RE, $this->ordnung, null, [], $frist),
        );

        // Ohne Fristfunktion bleibt es beim alten Sofort-Ansagen.
        self::assertSame(
            AnsageTyp::RE,
            $this->heuristik->willAnsagen(BotStaerke::PROFI, $voll, Team::RE, $this->ordnung, null, [], null),
        );

        // Abgeschaltet über die Schwellen → ebenfalls sofort.
        self::assertSame(
            AnsageTyp::RE,
            $this->heuristik->willAnsagen(
                BotStaerke::PROFI, $voll, Team::RE, $this->ordnung,
                new AnsageSchwellen(7, 8, [], false), [], $zuFrueh,
            ),
        );
    }

    /**
     * Welche Hand bewertet wird, entscheiden die Schwellen – nicht der Aufrufer. Im Default
     * (Restblatt, weil die gespielte Karte über die erspielten Augen schon abgerechnet ist)
     * bleibt die mitgegebene Starthand unbeachtet.
     */
    public function testDefaultBewertetDasRestblatt(): void
    {
        self::assertNull($this->heuristik->willAnsagen(
            BotStaerke::PROFI, $this->schwacheHand(), Team::RE, $this->ordnung,
            null, [], null, $this->starkeHand(),
        ));
    }

    /**
     * Ohne erspielte Augen fällt der Beitrag der gespielten Karte sonst ganz weg – dann ist
     * die ausgeteilte Hand die bessere Basis (Stand vor P5.8 Teil B, weiter messbar).
     */
    public function testOhneRestblattZaehltDieAusgeteilteHand(): void
    {
        $schwellen = (new AnsageSchwellen())
            ->mitErspieltenAugen(false)
            ->mitBewerteRestblatt(false);

        self::assertSame(
            AnsageTyp::RE,
            $this->heuristik->willAnsagen(
                BotStaerke::PROFI, $this->schwacheHand(), Team::RE, $this->ordnung,
                $schwellen, [], null, $this->starkeHand(),
            ),
        );
    }

    /**
     * Essener Formel: „bis zur Ansage erzielte Augenzahl plus Summe der Erwartungswerte".
     * Ein Blatt knapp unter der Schwelle reicht, sobald die eigene Partei den fehlenden
     * Rest schon im Stich liegen hat.
     */
    public function testErspielteAugenHebenDasBlattUeberDieSchwelle(): void
    {
        $hand      = $this->mittlereHand();
        $augen     = (new BlattBewertung())->augen($hand, $this->ordnung);
        $schwellen = (new AnsageSchwellen())->mitAugenSchwellen($augen + 10, $augen + 10);

        // Blatt allein reicht nicht …
        self::assertNull($this->heuristik->willAnsagen(
            BotStaerke::PROFI, $hand, Team::RE, $this->ordnung, $schwellen, [], null, null, 0,
        ));

        // … mit 15 schon erspielten Augen dagegen schon.
        self::assertSame(AnsageTyp::RE, $this->heuristik->willAnsagen(
            BotStaerke::PROFI, $hand, Team::RE, $this->ordnung, $schwellen, [], null, null, 15,
        ));
    }

    /** Abschaltbar für Vergleichsmessungen – dann zählen nur die Karten. */
    public function testOhneErspielteAugenZaehltNurDasBlatt(): void
    {
        $hand      = $this->mittlereHand();
        $augen     = (new BlattBewertung())->augen($hand, $this->ordnung);
        $schwellen = (new AnsageSchwellen())
            ->mitAugenSchwellen($augen + 10, $augen + 10)
            ->mitErspieltenAugen(false);

        self::assertNull($this->heuristik->willAnsagen(
            BotStaerke::PROFI, $hand, Team::RE, $this->ordnung, $schwellen, [], null, null, 15,
        ));
    }

    /** Die gröbere Trumpfzahl-Entscheidung kennt keine Augen – erspielte ändern dort nichts. */
    public function testTrumpfzahlIgnoriertErspielteAugen(): void
    {
        $trumpfzahl = AnsageSchwellen::nachTrumpfzahl(8, 8);

        self::assertNull($this->heuristik->willAnsagen(
            BotStaerke::PROFI, $this->mittlereHand(), Team::RE, $this->ordnung, $trumpfzahl, [], null, null, 60,
        ));
    }

    public function testOhneKonfigurierteSchwelleKeineAbsage(): void
    {
        // Selbst mit Traumblatt und stehendem "Re": Absagen sind per Default abgeschaltet.
        self::assertNull($this->heuristik->willAnsagen(
            BotStaerke::PROFI,
            $this->starkeHand(),
            Team::RE,
            $this->ordnung,
            null,
            [AnsageTyp::RE],
        ));
    }

    public function testAbsagenFolgenDerKette(): void
    {
        $schwellen = new AnsageSchwellen(7, 8, [
            AnsageTyp::KEINE_NEUN->value  => 8,
            AnsageTyp::KEINE_SECHS->value => 8,
        ]);
        $hand = $this->starkeHand(); // 8 Trümpfe

        // Ohne "Re" kommt zuerst die Grundansage.
        self::assertSame(
            AnsageTyp::RE,
            $this->heuristik->willAnsagen(BotStaerke::PROFI, $hand, Team::RE, $this->ordnung, $schwellen, []),
        );

        // Mit "Re" die erste offene Absagestufe …
        self::assertSame(
            AnsageTyp::KEINE_NEUN,
            $this->heuristik->willAnsagen(BotStaerke::PROFI, $hand, Team::RE, $this->ordnung, $schwellen, [AnsageTyp::RE]),
        );

        // … dann die nächste …
        self::assertSame(
            AnsageTyp::KEINE_SECHS,
            $this->heuristik->willAnsagen(
                BotStaerke::PROFI, $hand, Team::RE, $this->ordnung, $schwellen,
                [AnsageTyp::RE, AnsageTyp::KEINE_NEUN],
            ),
        );

        // … und Schluss, weil "keine 30" keine Schwelle hat.
        self::assertNull(
            $this->heuristik->willAnsagen(
                BotStaerke::PROFI, $hand, Team::RE, $this->ordnung, $schwellen,
                [AnsageTyp::RE, AnsageTyp::KEINE_NEUN, AnsageTyp::KEINE_SECHS],
            ),
        );
    }

    public function testGleicheSchwellenStellenDasAlteVerhaltenHer(): void
    {
        $hand = $this->mittlereHand();

        self::assertSame(
            AnsageTyp::CONTRA,
            $this->heuristik->willAnsagen(BotStaerke::PROFI, $hand, Team::KONTRA, $this->ordnung, AnsageSchwellen::gleich(7)),
        );
    }

    // ── Ansage per Rollout ──────────────────────────────────────────────────
    //
    // Die Schätzung wird hier gestellt, nicht gerechnet: Genau dafür ist sie von der
    // Entscheidung getrennt. Die Politik ist damit ohne eine einzige Monte-Carlo-Welt
    // prüfbar – und die Tests laufen in Millisekunden statt Sekunden.

    /** @param array<string, float> $evMit @param array<string, float> $haelt */
    private function aussichten(float $evOhne, array $evMit, array $haelt = []): callable
    {
        return static fn(array $kandidaten): AnsageAussichten
            => new AnsageAussichten(60, $evOhne, $evMit, $haelt);
    }

    private function rolloutSchwellen(bool $evVergleich = true, bool $absagen = false): AnsageSchwellen
    {
        return (new AnsageSchwellen())->mitRollout(new AnsageRollout($evVergleich, $absagen));
    }

    public function testRolloutSagtAnWennDerErwartungswertSteigt(): void
    {
        self::assertSame(
            AnsageTyp::RE,
            $this->heuristik->willAnsagen(
                BotStaerke::PROFI, $this->starkeHand(), Team::RE, $this->ordnung,
                $this->rolloutSchwellen(),
                aussichtenFuer: $this->aussichten(1.0, ['RE' => 2.0]),
            ),
        );
    }

    public function testRolloutSchweigtWennDieAnsageDenErwartungswertSenkt(): void
    {
        self::assertNull(
            $this->heuristik->willAnsagen(
                BotStaerke::PROFI, $this->starkeHand(), Team::RE, $this->ordnung,
                $this->rolloutSchwellen(),
                aussichtenFuer: $this->aussichten(1.0, ['RE' => -2.0]),
            ),
        );
    }

    /**
     * Der fachliche Kern des Hebels: Das Damen-Gate („Kreuz-Dame oder ≥ 2 Damen") gilt im
     * Rollout-Pfad nicht mehr. Eine Hand ohne jede Dame konnte bisher nie ansagen – auch
     * dann nicht, wenn die Partei längst uneinholbar vorn lag.
     */
    public function testRolloutBrauchtKeineDamenImBlatt(): void
    {
        self::assertSame(
            AnsageTyp::RE,
            $this->heuristik->willAnsagen(
                BotStaerke::PROFI, $this->schwacheHand(), Team::RE, $this->ordnung,
                $this->rolloutSchwellen(),
                aussichtenFuer: $this->aussichten(0.5, ['RE' => 1.5]),
            ),
        );

        // Zum Vergleich: über die Blattbewertung bleibt dieselbe Hand stumm.
        self::assertNull(
            $this->heuristik->willAnsagen(
                BotStaerke::PROFI, $this->schwacheHand(), Team::RE, $this->ordnung,
            ),
        );
    }

    public function testEssenerSchwellenEntscheidenNachWahrscheinlichkeit(): void
    {
        $schwellen = $this->rolloutSchwellen(evVergleich: false);

        // 52 % Siegchance – über den 50 % für Re, obwohl der Erwartungswert hier fiele.
        self::assertSame(
            AnsageTyp::RE,
            $this->heuristik->willAnsagen(
                BotStaerke::PROFI, $this->starkeHand(), Team::RE, $this->ordnung, $schwellen,
                aussichtenFuer: $this->aussichten(1.0, ['RE' => -5.0], ['RE' => 0.52]),
            ),
        );

        self::assertNull(
            $this->heuristik->willAnsagen(
                BotStaerke::PROFI, $this->starkeHand(), Team::RE, $this->ordnung, $schwellen,
                aussichtenFuer: $this->aussichten(1.0, ['RE' => 5.0], ['RE' => 0.48]),
            ),
        );
    }

    /**
     * Der Absage-Schalter wirkt getrennt: Re/Contra bleiben beim Erwartungswert, nur die
     * Absage entscheidet nach Wahrscheinlichkeit. Sonst ließe sich nicht messen, welche der
     * beiden Regeln einen Unterschied macht.
     */
    public function testAbsagenKoennenEigenerRegelFolgen(): void
    {
        $schwellen = (new AnsageSchwellen())->mitRollout(
            new AnsageRollout(true, true, AnsageRollout::SICHERHEIT_ESSEN, 60, absagenNachSchwellen: true),
        );

        // Erwartungswert spräche dafür, die Sicherheit (80 %) reicht für „keine 90" nicht.
        self::assertNull(
            $this->heuristik->willAnsagen(
                BotStaerke::PROFI, $this->starkeHand(), Team::RE, $this->ordnung, $schwellen,
                [AnsageTyp::RE],
                aussichtenFuer: $this->aussichten(1.0, ['KEINE_NEUN' => 9.0], ['KEINE_NEUN' => 0.80]),
            ),
        );

        // 95 % liegen über den geforderten 88,89 %.
        self::assertSame(
            AnsageTyp::KEINE_NEUN,
            $this->heuristik->willAnsagen(
                BotStaerke::PROFI, $this->starkeHand(), Team::RE, $this->ordnung, $schwellen,
                [AnsageTyp::RE],
                aussichtenFuer: $this->aussichten(1.0, ['KEINE_NEUN' => -9.0], ['KEINE_NEUN' => 0.95]),
            ),
        );

        // Die Grundansage folgt weiter dem Erwartungswert, nicht der Sicherheit.
        self::assertSame(
            AnsageTyp::RE,
            $this->heuristik->willAnsagen(
                BotStaerke::PROFI, $this->starkeHand(), Team::RE, $this->ordnung, $schwellen,
                aussichtenFuer: $this->aussichten(1.0, ['RE' => 2.0], ['RE' => 0.10]),
            ),
        );
    }

    /**
     * `nurAbsagen`: Die Erstansage bleibt bei der Blattbewertung, die Rollouts greifen erst
     * ab der Absage-Kette. Der Schätzer darf für die Grundansage gar nicht erst befragt
     * werden – sonst kostete er Rechenzeit für eine Entscheidung, die er nicht trifft.
     */
    public function testNurAbsagenLaesstDieErstansageBeiDerBlattbewertung(): void
    {
        $schwellen = (new AnsageSchwellen())->mitRollout(
            new AnsageRollout(true, true, AnsageRollout::SICHERHEIT_ESSEN, 60, nurAbsagen: true),
        );

        $befragt = false;
        $geber = static function (array $k) use (&$befragt): AnsageAussichten {
            $befragt = true;

            return new AnsageAussichten(60, 0.0, ['KEINE_NEUN' => 5.0]);
        };

        // Ohne Grundansage: Blattbewertung entscheidet, Rollout bleibt unangetastet.
        self::assertSame(
            AnsageTyp::RE,
            $this->heuristik->willAnsagen(
                BotStaerke::PROFI, $this->starkeHand(), Team::RE, $this->ordnung, $schwellen,
                aussichtenFuer: $geber,
            ),
        );
        self::assertFalse($befragt, 'Für die Erstansage dürfen keine Rollouts laufen.');

        // Eine Hand, die der Blattbewertung nicht reicht, sagt auch nicht an …
        self::assertNull(
            $this->heuristik->willAnsagen(
                BotStaerke::PROFI, $this->schwacheHand(), Team::RE, $this->ordnung, $schwellen,
                aussichtenFuer: $geber,
            ),
        );

        // … steht die Grundansage aber, übernimmt der Rollout die Absage.
        self::assertSame(
            AnsageTyp::KEINE_NEUN,
            $this->heuristik->willAnsagen(
                BotStaerke::PROFI, $this->schwacheHand(), Team::RE, $this->ordnung, $schwellen,
                [AnsageTyp::RE],
                aussichtenFuer: $geber,
            ),
        );
        self::assertTrue($befragt);
    }

    public function testAbsagenBleibenOhneEigenenSchalterAus(): void
    {
        // Re steht, der Erwartungswert trüge auch „keine 90" – der Schalter ist aber aus.
        self::assertNull(
            $this->heuristik->willAnsagen(
                BotStaerke::PROFI, $this->starkeHand(), Team::RE, $this->ordnung,
                $this->rolloutSchwellen(absagen: false),
                [AnsageTyp::RE],
                aussichtenFuer: $this->aussichten(1.0, ['KEINE_NEUN' => 3.0]),
            ),
        );

        self::assertSame(
            AnsageTyp::KEINE_NEUN,
            $this->heuristik->willAnsagen(
                BotStaerke::PROFI, $this->starkeHand(), Team::RE, $this->ordnung,
                $this->rolloutSchwellen(absagen: true),
                [AnsageTyp::RE],
                aussichtenFuer: $this->aussichten(1.0, ['KEINE_NEUN' => 3.0]),
            ),
        );
    }

    /** Immer nur die nächste offene Stufe – das hält die Rollout-Kosten bei einem Durchgang. */
    public function testRolloutBietetGenauEinenKandidatenAn(): void
    {
        $gesehen = [];
        $geber = static function (array $kandidaten) use (&$gesehen): AnsageAussichten {
            $gesehen = $kandidaten;

            return new AnsageAussichten(60, 0.0, ['KEINE_SECHS' => 1.0]);
        };

        $this->heuristik->willAnsagen(
            BotStaerke::PROFI, $this->starkeHand(), Team::RE, $this->ordnung,
            $this->rolloutSchwellen(absagen: true),
            [AnsageTyp::RE, AnsageTyp::KEINE_NEUN],
            aussichtenFuer: $geber,
        );

        self::assertSame([AnsageTyp::KEINE_SECHS], $gesehen);
    }

    public function testRolloutHaeltDieFristEin(): void
    {
        $hand = $this->starkeHand(); // 10 Karten

        self::assertNull(
            $this->heuristik->willAnsagen(
                BotStaerke::PROFI, $hand, Team::RE, $this->ordnung,
                $this->rolloutSchwellen(),
                [],
                static fn(AnsageTyp $typ): int => 9, // erst ab 9 Handkarten
                aussichtenFuer: $this->aussichten(0.0, ['RE' => 9.0]),
            ),
        );
    }

    /** Ohne auswertbare Welt wird nicht geraten. */
    public function testOhneWeltenKeineAnsage(): void
    {
        self::assertNull(
            $this->heuristik->willAnsagen(
                BotStaerke::PROFI, $this->starkeHand(), Team::RE, $this->ordnung,
                $this->rolloutSchwellen(),
                aussichtenFuer: static fn(array $k): AnsageAussichten => new AnsageAussichten(0),
            ),
        );
    }

    /** Ohne Schätzer (alle Stufen außer PROFI) bleibt es bei der Blattbewertung. */
    public function testOhneSchaetzerEntscheidetWeiterDasBlatt(): void
    {
        self::assertSame(
            AnsageTyp::RE,
            $this->heuristik->willAnsagen(
                BotStaerke::PROFI, $this->starkeHand(), Team::RE, $this->ordnung,
                $this->rolloutSchwellen(),
            ),
        );
    }
}
