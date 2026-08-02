<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\PasswortZuruecksetzenType;
use App\Infrastructure\Logger\SicherheitsLogger;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\Exception\TooManyPasswordRequestsException;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

class SecurityController extends AbstractController
{
    use ResetPasswordControllerTrait;

    public function __construct(
        private readonly SicherheitsLogger            $logger,
        private readonly ResetPasswordHelperInterface $resetHelper,
        private readonly MailerInterface              $mailer,
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

    /**
     * Schritt 1: Reset anfordern. Die Antwort ist immer identisch – egal ob die
     * E-Mail-Adresse existiert, ob gerade eine Sperre greift oder ob der Versand
     * scheiterte. Sonst wäre die Seite ein Orakel für gültige Konten.
     */
    #[Route('/passwort-vergessen', name: 'app_passwort_vergessen')]
    public function passwortVergessen(Request $request, UserRepository $userRepo): Response
    {
        $gesendet = false;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('passwort_vergessen', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Die Sitzung ist abgelaufen. Bitte versuche es erneut.');

                return $this->redirectToRoute('app_passwort_vergessen');
            }

            $email = trim((string) $request->request->get('email', ''));
            $user  = $email !== '' ? $userRepo->findOneBy(['email' => $email]) : null;

            if ($user !== null) {
                $this->logger->passwortResetAngefordert($user->getUsername());
                $this->sendeResetEmail($user);
            }

            $gesendet = true;
        }

        return $this->render('security/passwort_vergessen.html.twig', [
            'gesendet'          => $gesendet,
            'gueltigkeitInMin'  => intdiv($this->resetHelper->getTokenLifetime(), 60),
        ]);
    }

    /**
     * Schritt 2: neues Passwort setzen. Der Token aus der E-Mail wird sofort in
     * die Session übernommen und die URL ohne ihn neu geladen – so taucht er
     * nicht im Referer auf, wenn die Seite externe Ressourcen nachlädt.
     */
    #[Route('/passwort-zuruecksetzen/{token}', name: 'app_passwort_zuruecksetzen', defaults: ['token' => null])]
    public function passwortZuruecksetzen(
        Request                     $request,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface      $em,
        ?string                     $token = null,
    ): Response {
        if ($token !== null) {
            $this->storeTokenInSession($token);

            return $this->redirectToRoute('app_passwort_zuruecksetzen');
        }

        $token = $this->getTokenFromSession();

        if ($token === null) {
            $this->addFlash('error', 'Kein gültiger Zurücksetz-Link. Bitte fordere einen neuen an.');

            return $this->redirectToRoute('app_passwort_vergessen');
        }

        try {
            $user = $this->resetHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface $e) {
            $this->cleanSessionAfterReset();
            $this->logger->passwortResetTokenUngueltig($e->getReason());
            $this->addFlash('error', 'Der Zurücksetz-Link ist ungültig oder abgelaufen. Bitte fordere einen neuen an.');

            return $this->redirectToRoute('app_passwort_vergessen');
        }

        \assert($user instanceof User);

        $formular = $this->createForm(PasswortZuruecksetzenType::class);
        $formular->handleRequest($request);

        if ($formular->isSubmitted() && $formular->isValid()) {
            // Erst den Token verbrennen (entfernt auch alle weiteren offenen
            // Anfragen des Nutzers), dann das Passwort setzen.
            $this->resetHelper->removeResetRequest($token);

            $user->setPassword($hasher->hashPassword($user, $formular->get('neuesPasswort')->getData()));
            $em->flush();

            $this->cleanSessionAfterReset();
            $this->logger->passwortResetDurchgefuehrt($user->getUsername());
            $this->addFlash('success', 'Dein Passwort wurde geändert. Du kannst dich jetzt anmelden.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/passwort_zuruecksetzen.html.twig', [
            'formular' => $formular,
        ]);
    }

    private function sendeResetEmail(User $user): void
    {
        try {
            $resetToken = $this->resetHelper->generateResetToken($user);
        } catch (TooManyPasswordRequestsException) {
            // Sperre läuft noch – bewusst stillschweigend, sonst verrät die
            // abweichende Antwort, dass es das Konto gibt.
            return;
        }

        $email = (new Email())
            ->to($user->getEmail())
            ->subject('Doppelkopf – Passwort zurücksetzen')
            ->html($this->renderView('security/passwort_reset_email.html.twig', [
                'username'  => $user->getUsername(),
                'resetUrl'  => $this->generateUrl(
                    'app_passwort_zuruecksetzen',
                    ['token' => $resetToken->getToken()],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
                'expiresAt' => $resetToken->getExpiresAt(),
            ]));

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            // Auch hier keine abweichende Antwort an den Browser; der Fehler
            // gehört ins Log, damit der Betreiber ihn bemerkt.
            $this->logger->passwortResetVersandFehlgeschlagen($user->getUsername(), $e->getMessage());
        }
    }
}
