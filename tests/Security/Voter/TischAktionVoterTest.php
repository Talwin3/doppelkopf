<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Tisch;
use App\Entity\TischSpieler;
use App\Entity\User;
use App\Enum\TischSteuerungsModus;
use App\Security\Voter\TischAktionVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Reine Logik-Tests für den TischAktionVoter. Kein Kernel/DB nötig.
 */
final class TischAktionVoterTest extends TestCase
{
    private TischAktionVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new TischAktionVoter();
    }

    public function testErstellerDarfImModusNurErsteller(): void
    {
        $ersteller = new User();
        $tisch = $this->tischMit($ersteller, TischSteuerungsModus::NUR_ERSTELLER, [$ersteller]);

        self::assertTrue($this->darfVerwalten($tisch, $ersteller));
    }

    public function testAktiverNichtErstellerDarfNichtImModusNurErsteller(): void
    {
        $ersteller = new User();
        $anderer   = new User();
        $tisch = $this->tischMit($ersteller, TischSteuerungsModus::NUR_ERSTELLER, [$ersteller, $anderer]);

        self::assertFalse($this->darfVerwalten($tisch, $anderer));
    }

    public function testAktiverNichtErstellerDarfImModusAlle(): void
    {
        $ersteller = new User();
        $anderer   = new User();
        $tisch = $this->tischMit($ersteller, TischSteuerungsModus::ALLE, [$ersteller, $anderer]);

        self::assertTrue($this->darfVerwalten($tisch, $anderer));
    }

    public function testNichtAmTischDarfNichtTrotzModusAlle(): void
    {
        $ersteller = new User();
        $fremder   = new User();
        $tisch = $this->tischMit($ersteller, TischSteuerungsModus::ALLE, [$ersteller]);

        self::assertFalse($this->darfVerwalten($tisch, $fremder));
    }

    public function testErstellerOhneSitzplatzDarfNicht(): void
    {
        // Ersteller in der Warteschlange (kein Sitzplatz) → nicht aktiv → kein Recht.
        $ersteller = new User();
        $tisch = $this->tischMit($ersteller, TischSteuerungsModus::NUR_ERSTELLER, []);
        $this->setzeSpieler($tisch, $ersteller, sitzplatz: null);

        self::assertFalse($this->darfVerwalten($tisch, $ersteller));
    }

    // ── Helfer ──────────────────────────────────────────────────────────────

    /** @param list<User> $aktiveSpieler */
    private function tischMit(User $ersteller, TischSteuerungsModus $modus, array $aktiveSpieler): Tisch
    {
        $tisch = new Tisch();
        $tisch->setErsteller($ersteller);
        $tisch->setSteuerungsModus($modus);

        $platz = 1;
        foreach ($aktiveSpieler as $u) {
            $this->setzeSpieler($tisch, $u, $platz++);
        }

        return $tisch;
    }

    private function setzeSpieler(Tisch $tisch, User $user, ?int $sitzplatz): void
    {
        $ts = new TischSpieler();
        $ts->setTisch($tisch);
        $ts->setUser($user);
        $ts->setSitzplatz($sitzplatz);
        $tisch->getSpieler()->add($ts);
    }

    private function darfVerwalten(Tisch $tisch, User $user): bool
    {
        $token = new UsernamePasswordToken($user, 'main');

        $ergebnis = $this->voter->vote($token, $tisch, [TischAktionVoter::VERWALTEN]);

        return $ergebnis === TischAktionVoter::ACCESS_GRANTED;
    }
}
