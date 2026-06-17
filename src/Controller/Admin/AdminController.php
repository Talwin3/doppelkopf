<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Application\SystemEinstellungService;
use App\Entity\User;
use App\Repository\SystemEinstellungRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin', name: 'app_admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SystemEinstellungService $einstellungService,
        private readonly SystemEinstellungRepository $einstellungRepo,
    ) {}

    #[Route('', name: '')]
    public function dashboard(): Response
    {
        $gesamtBenutzer = $this->em->getRepository(User::class)->count([]);
        $verifiziert    = $this->em->getRepository(User::class)->count(['isVerified' => true]);

        return $this->render('admin/dashboard.html.twig', [
            'gesamtBenutzer' => $gesamtBenutzer,
            'verifiziert'    => $verifiziert,
        ]);
    }

    #[Route('/benutzer', name: '_benutzer')]
    public function benutzerListe(): Response
    {
        $benutzer = $this->em->getRepository(User::class)->findBy(
            [],
            ['erstelltAm' => 'DESC'],
            100,
        );

        return $this->render('admin/benutzer_liste.html.twig', [
            'benutzer' => $benutzer,
        ]);
    }

    #[Route('/einstellungen', name: '_einstellungen')]
    public function einstellungen(): Response
    {
        return $this->render('admin/einstellungen.html.twig', [
            'einstellungen' => $this->einstellungRepo->findAlle(),
        ]);
    }

    #[Route('/einstellungen/{schluessel}', name: '_einstellung_speichern', methods: ['POST'])]
    public function einstellungSpeichern(string $schluessel, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('einstellung_' . $schluessel, $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiger CSRF-Token.');
            return $this->redirectToRoute('app_admin_einstellungen');
        }

        $wert = trim((string) $request->request->get('wert', ''));
        if ($wert === '') {
            $this->addFlash('error', 'Wert darf nicht leer sein.');
            return $this->redirectToRoute('app_admin_einstellungen');
        }

        $this->einstellungService->set($schluessel, $wert);
        $this->addFlash('success', sprintf('Einstellung „%s" gespeichert.', $schluessel));

        return $this->redirectToRoute('app_admin_einstellungen');
    }

    #[Route('/benutzer/{id}/admin-toggle', name: '_benutzer_admin_toggle', methods: ['POST'])]
    public function adminToggle(User $user, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_toggle_' . $user->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiger CSRF-Token.');

            return $this->redirectToRoute('app_admin_benutzer');
        }

        // Eigene Admin-Rechte darf man nicht entziehen
        if ($user === $this->getUser()) {
            $this->addFlash('error', 'Eigene Admin-Rechte können nicht entzogen werden.');

            return $this->redirectToRoute('app_admin_benutzer');
        }

        $rollen = $user->getRoles();
        if (in_array('ROLE_ADMIN', $rollen, true)) {
            $user->setRoles(array_values(array_filter($rollen, fn($r) => $r !== 'ROLE_ADMIN' && $r !== 'ROLE_USER')));
        } else {
            $user->setRoles(['ROLE_ADMIN']);
        }

        $this->em->flush();
        $this->addFlash('success', sprintf('Admin-Rechte für %s aktualisiert.', $user->getUsername()));

        return $this->redirectToRoute('app_admin_benutzer');
    }
}
