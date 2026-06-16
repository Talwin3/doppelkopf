<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class ProfilController extends AbstractController
{
    #[Route('/profil/{username}', name: 'app_profil')]
    public function profil(string $username): Response
    {
        // TODO Phase 3: Statistiken laden
        return $this->render('profil/profil.html.twig', [
            'username' => $username,
        ]);
    }

    #[Route('/profil/einstellungen', name: 'app_profil_einstellungen')]
    #[IsGranted('ROLE_USER')]
    public function einstellungen(): Response
    {
        // TODO Phase 3: Einstellungen (Kartenbild, Benutzername, Passwort ändern)
        return $this->render('profil/einstellungen.html.twig');
    }
}
