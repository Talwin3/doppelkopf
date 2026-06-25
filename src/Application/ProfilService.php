<?php

declare(strict_types=1);

namespace App\Application;

use App\Entity\SpielTeilnehmer;
use App\Entity\Tisch;
use App\Entity\TischSpieler;
use App\Entity\User;
use App\Repository\TischSpielerRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ProfilService
{
    private const BENUTZERNAME_COOLDOWN_TAGE = 7;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly UserRepository $userRepo,
        private readonly TischSpielerRepository $tischSpielerRepo,
    ) {}

    /**
     * Ändert den Benutzernamen (max. einmal alle 7 Tage).
     * @throws \DomainException wenn Cooldown aktiv oder Name vergeben
     */
    public function benutzernameAendern(User $user, string $neuerName): void
    {
        $geaendertAm = $user->getNutzernameGeaendertAm();
        if ($geaendertAm !== null) {
            $cooldownBis = $geaendertAm->modify('+' . self::BENUTZERNAME_COOLDOWN_TAGE . ' days');
            if (new \DateTimeImmutable() < $cooldownBis) {
                throw new \DomainException(
                    'Der Benutzername kann erst wieder am ' . $cooldownBis->format('d.m.Y') . ' geändert werden.'
                );
            }
        }

        if ($this->userRepo->findOneBy(['username' => $neuerName]) !== null) {
            throw new \DomainException('Dieser Benutzername ist bereits vergeben.');
        }

        $user->setUsername($neuerName);
        $user->setNutzernameGeaendertAm(new \DateTimeImmutable());
        $this->em->flush();
    }

    /**
     * Ändert das Passwort nach Verifikation des alten Passworts.
     * @throws \DomainException wenn altes Passwort falsch
     */
    public function passwortAendern(User $user, string $aktuellesPasswort, string $neuesPasswort): void
    {
        if (!$this->hasher->isPasswordValid($user, $aktuellesPasswort)) {
            throw new \DomainException('Das aktuelle Passwort ist falsch.');
        }

        $user->setPassword($this->hasher->hashPassword($user, $neuesPasswort));
        $this->em->flush();
    }

    /**
     * Ändert die E-Mail-Adresse nach Passwort-Bestätigung.
     * @throws \DomainException wenn Passwort falsch oder E-Mail vergeben
     */
    public function emailAendern(User $user, string $neueEmail, string $passwort): void
    {
        if (!$this->hasher->isPasswordValid($user, $passwort)) {
            throw new \DomainException('Das Passwort ist falsch.');
        }

        $existing = $this->userRepo->findOneBy(['email' => $neueEmail]);
        if ($existing !== null && $existing->getId() != $user->getId()) {
            throw new \DomainException('Diese E-Mail-Adresse ist bereits vergeben.');
        }

        $user->setEmail($neueEmail);
        $this->em->flush();
    }

    public function datenschutzAendern(User $user, bool $oeffentlich): void
    {
        $user->setProfilOeffentlich($oeffentlich);
        $this->em->flush();
    }

    public function kartendeckAendern(User $user, string $wert): void
    {
        $deck = \App\Enum\Kartendeck::tryFrom($wert);
        if ($deck === null) {
            return; // unbekannten Wert ignorieren
        }

        $user->setKartenbildPraeferenz($deck->value);
        $this->em->flush();
    }

    /**
     * Speichert die Avatar-Auswahl: Stil und der zuvor (clientseitig) gewürfelte
     * Seed. Erst dieser Aufruf persistiert – das Würfeln selbst erzeugt nur
     * Vorschläge im Browser. Ungültige Werte werden ignoriert (alter Wert bleibt).
     */
    public function avatarAendern(User $user, string $stilWert, string $seed): void
    {
        $stil = \App\Enum\AvatarStil::tryFrom($stilWert);
        if ($stil === null) {
            return; // unbekannten Stil ignorieren
        }

        $user->setAvatarStil($stil->value);

        // Seed nur übernehmen, wenn er dem erwarteten Format entspricht.
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $seed) === 1) {
            $user->setAvatarSeed($seed);
        }

        $this->em->flush();
    }

    /**
     * Erstellt ein DSGVO-Datenpaket (Art. 20) als Array.
     * @return array<string, mixed>
     */
    public function datenExportieren(User $user): array
    {
        return [
            'exportiert_am'    => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'benutzername'     => $user->getUsername(),
            'email'            => $user->getEmail(),
            'profil_oeffentlich' => $user->isProfilOeffentlich(),
            'mitglied_seit'    => $user->getErstelltAm()->format(\DateTimeInterface::ATOM),
            'letzter_login'    => $user->getLetzterLoginAm()?->format(\DateTimeInterface::ATOM),
            'benutzername_zuletzt_geaendert' => $user->getNutzernameGeaendertAm()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Löscht das Konto gemäß DSGVO Art. 17: Personenbezogene Daten werden
     * anonymisiert, Spiel-Historie bleibt ohne Personenbezug erhalten.
     */
    public function kontoLoeschen(User $user): void
    {
        $shortId = substr(str_replace('-', '', (string) $user->getId()), 0, 10);

        // Alle aktiven Tisch-Sitzplätze freigeben. Betroffene Tische merken, um sie
        // danach ggf. als menschenlos zu markieren (sonst räumt der Worker sie nie auf).
        // Laufende Spiele werden vom Worker per Disconnect-Timeout zu Ende gespielt.
        $betroffeneTische = [];
        foreach ($this->tischSpielerRepo->findBy(['user' => $user]) as $ts) {
            $tisch = $ts->getTisch();
            $betroffeneTische[(string) $tisch->getId()] = $tisch;
            $this->em->remove($ts);
            // Auch aus der In-Memory-Collection lösen, damit hatMenschAmTisch() unten stimmt.
            $tisch->getSpieler()->removeElement($ts);
        }
        $this->em->flush();

        foreach ($betroffeneTische as $tisch) {
            if (!$tisch->hatMenschAmTisch() && $tisch->getMenschenloseSeitAm() === null) {
                $tisch->setMenschenloseSeitAm(new \DateTimeImmutable());
            }
        }

        // Spiel-Teilnahmen anonymisieren (User-Bezug kappen)
        $qb = $this->em->createQueryBuilder();
        $qb->update(SpielTeilnehmer::class, 'st')
            ->set('st.user', ':null')
            ->where('st.user = :user')
            ->setParameter('null', null)
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();

        // Personenbezogene Daten überschreiben
        $user->setUsername('gelöscht_' . $shortId);
        $user->setEmail('gelöscht_' . $shortId . '@beispiel.invalid');
        $user->setPassword('');
        $user->setRoles([]);
        $user->setIsVerified(false);
        $user->setNutzernameGeaendertAm(null);
        $user->setProfilOeffentlich(false);

        $this->em->flush();
    }
}
