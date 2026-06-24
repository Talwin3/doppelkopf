<?php

declare(strict_types=1);

namespace App\Application;

use App\Enum\AvatarStil;
use DiceBear\Avatar;
use DiceBear\Style;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Rendert DiceBear-Avatare self-hosted aus dem Paket dicebear/styles.
 *
 * Avatare sind deterministisch (Stil + Seed → immer dasselbe SVG), daher wird
 * das gerenderte SVG dauerhaft im Cache gehalten; die {@see Style}-Definitionen
 * werden zusätzlich prozessweit gepuffert (Schema-Validierung ist teuer).
 */
final class AvatarService
{
    /** @var array<string, Style> Stil-Wert → validierte Style-Instanz. */
    private array $styleCache = [];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * Liefert das gerenderte SVG für Stil + Seed.
     *
     * @param positive-int|null $size Optionale Pixelgröße (quadratisch); null = native Größe.
     */
    public function rendern(AvatarStil $stil, string $seed, ?int $size = null): string
    {
        $cacheKey = 'avatar_' . $stil->value . '_' . substr(sha1($seed), 0, 16) . '_' . ($size ?? 0);

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($stil, $seed, $size) {
            $item->expiresAfter(2592000); // 30 Tage – Avatare sind deterministisch

            $optionen = ['seed' => $seed];
            if ($size !== null) {
                $optionen['size'] = $size;
            }

            return (new Avatar($this->style($stil), $optionen))->toString();
        });
    }

    private function style(AvatarStil $stil): Style
    {
        return $this->styleCache[$stil->value] ??= Style::fromJson(
            $this->styleJson($stil),
        );
    }

    private function styleJson(AvatarStil $stil): string
    {
        $pfad = $this->projectDir . '/vendor/dicebear/styles/src/' . $stil->value . '.json';
        $json = @file_get_contents($pfad);

        if ($json === false) {
            throw new \RuntimeException(sprintf('Avatar-Stil "%s" nicht gefunden (%s).', $stil->value, $pfad));
        }

        return $json;
    }
}
