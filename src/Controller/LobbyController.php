<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Doppelkopf\TischBeitrittsService;
use App\Domain\Doppelkopf\Exception\TischGesperrtException;
use App\Domain\Doppelkopf\Exception\TischZugangVerweigertException;
use App\Entity\Tisch;
use App\Entity\User;
use App\Form\TischErstellenType;
use App\Infrastructure\Mercure\LobbyMercurePublisher;
use App\Repository\TischRepository;
use App\Repository\TischSpielerRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/lobby')]
#[IsGranted('ROLE_USER')]
class LobbyController extends AbstractController
{
    public function __construct(
        private readonly TischRepository $tischRepo,
        private readonly TischSpielerRepository $tischSpielerRepo,
        private readonly TischBeitrittsService $beitrittsService,
        #[Autowire('%env(MERCURE_PUBLIC_URL)%')]
        private readonly string $mercurePublicUrl,
    ) {}

    /** Tisch, an dem der User aktuell aktiv ist (Ein-Tisch-Regel), oder null. */
    private function aktiverTisch(?User $user): ?Tisch
    {
        if ($user === null) {
            return null;
        }

        return $this->tischSpielerRepo->findAktiveMitgliedschaft($user)?->getTisch();
    }

    #[Route('', name: 'app_lobby', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $erstellenForm = $this->createForm(TischErstellenType::class, null, [
            'action' => $this->generateUrl('app_tisch_erstellen'),
        ]);

        return $this->render('lobby/index.html.twig', [
            'tische'           => $this->tischRepo->findAktiveFuerLobby(),
            'erstellenForm'    => $erstellenForm,
            'aktiverTisch'     => $this->aktiverTisch($this->getUser()),
            'mercurePublicUrl' => $this->mercurePublicUrl,
            'mercureTopic'     => LobbyMercurePublisher::TOPIC,
        ]);
    }

    #[Route('/tisch-erstellen', name: 'app_tisch_erstellen', methods: ['POST'])]
    public function tischErstellen(Request $request): Response
    {
        $form = $this->createForm(TischErstellenType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var \App\Entity\User $user */
            $user  = $this->getUser();
            $daten = $form->getData();

            try {
                $tisch = $this->beitrittsService->erstelleTisch(
                    $user,
                    $daten['name'],
                    $daten['zugangsmodus'],
                );

                $this->addFlash('success', 'Tisch „' . $tisch->getName() . '" wurde erstellt.');
            } catch (TischZugangVerweigertException $e) {
                $this->addFlash('error', $e->getMessage());
            }

            return $this->redirectToRoute('app_lobby');
        }

        return $this->render('lobby/index.html.twig', [
            'tische'           => $this->tischRepo->findAktiveFuerLobby(),
            'erstellenForm'    => $form,
            'aktiverTisch'     => $this->aktiverTisch($this->getUser()),
            'mercurePublicUrl' => $this->mercurePublicUrl,
            'mercureTopic'     => LobbyMercurePublisher::TOPIC,
        ]);
    }

    #[Route('/tisch/{id}/beitreten', name: 'app_tisch_beitreten', methods: ['POST'])]
    public function beitreten(Tisch $tisch, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('tisch_beitreten_' . $tisch->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültige Anfrage.');
            return $this->redirectToRoute('app_lobby');
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        try {
            $this->beitrittsService->beitreten($tisch, $user);
            $this->addFlash('success', 'Du hast Tisch „' . $tisch->getName() . '" betreten.');
        } catch (TischGesperrtException | TischZugangVerweigertException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_lobby');
    }

    #[Route('/tisch/{id}/verlassen', name: 'app_tisch_verlassen', methods: ['POST'])]
    public function verlassen(Tisch $tisch, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('tisch_verlassen_' . $tisch->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültige Anfrage.');
            return $this->redirectToRoute('app_lobby');
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $this->beitrittsService->verlassen($tisch, $user);
        $this->addFlash('success', 'Du hast den Tisch verlassen.');

        return $this->redirectToRoute('app_lobby');
    }

    #[Route('/tischliste', name: 'app_lobby_tischliste', methods: ['GET'])]
    public function tischliste(): Response
    {
        return $this->render('lobby/_tischliste.html.twig', [
            'tische'       => $this->tischRepo->findAktiveFuerLobby(),
            'aktiverTisch' => $this->aktiverTisch($this->getUser()),
        ]);
    }
}
