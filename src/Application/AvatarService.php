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

    /**
     * Liefert die validierte Style-Instanz. Da {@see Style::fromJson()} die JSON-
     * Schema-Validierung ausführt (~350 ms) und jeder Avatar in einem eigenen
     * Request mit eigenem Stil gerendert wird, würde der reine Prozess-Cache nie
     * greifen. Deshalb wird die *validierte* Style-Instanz serialisiert im Cache
     * gehalten: Deserialisieren ruft den Konstruktor (und damit die Validierung)
     * nicht erneut auf (~1 ms statt ~350 ms). Cache-Key enthält die Datei-mtime,
     * damit ein Update des dicebear/styles-Pakets automatisch neu validiert.
     */
    private function style(AvatarStil $stil): Style
    {
        if (isset($this->styleCache[$stil->value])) {
            return $this->styleCache[$stil->value];
        }

        $pfad    = $this->stilPfad($stil);
        $version = @filemtime($pfad) ?: 0;
        $key     = 'avatar_style_' . $stil->value . '_' . $version;

        $style = $this->cache->get($key, function (ItemInterface $item) use ($pfad): Style {
            $item->expiresAfter(2592000); // 30 Tage

            $json = @file_get_contents($pfad);
            if ($json === false) {
                throw new \RuntimeException(sprintf('Avatar-Stil-Datei nicht gefunden (%s).', $pfad));
            }

            return Style::fromJson($json);
        });

        return $this->styleCache[$stil->value] = $style;
    }

    private function stilPfad(AvatarStil $stil): string
    {
        return $this->projectDir . '/vendor/dicebear/styles/src/' . $stil->value . '.json';
    }
}
