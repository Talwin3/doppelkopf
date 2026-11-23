<?php

declare(strict_types=1);

namespace App\Infrastructure\Mercure;

use App\Entity\ChatNachricht;
use App\Entity\Spiel;
use App\Entity\Tisch;
use App\Infrastructure\Logger\MercureLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class SpielMercurePublisher
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly MercureLogger $logger,
    ) {}

    public function kartenAusgeteilt(Spiel $spiel): void
    {
        $this->publizieren($spiel, 'KARTEN_AUSGETEILT');
    }

    public function spielGestartet(Spiel $spiel): void
    {
        $this->publizieren($spiel, 'SPIEL_GESTARTET');
    }

    public function karteGespielt(Spiel $spiel, int $sitzplatz): void
    {
        $this->publizieren($spiel, 'KARTE_GESPIELT', ['sitzplatz' => $sitzplatz]);
    }

    public function stichAbgeschlossen(Spiel $spiel, int $gewinnerSitzplatz, int $gespielterSitzplatz): void
    {
        $this->publizieren($spiel, 'STICH_ABGESCHLOSSEN', [
            'gewinnerSitzplatz'   => $gewinnerSitzplatz,
            'gespielterSitzplatz' => $gespielterSitzplatz,
        ]);
    }

    public function ansageGemacht(Spiel $spiel): void
    {
        $this->publizieren($spiel, 'ANSAGE_GEMACHT');
    }

    public function vorbehaltDeklariert(Spiel $spiel): void
    {
        $this->publizieren($spiel, 'VORBEHALT_DEKLARIERT');
    }

    public function armutAnfrage(Spiel $spiel): void
    {
        $this->publizieren($spiel, 'ARMUT_ANFRAGE');
    }

    public function armutAngenommen(Spiel $spiel): void
    {
        $this->publizieren($spiel, 'ARMUT_ANGENOMMEN');
    }

    public function armutAbgelehnt(Spiel $spiel): void
    {
        $this->publizieren($spiel, 'ARMUT_ABGELEHNT');
    }

    public function spielBeendet(Spiel $spiel): void
    {
        $this->publizieren($spiel, 'SPIEL_BEENDET');
    }

    public function tischZustandAktualisiert(Spiel $spiel): void
    {
        $this->publizieren($spiel, 'TISCH_ZUSTAND');
    }

    /**
     * Tisch-Einstellungen geändert (Regelwerk, Punkteanzeige): Clients laden den
     * Zustand neu. Funktioniert auch zwischen den Spielen, wenn kein Spiel läuft.
     */
    public function tischAktualisiert(Tisch $tisch, bool $regelwerkGeaendert = false, ?string $aenderung = null): void
    {
        // `regelwerk` sagt dem Client, ob ungespeicherte Regelwerk-Häkchen jetzt veraltet sind;
        // `aenderung` ist die Kennung, die der speichernde Client mitgeschickt hat.
        $daten = ['typ' => 'TISCH_EINSTELLUNGEN', 'regelwerk' => $regelwerkGeaendert];
        if ($aenderung !== null) {
            $daten['aenderung'] = $aenderung;
        }
        $this->senden($this->topicFuerTisch($tisch), $daten);
    }

    /**
     * Besetzung geändert (Beitritt, Verlassen, Bots, Kontolöschung): Punkteleiste und
     * Panel nachladen. Bewusst getrennt von TISCH_EINSTELLUNGEN, damit ein Beitritt
     * keine ungespeicherten Regelwerk-Eingaben verwirft.
     */
    public function tischBesetzungGeaendert(Tisch $tisch): void
    {
        $this->senden($this->topicFuerTisch($tisch), ['typ' => 'TISCH_BESETZUNG']);
    }

    /**
     * Veröffentlicht eine Chat-Nachricht auf dem tischbezogenen Topic. Läuft über
     * denselben Kanal wie die Spiel-Events; Clients unterscheiden anhand von `typ`.
     */
    public function chatNachricht(ChatNachricht $nachricht): void
    {
        $this->senden($this->topicFuerTisch($nachricht->getTisch()), [
            'typ'        => 'CHAT_NACHRICHT',
            'id'         => (string) $nachricht->getId(),
            'system'     => $nachricht->istSystem(),
            'absender'   => $nachricht->getAbsenderName(),
            'absenderId' => (string) ($nachricht->getAbsender()?->getId() ?? ''),
            'text'       => $nachricht->getText(),
            'zeit'       => $nachricht->getErstelltAm()->format('H:i'),
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function publizieren(Spiel $spiel, string $typ, array $extra = []): void
    {
        $this->senden($this->topic($spiel), array_merge(['typ' => $typ, 'spielId' => (string) $spiel->getId()], $extra));
    }

    /**
     * Gemeinsamer Versand: Fehler beim Hub werden nur protokolliert, damit ein
     * ausgefallener Hub nie die Spiellogik abbricht.
     *
     * @param array<string, mixed> $payload
     */
    private function senden(string $topic, array $payload): void
    {
        $typ = (string) ($payload['typ'] ?? '');
        try {
            $this->hub->publish(new Update($topic, json_encode($payload, JSON_THROW_ON_ERROR)));
            $this->logger->updateVeroeffentlicht($topic, $typ);
        } catch (\Throwable $e) {
            $this->logger->veroeffentlichungFehlgeschlagen($topic, $e->getMessage());
        }
    }

    public function topic(Spiel $spiel): string
    {
        return $this->topicFuerTisch($spiel->getTisch());
    }

    /**
     * Tischbezogenes (nicht spielbezogenes) Topic: bleibt über Spielgrenzen hinweg
     * stabil, damit ein Client am Tisch auch den Start des nächsten Spiels mitbekommt
     * (Auto-Start nach dem Punktestand) und nicht auf das Topic des beendeten Spiels
     * abonniert bleibt.
     */
    public function topicFuerTisch(Tisch $tisch): string
    {
        return 'https://doppelkopf.de/tisch/' . $tisch->getId();
    }
}
