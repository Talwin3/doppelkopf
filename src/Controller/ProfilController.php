<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\ProfilService;
use App\Application\StatistikService;
use App\Entity\User;
use App\Enum\Kartendeck;
use App\Form\BenutzernameAendernType;
use App\Form\EmailAendernType;
use App\Form\PasswortAendernType;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class ProfilController extends AbstractController
{
    public function __construct(
        private readonly ProfilService $profilService,
        private readonly StatistikService $statistikService,
        private readonly UserRepository $userRepo,
        private readonly Security $security,
    ) {}

    // ── Öffentliches Profil ────────────────────────────────────────────────

    #[Route('/profil/{username}', name: 'app_profil')]
    public function profil(string $username): Response
    {
        $profilUser = $this->userRepo->findOneBy(['username' => $username]);
        if ($profilUser === null) {
            throw $this->createNotFoundException('Spieler nicht gefunden.');
        }

        /** @var User|null $ich */
        $ich            = $this->getUser();
        $istEigenProfil = $ich !== null && $ich->getId() == $profilUser->getId();

        if (!$profilUser->isProfilOeffentlich() && !$istEigenProfil) {
            throw $this->createNotFoundException('Dieses Profil ist nicht öffentlich.');
        }

        return $this->render('profil/profil.html.twig', [
            'profilUser'     => $profilUser,
            'istEigenProfil' => $istEigenProfil,
            'statistik'      => $this->statistikService->fuerUser($profilUser),
        ]);
    }

    // ── Einstellungen (GET) ────────────────────────────────────────────────

    #[Route('/einstellungen', name: 'app_profil_einstellungen')]
    #[IsGranted('ROLE_USER')]
    public function einstellungen(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $cooldownBis = null;
        if ($user->getNutzernameGeaendertAm() !== null) {
            $bis = $user->getNutzernameGeaendertAm()->modify('+7 days');
            if (new \DateTimeImmutable() < $bis) {
                $cooldownBis = $bis;
            }
        }

        return $this->render('profil/einstellungen.html.twig', [
            'user'             => $user,
            'benutzernameForm' => $this->createForm(BenutzernameAendernType::class),
            'passwortForm'     => $this->createForm(PasswortAendernType::class),
            'emailForm'        => $this->createForm(EmailAendernType::class),
            'cooldownBis'      => $cooldownBis,
            'kartendecks'      => Kartendeck::cases(),
        ]);
    }

    // ── Benutzername ändern ────────────────────────────────────────────────

    #[Route('/einstellungen/benutzername', name: 'app_profil_benutzername', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function benutzernameAendern(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $form = $this->createForm(BenutzernameAendernType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->profilService->benutzernameAendern($user, $form->get('username')->getData());
                $this->addFlash('success', 'Benutzername erfolgreich geändert.');
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        } else {
            foreach ($form->getErrors(true) as $fehler) {
                $this->addFlash('error', $fehler->getMessage());
            }
        }

        return $this->redirectToRoute('app_profil_einstellungen');
    }

    // ── Passwort ändern ───────────────────────────────────────────────────

    #[Route('/einstellungen/passwort', name: 'app_profil_passwort', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function passwortAendern(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $form = $this->createForm(PasswortAendernType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->profilService->passwortAendern(
                    $user,
                    $form->get('aktuellesPasswort')->getData(),
                    $form->get('neuesPasswort')->getData(),
                );
                $this->addFlash('success', 'Passwort erfolgreich geändert.');
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        } else {
            foreach ($form->getErrors(true) as $fehler) {
                $this->addFlash('error', $fehler->getMessage());
            }
        }

        return $this->redirectToRoute('app_profil_einstellungen');
    }

    // ── E-Mail ändern ─────────────────────────────────────────────────────

    #[Route('/einstellungen/email', name: 'app_profil_email', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function emailAendern(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $form = $this->createForm(EmailAendernType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->profilService->emailAendern(
                    $user,
                    $form->get('email')->getData(),
                    $form->get('passwort')->getData(),
                );
                $this->addFlash('success', 'E-Mail-Adresse erfolgreich geändert.');
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        } else {
            foreach ($form->getErrors(true) as $fehler) {
                $this->addFlash('error', $fehler->getMessage());
            }
        }

        return $this->redirectToRoute('app_profil_einstellungen');
    }

    // ── Datenschutz ───────────────────────────────────────────────────────

    #[Route('/einstellungen/datenschutz', name: 'app_profil_datenschutz', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function datenschutzAendern(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('datenschutz', $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültige Anfrage.');
            return $this->redirectToRoute('app_profil_einstellungen');
        }

        /** @var User $user */
        $user = $this->getUser();
        $this->profilService->datenschutzAendern($user, (bool) $request->request->get('profil_oeffentlich'));
        $this->addFlash('success', 'Datenschutz-Einstellung gespeichert.');

        return $this->redirectToRoute('app_profil_einstellungen');
    }

    // ── Kartendeck ─────────────────────────────────────────────────────────

    #[Route('/einstellungen/kartendeck', name: 'app_profil_kartendeck', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function kartendeckAendern(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('kartendeck', $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültige Anfrage.');
            return $this->redirectToRoute('app_profil_einstellungen');
        }

        /** @var User $user */
        $user = $this->getUser();
        $this->profilService->kartendeckAendern($user, (string) $request->request->get('kartendeck', ''));
        $this->addFlash('success', 'Kartendeck gespeichert.');

        return $this->redirectToRoute('app_profil_einstellungen');
    }

    // ── DSGVO-Datenexport ─────────────────────────────────────────────────

    #[Route('/einstellungen/daten-export', name: 'app_profil_daten_export', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function datenExportieren(): JsonResponse
    {
        /** @var User $user */
        $user  = $this->getUser();
        $daten = $this->profilService->datenExportieren($user);
        $datei = 'doppelkopf_daten_' . $user->getUsername() . '_' . date('Y-m-d') . '.json';

        return new JsonResponse(
            $daten,
            Response::HTTP_OK,
            ['Content-Disposition' => 'attachment; filename="' . $datei . '"'],
        );
    }

    // ── Konto löschen (DSGVO Art. 17) ────────────────────────────────────

    #[Route('/einstellungen/konto-loeschen', name: 'app_profil_konto_loeschen', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function kontoLoeschen(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('konto_loeschen', $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültige Anfrage.');
            return $this->redirectToRoute('app_profil_einstellungen');
        }

        /** @var User $user */
        $user         = $this->getUser();
        $bestaetigung = $request->request->get('bestaetigung', '');

        if ($bestaetigung !== $user->getUsername()) {
            $this->addFlash('error', 'Zur Bestätigung bitte den Benutzernamen exakt eingeben.');
            return $this->redirectToRoute('app_profil_einstellungen');
        }

        $this->profilService->kontoLoeschen($user);
        $request->getSession()->invalidate();
        $this->security->logout(false);

        return $this->redirectToRoute('app_start');
    }
}
