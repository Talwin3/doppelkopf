<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Doppelkopf\AnsageService;
use App\Application\Doppelkopf\KarteAusspielenService;
use App\Application\Doppelkopf\SpielStartService;
use App\Application\Doppelkopf\TischBeitrittsService;
use App\Application\Doppelkopf\VorbehaltService;
use App\Domain\Doppelkopf\Exception\UngueltigeAnsageException;
use App\Domain\Doppelkopf\Exception\UngueltigerZugException;
use App\Entity\Tisch;
use App\Enum\AnsageTyp;
use App\Enum\SpielVariante;
use App\Enum\VorbehaltTyp;
use App\Infrastructure\Mercure\SpielMercurePublisher;
use App\Repository\GespielteKarteRepository;
use App\Repository\SpielAnsageRepository;
use App\Repository\SpielRepository;
use App\Repository\SpielTeilnehmerRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/spieltisch')]
#[IsGranted('ROLE_USER')]
class SpielController extends AbstractController
{
    public function __construct(
        private readonly SpielRepository $spielRepo,
        private readonly SpielTeilnehmerRepository $teilnehmerRepo,
        private readonly GespielteKarteRepository $gespielteKarteRepo,
        private readonly SpielAnsageRepository $ansageRepo,
        private readonly SpielStartService $spielStartService,
        private readonly KarteAusspielenService $karteAusspielenService,
        private readonly AnsageService $ansageService,
        private readonly SpielMercurePublisher $mercurePublisher,
        private readonly TischBeitrittsService $beitrittsService,
        private readonly VorbehaltService $vorbehaltService,
        #[Autowire('%env(MERCURE_PUBLIC_URL)%')]
        private readonly string $mercurePublicUrl,
    ) {}

    #[Route('/{id}', name: 'app_spieltisch', methods: ['GET'])]
    public function index(Tisch $tisch): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $istSpieler = false;
        foreach ($tisch->getAktiveSpieler() as $ts) {
            if ($ts->getUser()?->getId() == $user->getId()) {
                $istSpieler = true;
                break;
            }
        }

        if (!$istSpieler) {
            $this->addFlash('error', 'Du sitzt nicht an diesem Tisch.');
            return $this->redirectToRoute('app_lobby');
        }

        $spiel = $tisch->anzahlAktiveSpieler() === 4
            ? $this->spielStartService->starten($tisch)
            : $this->spielRepo->findLaufendesSpielFuerTisch($tisch);

        [$teilnehmer, $hand, $ansagen, $verfuegbareAnsagen, $vorbehaltOptionen] = $this->spielDaten($spiel, $user);

