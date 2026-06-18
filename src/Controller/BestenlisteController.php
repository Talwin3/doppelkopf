<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\StatistikService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class BestenlisteController extends AbstractController
{
    public function __construct(
        private readonly StatistikService $statistikService,
    ) {}

    #[Route('/bestenlisten', name: 'app_bestenlisten')]
    public function index(): Response
    {
        return $this->render('bestenliste/index.html.twig', [
            'gesamt' => $this->statistikService->bestelisteGesamt(50),
            'monat'  => $this->statistikService->bestelisteMonat(50),
            'monatLabel' => (new \DateTimeImmutable())->format('F Y'),
        ]);
    }
}
