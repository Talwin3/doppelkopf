<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class StartController extends AbstractController
{
    #[Route('/', name: 'app_start')]
    public function start(): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_lobby');
        }

        return $this->render('start/start.html.twig');
    }

    #[Route('/regeln', name: 'app_regeln')]
    public function regeln(): Response
    {
        return $this->render('start/regeln.html.twig');
    }

    #[Route('/impressum', name: 'app_impressum')]
    public function impressum(): Response
    {
        return $this->render('start/impressum.html.twig');
    }

    #[Route('/datenschutz', name: 'app_datenschutz')]
    public function datenschutz(): Response
    {
        return $this->render('start/datenschutz.html.twig');
    }

}