        return $this->render('spieltisch/index.html.twig', [
            'tisch'              => $tisch,
            'spiel'              => $spiel,
            'teilnehmer'         => $teilnehmer,
            'hand'               => $hand,
            'ansagen'            => $ansagen,
            'verfuegbareAnsagen' => $verfuegbareAnsagen,
            'vorbehaltOptionen'  => $vorbehaltOptionen,
            'mercurePublicUrl'   => $this->mercurePublicUrl,
            'mercureTopic'       => $spiel ? $this->mercurePublisher->topic($spiel) : null,
        ]);
    }

    #[Route('/{id}/zustand', name: 'app_spieltisch_zustand', methods: ['GET'])]
    public function zustand(Tisch $tisch): Response
    {
        /** @var \App\Entity\User $user */
        $user  = $this->getUser();
        $spiel = $this->spielRepo->findLaufendesSpielFuerTisch($tisch);

        [$teilnehmer, $hand, $ansagen, $verfuegbareAnsagen, $vorbehaltOptionen] = $this->spielDaten($spiel, $user);

        return $this->render('spieltisch/_spielzustand.html.twig', [
            'tisch'              => $tisch,
            'spiel'              => $spiel,
            'teilnehmer'         => $teilnehmer,
            'hand'               => $hand,
            'ansagen'            => $ansagen,
            'verfuegbareAnsagen' => $verfuegbareAnsagen,
            'vorbehaltOptionen'  => $vorbehaltOptionen,
        ]);
    }

    #[Route('/{id}/karte-spielen', name: 'app_karte_spielen', methods: ['POST'])]
    public function karteAusspielen(Tisch $tisch, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('karte_spielen_' . $tisch->getId(), $request->request->get('_token'))) {
            return new JsonResponse(['fehler' => 'Ungültige Anfrage.'], Response::HTTP_FORBIDDEN);
        }

        /** @var \App\Entity\User $user */
        $user  = $this->getUser();
        $spiel = $this->spielRepo->findLaufendesSpielFuerTisch($tisch);

        if ($spiel === null) {
            return new JsonResponse(['fehler' => 'Kein laufendes Spiel.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->karteAusspielenService->spielen($spiel, $user, $request->request->get('karte_id', ''));
            return new JsonResponse(['ok' => true]);
        } catch (UngueltigerZugException $e) {
            return new JsonResponse(['fehler' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    #[Route('/{id}/ansagen', name: 'app_ansagen', methods: ['POST'])]
    public function ansagen(Tisch $tisch, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('ansagen_' . $tisch->getId(), $request->request->get('_token'))) {
            return new JsonResponse(['fehler' => 'Ungültige Anfrage.'], Response::HTTP_FORBIDDEN);
        }

        /** @var \App\Entity\User $user */
        $user  = $this->getUser();
        $spiel = $this->spielRepo->findLaufendesSpielFuerTisch($tisch);

        if ($spiel === null) {
            return new JsonResponse(['fehler' => 'Kein laufendes Spiel.'], Response::HTTP_NOT_FOUND);
        }

        $typWert = $request->request->get('ansage_typ', '');
        $typ     = AnsageTyp::tryFrom($typWert);

        if ($typ === null) {
            return new JsonResponse(['fehler' => 'Unbekannter Ansage-Typ.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->ansageService->machen($spiel, $user, $typ);
            return new JsonResponse(['ok' => true]);
        } catch (UngueltigeAnsageException $e) {
            return new JsonResponse(['fehler' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    #[Route('/{id}/vorbehalt', name: 'app_vorbehalt', methods: ['POST'])]
    public function vorbehaltDeklarieren(Tisch $tisch, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('vorbehalt_' . $tisch->getId(), $request->request->get('_token'))) {
            return new JsonResponse(['fehler' => 'Ungültige Anfrage.'], Response::HTTP_FORBIDDEN);
        }

        /** @var \App\Entity\User $user */
        $user  = $this->getUser();
        $spiel = $this->spielRepo->findLaufendesSpielFuerTisch($tisch);

        if ($spiel === null) {
            return new JsonResponse(['fehler' => 'Kein laufendes Spiel.'], Response::HTTP_NOT_FOUND);
        }

        $typWert = $request->request->get('vorbehalt_typ', '');
        $typ     = VorbehaltTyp::tryFrom($typWert);
        if ($typ === null) {
            return new JsonResponse(['fehler' => 'Unbekannter Vorbehalt-Typ.'], Response::HTTP_BAD_REQUEST);
        }

        $soloVariante = null;
        if ($typ === VorbehaltTyp::SOLO) {
            $varianteWert = $request->request->get('solo_variante', '');
            $soloVariante = SpielVariante::tryFrom($varianteWert);
            if ($soloVariante === null) {
                return new JsonResponse(['fehler' => 'Ungültige Solo-Variante.'], Response::HTTP_BAD_REQUEST);
            }
        }

        try {
            $this->vorbehaltService->deklarieren($spiel, $user, $typ, $soloVariante);
            return new JsonResponse(['ok' => true]);
        } catch (\DomainException $e) {
            return new JsonResponse(['fehler' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    #[Route('/{id}/regelwerk-speichern', name: 'app_regelwerk_speichern', methods: ['POST'])]
    public function regelwerkSpeichern(Tisch $tisch, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('regelwerk_' . $tisch->getId(), $request->request->get('_token'))) {
            return new JsonResponse(['fehler' => 'Ungültige Anfrage.'], Response::HTTP_FORBIDDEN);
        }

        $spiel = $this->spielRepo->findLaufendesSpielFuerTisch($tisch);
        if ($spiel !== null) {
            return new JsonResponse(['fehler' => 'Regelwerk kann nur zwischen Spielen geändert werden.'], Response::HTTP_CONFLICT);
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        // Sicherstellen dass User ein aktiver Spieler am Tisch ist
        $istAktiv = false;
        foreach ($tisch->getAktiveSpieler() as $ts) {
            if ($ts->getUser()?->getId() == $user->getId()) {
                $istAktiv = true;
                break;
            }
        }
        if (!$istAktiv) {
            return new JsonResponse(['fehler' => 'Nur aktive Spieler können das Regelwerk ändern.'], Response::HTTP_FORBIDDEN);
        }

        $alleVarianten = ['SOLO_BUBEN', 'SOLO_DAMEN', 'SOLO_FLEISCHLOS', 'SOLO_KARO', 'SOLO_HERZ', 'SOLO_PIK', 'SOLO_KREUZ'];
        $soliEingabe   = $request->request->all()['soli_erlaubt'] ?? [];
        $soliErlaubt   = array_values(array_filter($soliEingabe, fn($v) => in_array($v, $alleVarianten, true)));

        $regelwerk = $tisch->getRegelEinstellungen();
        $regelwerk['soli_erlaubt']       = $soliErlaubt;
        $regelwerk['solist_kommt_raus']  = (bool) $request->request->get('solist_kommt_raus');
        $regelwerk['schweinchen']        = (bool) $request->request->get('schweinchen');
        $regelwerk['superschweinchen']   = (bool) $request->request->get('superschweinchen') && $regelwerk['schweinchen'];
        $regelwerk['ohne_neuner']        = (bool) $request->request->get('ohne_neuner');
        $regelwerk['zweite_dulle_sticht'] = (bool) $request->request->get('zweite_dulle_sticht');

        // Superschweinchen auto-deaktivieren wenn ohne_neuner
        if ($regelwerk['ohne_neuner']) {
            $regelwerk['superschweinchen'] = false;
        }

        $this->beitrittsService->regelwerkAktualisieren($tisch, $regelwerk, $user);

        return new JsonResponse(['ok' => true, 'regelwerk' => $regelwerk]);
    }

    #[Route('/{id}/nach-spiel-verlassen', name: 'app_nach_spiel_verlassen', methods: ['POST'])]
    public function nachSpielVerlassen(Tisch $tisch, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('nach_spiel_verlassen_' . $tisch->getId(), $request->request->get('_token'))) {
            return new JsonResponse(['fehler' => 'Ungültige Anfrage.'], Response::HTTP_FORBIDDEN);
        }

        /** @var \App\Entity\User $user */
        $user       = $this->getUser();
        $neuerWert  = $this->beitrittsService->nachSpielVerlassenToggle($tisch, $user);

        return new JsonResponse(['moechteVerlassen' => $neuerWert]);
    }

    #[Route('/{id}/auto-start-toggle', name: 'app_auto_start_toggle', methods: ['POST'])]
    public function autoStartToggle(Tisch $tisch, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('auto_start_' . $tisch->getId(), $request->request->get('_token'))) {
            return new JsonResponse(['fehler' => 'Ungültige Anfrage.'], Response::HTTP_FORBIDDEN);
        }

        /** @var \App\Entity\User $user */
        $user      = $this->getUser();
        $neuerWert = $this->beitrittsService->autoStartToggle($tisch, $user);

        return new JsonResponse(['autoStart' => $neuerWert]);
    }

    /** Hilfsmethode: Spiel-Kontextdaten für ein Template aufbereiten. */
    private function spielDaten(?object $spiel, object $user): array
    {
        $teilnehmer         = null;
        $hand               = [];
        $ansagen            = [];
        $verfuegbareAnsagen = [];
        $vorbehaltOptionen  = [];

        if ($spiel !== null) {
            $teilnehmer = $this->teilnehmerRepo->findBySpielAndUser($spiel, $user);
            if ($teilnehmer !== null) {
                $gespielteIds = $this->gespielteKarteRepo->findGespielteKartenIds($spiel, $teilnehmer->getSitzplatz());
                $hand         = $teilnehmer->aktuelleHand($gespielteIds);
                $verfuegbareAnsagen = $this->ansageService->verfuegbareAnsagen($spiel, $user);
                $vorbehaltOptionen  = $this->vorbehaltService->verfuegbareOptionen($spiel, $user);
            }
            $ansagen = $this->ansageRepo->findFuerSpiel($spiel);
        }

        return [$teilnehmer, $hand, $ansagen, $verfuegbareAnsagen, $vorbehaltOptionen];
    }
}
