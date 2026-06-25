<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\ReplayService;
use App\Entity\Spiel;
use App\Entity\User;
use App\Repository\SpielRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
class ReplayController extends AbstractController
{
    public function __construct(
        private readonly SpielRepository $spielRepo,
        private readonly ReplayService $replayService,
    ) {}

    #[Route('/replays', name: 'app_replay_liste')]
    public function liste(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('replay/liste.html.twig', [
            'spiele' => $this->spielRepo->findeBeendeteFuerUser($user),
        ]);
    }

    #[Route('/replays/{id}', name: 'app_replay')]
    public function zeigen(Spiel $spiel): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$spiel->istBeendet() || !$this->istTeilnehmer($spiel, $user)) {
            throw $this->createNotFoundException('Replay nicht verfügbar.');
        }

        return $this->render('replay/index.html.twig', [
            'spiel'  => $spiel,
            'replay' => $this->replayService->baueDaten($spiel, $user),
        ]);
    }

    private function istTeilnehmer(Spiel $spiel, User $user): bool
    {
        foreach ($spiel->getTeilnehmer() as $t) {
            if ($t->getUser()?->getId() == $user->getId()) {
                return true;
            }
        }

        return false;
    }
}
