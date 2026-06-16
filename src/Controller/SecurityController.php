<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Infrastructure\Logger\SicherheitsLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    public function __construct(
        private readonly SicherheitsLogger $logger,
    ) {}

    #[Route('/anmelden', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_lobby');
        }

        return $this->render('security/login.html.twig', [
            'fehler'           => $authenticationUtils->getLastAuthenticationError(),
            'letzteEmail'      => $authenticationUtils->getLastUsername(),
        ]);
    }

    #[Route('/abmelden', name: 'app_logout')]
    public function logout(): never
    {
        // Symfony übernimmt das Logout automatisch über die Firewall-Konfiguration
        throw new \LogicException('Diese Methode wird niemals ausgeführt.');
    }

    #[Route('/passwort-vergessen', name: 'app_passwort_vergessen')]
    public function passwortVergessen(
        Request                $request,
        EntityManagerInterface $em,
    ): Response {
        $gesendet = false;

        if ($request->isMethod('POST')) {
            $email = $request->request->get('email', '');
            $user  = $em->getRepository(User::class)->findOneBy(['email' => $email]);

            // Gleiche Antwort ob User existiert oder nicht (verhindert User-Enumeration)
            if ($user) {
                $this->logger->passwortResetAngefordert($user->getUsername());
                // TODO: Reset-Token generieren und E-Mail versenden (Phase 0 Erweiterung)
            }

            $gesendet = true;
        }

        return $this->render('security/passwort_vergessen.html.twig', [
            'gesendet' => $gesendet,
        ]);
    }
}
