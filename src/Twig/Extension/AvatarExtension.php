<?php

declare(strict_types=1);

namespace App\Twig\Extension;

use App\Application\SystemEinstellungService;
use App\Entity\SpielTeilnehmer;
use App\Entity\TischSpieler;
use App\Entity\User;
use App\Enum\AvatarStil;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Stellt Avatar-URLs für Templates bereit. Die URLs zeigen auf
 * {@see \App\Controller\AvatarController}, der das SVG self-hosted rendert.
 */
final class AvatarExtension extends AbstractExtension
{
    private ?AvatarStil $botStilCache = null;

    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly SystemEinstellungService $einstellungen,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('avatar_url', $this->avatarUrl(...)),
            new TwigFunction('avatar_url_roh', $this->avatarUrlRoh(...)),
        ];
    }

    /**
     * URL für einen Spieler. Akzeptiert {@see User} (Profil-Avatar) sowie
     * {@see TischSpieler}/{@see SpielTeilnehmer} (Mensch → Profil-Avatar,
     * Bot → admin-konfigurierter Stil mit Bot-Name als Seed).
     */
    public function avatarUrl(User|TischSpieler|SpielTeilnehmer|null $ziel, ?int $size = null): string
    {
        if ($ziel instanceof User) {
            return $this->avatarUrlRoh($ziel->getAvatarStil()->value, $ziel->getAvatarSeed(), $size);
        }

        if ($ziel instanceof TischSpieler || $ziel instanceof SpielTeilnehmer) {
            $user = $ziel->getUser();
            if ($user !== null) {
                return $this->avatarUrlRoh($user->getAvatarStil()->value, $user->getAvatarSeed(), $size);
            }

            // Bot-Platzhalter: Stil aus den Systemeinstellungen, Seed = Bot-Name.
            return $this->avatarUrlRoh($this->botStil()->value, $ziel->getBotName() ?? 'Bot', $size);
        }

        // Leerer Sitzplatz o. Ä.: neutraler Platzhalter.
        return $this->avatarUrlRoh(AvatarStil::SHAPES->value, 'leer', $size);
    }

    /** Direkte URL aus Stil-Wert und Seed – für Vorschauen in der Stil-Auswahl. */
    public function avatarUrlRoh(string $stilWert, string $seed, ?int $size = null): string
    {
        $params = ['stil' => $stilWert, 'seed' => $seed];
        if ($size !== null) {
            $params['size'] = $size;
        }

        return $this->urls->generate('app_avatar', $params);
    }

    private function botStil(): AvatarStil
    {
        return $this->botStilCache ??= AvatarStil::vonWert(
            $this->einstellungen->get('bot_avatar_stil'),
        );
    }
}
