<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Kartendeck;
use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['email'], message: 'Diese E-Mail-Adresse ist bereits vergeben.')]
#[UniqueEntity(fields: ['username'], message: 'Dieser Benutzername ist bereits vergeben.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /**
     * Öffentlicher Anzeigename – niemals die E-Mail verwenden.
     */
    #[ORM\Column(length: 30, unique: true)]
    #[Assert\NotBlank(message: 'Bitte wähle einen Benutzernamen.')]
    #[Assert\Length(min: 3, max: 30, minMessage: 'Mindestens 3 Zeichen.', maxMessage: 'Maximal 30 Zeichen.')]
    #[Assert\Regex(pattern: '/^[a-zA-Z0-9_\-]+$/', message: 'Nur Buchstaben, Zahlen, - und _ erlaubt.')]
    private string $username;

    /**
     * E-Mail ist privat und wird niemals in der UI angezeigt.
     */
    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email(message: 'Bitte eine gültige E-Mail-Adresse eingeben.')]
    private string $email;

    #[ORM\Column]
    private string $password = '';

    /**
     * Rollen als JSON-Array – erweiterbar für spätere Feingranularität.
     * Basisrolle: ROLE_USER (wird automatisch über getRoles() hinzugefügt).
     * Admin: ["ROLE_ADMIN"]
     */
    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column(options: ['default' => false])]
    private bool $isVerified = false;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $verificationToken = null;

    /**
     * Bevorzugtes Kartendeck (Wert eines {@see Kartendeck}-Falls).
     * Unbekannte/Alt-Werte fallen über {@see getKartendeck()} auf den Default zurück.
     */
    #[ORM\Column(length: 20, options: ['default' => 'BELLOT'])]
    private string $kartenbildPraeferenz = 'BELLOT';

    /** Letzter Zeitpunkt der Benutzernamen-Änderung (für 7-Tage-Cooldown). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $nutzernameGeaendertAm = null;

    /** Ob das Profil öffentlich sichtbar ist. */
    #[ORM\Column(options: ['default' => true])]
    private bool $profilOeffentlich = true;

    #[ORM\Column]
    private \DateTimeImmutable $erstelltAm;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $letzterLoginAm = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->erstelltAm = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    /**
     * Symfony Security nutzt getUserIdentifier() für Login – wir nehmen die E-Mail.
     * Der Username ist nur für die Anzeige.
     */
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function eraseCredentials(): void
    {
        // Kein Klartext-Passwort zu löschen
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): static
    {
        $this->isVerified = $isVerified;

        return $this;
    }

    public function getVerificationToken(): ?string
    {
        return $this->verificationToken;
    }

    public function setVerificationToken(?string $verificationToken): static
    {
        $this->verificationToken = $verificationToken;

        return $this;
    }

    public function getKartenbildPraeferenz(): string
    {
        return $this->kartenbildPraeferenz;
    }

    public function setKartenbildPraeferenz(string $kartenbildPraeferenz): static
    {
        $this->kartenbildPraeferenz = $kartenbildPraeferenz;

        return $this;
    }

    /** Gewähltes Kartendeck (toleranter Lookup mit Fallback auf den Default). */
    public function getKartendeck(): Kartendeck
    {
        return Kartendeck::vonWert($this->kartenbildPraeferenz);
    }

    public function getNutzernameGeaendertAm(): ?\DateTimeImmutable { return $this->nutzernameGeaendertAm; }
    public function setNutzernameGeaendertAm(?\DateTimeImmutable $am): static { $this->nutzernameGeaendertAm = $am; return $this; }

    public function isProfilOeffentlich(): bool { return $this->profilOeffentlich; }
    public function setProfilOeffentlich(bool $oeffentlich): static { $this->profilOeffentlich = $oeffentlich; return $this; }

    public function getErstelltAm(): \DateTimeImmutable
    {
        return $this->erstelltAm;
    }

    public function getLetzterLoginAm(): ?\DateTimeImmutable
    {
        return $this->letzterLoginAm;
    }

    public function setLetzterLoginAm(?\DateTimeImmutable $letzterLoginAm): static
    {
        $this->letzterLoginAm = $letzterLoginAm;

        return $this;
    }
}
