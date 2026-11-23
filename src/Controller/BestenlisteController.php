<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Doppelkopf\BestenlisteService;
use App\Application\Doppelkopf\BestenlistenZeitraum;
use App\Entity\User;
use App\Enum\WertungsKategorie;
use App\Repository\SpielTeilnehmerRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class BestenlisteController extends AbstractController
{
    public function __construct(
        private readonly BestenlisteService $bestenliste,
        private readonly SpielTeilnehmerRepository $teilnehmerRepo,
    ) {}

    /**
     * Monats- bzw. Jahresbestenliste einer Kategorie. Parameter (alle optional):
     * kategorie (DDV|OHNE_NEUNER|SONDERREGELN), zeitraum (monat|jahr), jahr, monat,
     * sortierung (schnitt|punkte).
     */
    #[Route('/bestenlisten', name: 'app_bestenlisten')]
    public function index(Request $request): Response
    {
        $jetzt     = new \DateTimeImmutable();
        $kategorie = WertungsKategorie::tryFrom((string) $request->query->get('kategorie', '')) ?? WertungsKategorie::DDV;
        $zeitraum  = BestenlistenZeitraum::ausAnfrage(
            (string) $request->query->get('zeitraum', BestenlistenZeitraum::MONAT),
            self::ganzzahl($request->query->get('jahr')),
            self::ganzzahl($request->query->get('monat')),
            $jetzt,
        );
        $sortierung = $request->query->get('sortierung') === BestenlisteService::NACH_PUNKTEN
            ? BestenlisteService::NACH_PUNKTEN
            : BestenlisteService::NACH_SCHNITT;

        ['minSpiele' => $minSpiele, 'konten' => $konten, 'liste' => $liste]
            = $this->bestenliste->ansicht($kategorie, $zeitraum, $sortierung);

        // Zurückblättern bis zum Zeitraum des ersten gewerteten Spiels.
        $fruehestes = $this->teilnehmerRepo->findeFruehestesGewertetesSpielende();
        $erster     = BestenlistenZeitraum::aktuell($zeitraum->art, $fruehestes ?? $jetzt);
        $vorheriger = $zeitraum->istNach($erster) ? $zeitraum->vorheriger() : null;
        $aktuell    = BestenlistenZeitraum::aktuell($zeitraum->art, $jetzt);
        $naechster  = $aktuell->istNach($zeitraum) ? $zeitraum->naechster() : null;

        return $this->render('bestenliste/index.html.twig', [
            'kategorie'      => $kategorie,
            'kategorien'     => WertungsKategorie::cases(),
            'zeitraum'       => $zeitraum,
            'monatsZeitraum' => $this->monatZumWechseln($zeitraum, $jetzt),
            'jahresZeitraum' => BestenlistenZeitraum::jahr($zeitraum->jahr),
            'vorheriger'     => $vorheriger,
            'naechster'      => $naechster,
            'sortierung'     => $sortierung,
            'minSpiele'      => $minSpiele,
            'liste'          => $liste,
            'unterMindest'   => BestenlisteService::anzahlUnterMindestanzahl($konten, $minSpiele),
            'eigenesKonto'   => $this->eigenesKonto($konten, $liste),
        ]);
    }

    /**
     * Stand des angemeldeten Spielers im Zeitraum, auch wenn er (noch) nicht gelistet ist.
     *
     * @param list<array{user_id: string, punkte: int, spiele: int, gelistet: bool}> $konten
     * @param list<array{user_id: string, rang: int}> $liste
     * @return array{punkte: int, spiele: int, gelistet: bool, rang: ?int}|null
     */
    private function eigenesKonto(array $konten, array $liste): ?array
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return null;
        }
        $id = $user->getId()->toRfc4122();

        $rang = null;
        foreach ($liste as $zeile) {
            if ($zeile['user_id'] === $id) {
                $rang = $zeile['rang'];
                break;
            }
        }
        foreach ($konten as $k) {
            if ($k['user_id'] === $id) {
                return ['punkte' => $k['punkte'], 'spiele' => $k['spiele'], 'gelistet' => $user->isGelistet(), 'rang' => $rang];
            }
        }

        return ['punkte' => 0, 'spiele' => 0, 'gelistet' => $user->isGelistet(), 'rang' => null];
    }

    /**
     * Ziel des Reiters „Monat“: der angezeigte Monat; aus einer Jahresansicht der
     * laufende Monat (laufendes Jahr) bzw. der Dezember (vergangenes Jahr).
     */
    private function monatZumWechseln(BestenlistenZeitraum $zeitraum, \DateTimeImmutable $jetzt): BestenlistenZeitraum
    {
        if ($zeitraum->art === BestenlistenZeitraum::MONAT) {
            return $zeitraum;
        }
        $aktuell = BestenlistenZeitraum::aktuell(BestenlistenZeitraum::MONAT, $jetzt);

        return $zeitraum->jahr < $aktuell->jahr ? BestenlistenZeitraum::monat($zeitraum->jahr, 12) : $aktuell;
    }

    private static function ganzzahl(mixed $wert): ?int
    {
        return is_string($wert) && preg_match('/^\d{1,4}$/', $wert) === 1 ? (int) $wert : null;
    }
}
