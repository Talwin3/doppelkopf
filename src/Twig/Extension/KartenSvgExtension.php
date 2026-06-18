<?php

declare(strict_types=1);

namespace App\Twig\Extension;

use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\Kartenfarbe;
use App\Enum\Kartenwert;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class KartenSvgExtension extends AbstractExtension
{
    private const ROT = '#DC2626';
    private const SCHWARZ = '#1F2937';
    private const KARTEN_BG = '#FFFFFF';
    private const KARTEN_RAND = '#D1D5DB';

    private const SUIT_PATHS = [
        'HERZ'  => 'M15,5 C15,2 10,0 7,3 C3,7 3,12 15,24 C27,12 27,7 23,3 C20,0 15,2 15,5 Z',
        'KARO'  => 'M15,1 L27,14 L15,27 L3,14 Z',
        'PIK'   => 'M15,1 C15,1 3,9 3,15 C3,20 7,23 12,20 L11,27 L19,27 L18,20 C23,23 27,20 27,15 C27,9 15,1 15,1 Z',
        'KREUZ' => 'M15,8 C12,5 7,5 7,10 C7,14 11,15 14,13 L12,27 L18,27 L16,13 C19,15 23,14 23,10 C23,5 18,5 15,8 Z M11,4 C11,0 19,0 19,4 C19,7 15,8 15,8 C15,8 11,7 11,4 Z',
    ];

    private const WERT_LABELS = [
        'ASS'    => 'A',
        'ZEHN'   => '10',
        'KOENIG' => 'K',
        'DAME'   => 'D',
        'BUBE'   => 'B',
        'NEUN'   => '9',
    ];

    private const PIP_POSITIONS = [
        'NEUN' => [
            [0.5, 0.18], [0.5, 0.5], [0.5, 0.82],
            [0.25, 0.27], [0.25, 0.5], [0.25, 0.73],
            [0.75, 0.27], [0.75, 0.5], [0.75, 0.73],
        ],
        'ZEHN' => [
            [0.25, 0.18], [0.25, 0.38], [0.25, 0.58], [0.25, 0.78],
            [0.75, 0.18], [0.75, 0.38], [0.75, 0.58], [0.75, 0.78],
            [0.5, 0.28], [0.5, 0.68],
        ],
        'ASS' => [
            [0.5, 0.5],
        ],
    ];

    public function getFunctions(): array
    {
        return [
            new TwigFunction('karten_svg', $this->renderKarte(...), ['is_safe' => ['html']]),
            new TwigFunction('karten_ruecken_svg', $this->renderRuecken(...), ['is_safe' => ['html']]),
        ];
    }

    public function renderKarte(Karte $karte): string
    {
        $farbe = $karte->farbe;
        $wert = $karte->wert;
        $fill = $farbe->istRot() ? self::ROT : self::SCHWARZ;
        $label = self::WERT_LABELS[$wert->value];
        $suitPath = self::SUIT_PATHS[$farbe->value];

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 250 350" class="karte-svg">';

        // Schatten + Karte
        $svg .= '<defs>';
        $svg .= '<filter id="ks' . $this->karteHash($karte) . '"><feDropShadow dx="0" dy="1" stdDeviation="2" flood-opacity="0.15"/></filter>';
        $svg .= '</defs>';
        $svg .= '<rect x="2" y="2" width="246" height="346" rx="16" ry="16" fill="' . self::KARTEN_BG . '" stroke="' . self::KARTEN_RAND . '" stroke-width="1.5" filter="url(#ks' . $this->karteHash($karte) . ')"/>';

        // Ecke oben links
        $svg .= $this->ecke($label, $suitPath, $fill, false);

        // Ecke unten rechts (gespiegelt)
        $svg .= $this->ecke($label, $suitPath, $fill, true);

        // Zentrum
        $svg .= $this->zentrum($farbe, $wert, $fill, $suitPath);

        $svg .= '</svg>';

        return $svg;
    }

    public function renderRuecken(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 250 350" class="karte-svg">';
        $svg .= '<rect x="2" y="2" width="246" height="346" rx="16" ry="16" fill="#14532D" stroke="#166534" stroke-width="1.5"/>';
        $svg .= '<rect x="14" y="14" width="222" height="322" rx="10" ry="10" fill="none" stroke="#22C55E" stroke-width="1" stroke-dasharray="6,4" opacity="0.4"/>';

        // Rauten-Muster
        for ($y = 40; $y < 310; $y += 40) {
            for ($x = 35; $x < 220; $x += 45) {
                $svg .= '<path d="M' . $x . ',' . ($y - 12) . ' L' . ($x + 10) . ',' . $y . ' L' . $x . ',' . ($y + 12) . ' L' . ($x - 10) . ',' . $y . ' Z" fill="#16A34A" opacity="0.25"/>';
            }
        }

        // Zentrales Emblem
        $svg .= '<circle cx="125" cy="175" r="35" fill="none" stroke="#22C55E" stroke-width="2" opacity="0.5"/>';
        $svg .= '<text x="125" y="183" text-anchor="middle" font-size="28" font-weight="bold" fill="#22C55E" opacity="0.5">DK</text>';

        $svg .= '</svg>';

        return $svg;
    }

    private function ecke(string $label, string $suitPath, string $fill, bool $gespiegelt): string
    {
        $g = '';
        if ($gespiegelt) {
            $g .= '<g transform="translate(250,350) rotate(180)">';
        }

        $fontSize = $label === '10' ? '28' : '32';
        $g .= '<text x="20" y="42" text-anchor="middle" font-size="' . $fontSize . '" font-weight="bold" font-family="Georgia,serif" fill="' . $fill . '">' . $label . '</text>';
        $g .= '<g transform="translate(10,48) scale(0.6)">' . '<path d="' . $suitPath . '" fill="' . $fill . '"/>' . '</g>';

        if ($gespiegelt) {
            $g .= '</g>';
        }

        return $g;
    }

    private function zentrum(Kartenfarbe $farbe, Kartenwert $wert, string $fill, string $suitPath): string
    {
        $g = '';
        $wertKey = $wert->value;

        if (isset(self::PIP_POSITIONS[$wertKey])) {
            $scale = $wertKey === 'ASS' ? 2.2 : 0.8;
            $pipSize = 30 * $scale;

            foreach (self::PIP_POSITIONS[$wertKey] as $pos) {
                $cx = 20 + $pos[0] * 210;
                $cy = 70 + $pos[1] * 210;
                $tx = $cx - $pipSize / 2;
                $ty = $cy - $pipSize / 2;
                $g .= '<g transform="translate(' . $tx . ',' . $ty . ') scale(' . $scale . ')">';
                $g .= '<path d="' . $suitPath . '" fill="' . $fill . '"/>';
                $g .= '</g>';
            }
        } else {
            $g .= $this->gesichtKarte($farbe, $wert, $fill, $suitPath);
        }

        return $g;
    }

    private function gesichtKarte(Kartenfarbe $farbe, Kartenwert $wert, string $fill, string $suitPath): string
    {
        $g = '';
        $label = self::WERT_LABELS[$wert->value];

        // Dekorativer Rahmen
        $g .= '<rect x="45" y="70" width="160" height="210" rx="12" ry="12" fill="none" stroke="' . $fill . '" stroke-width="1.5" opacity="0.2"/>';

        // Hintergrund-Ornament
        $bgFill = $farbe->istRot() ? '#FEE2E2' : '#F3F4F6';
        $g .= '<rect x="50" y="75" width="150" height="200" rx="10" ry="10" fill="' . $bgFill . '"/>';

        // Großer Buchstabe
        $g .= '<text x="125" y="200" text-anchor="middle" font-size="80" font-weight="bold" font-family="Georgia,serif" fill="' . $fill . '" opacity="0.9">' . $label . '</text>';

        // Krone/Schwert/Schild je nach Typ
        $g .= match ($wert) {
            Kartenwert::KOENIG => $this->kronenSymbol($fill),
            Kartenwert::DAME   => $this->damenSymbol($fill),
            Kartenwert::BUBE   => $this->bubenSymbol($fill),
            default            => '',
        };

        // Suit-Symbole links und rechts
        $g .= '<g transform="translate(55,82) scale(0.7)"><path d="' . $suitPath . '" fill="' . $fill . '" opacity="0.7"/></g>';
        $g .= '<g transform="translate(250,280) rotate(180) scale(0.7)"><path d="' . (str_replace('M', 'M', $suitPath)) . '" fill="' . $fill . '" opacity="0.7"/></g>';

        return $g;
    }

    private function kronenSymbol(string $fill): string
    {
        // Krone über dem Buchstaben
        return '<g transform="translate(100,90)">'
            . '<path d="M0,30 L8,10 L16,25 L25,5 L34,25 L42,10 L50,30 Z" fill="' . $fill . '" opacity="0.6"/>'
            . '<rect x="0" y="30" width="50" height="6" rx="2" fill="' . $fill . '" opacity="0.6"/>'
            . '</g>';
    }

    private function damenSymbol(string $fill): string
    {
        // Stilisierte Lilie
        return '<g transform="translate(108,90)">'
            . '<ellipse cx="17" cy="22" rx="14" ry="8" fill="none" stroke="' . $fill . '" stroke-width="2" opacity="0.5"/>'
            . '<path d="M17,5 L17,18 M10,10 L17,18 L24,10" fill="none" stroke="' . $fill . '" stroke-width="2" stroke-linecap="round" opacity="0.5"/>'
            . '</g>';
    }

    private function bubenSymbol(string $fill): string
    {
        // Schild-Symbol
        return '<g transform="translate(108,92)">'
            . '<path d="M17,2 L30,8 L30,20 C30,28 17,34 17,34 C17,34 4,28 4,20 L4,8 Z" fill="none" stroke="' . $fill . '" stroke-width="2" opacity="0.5"/>'
            . '</g>';
    }

    private function karteHash(Karte $karte): string
    {
        return substr(md5($karte->id()), 0, 6);
    }
}
