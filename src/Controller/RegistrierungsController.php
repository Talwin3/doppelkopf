<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrierungsFormularType;
use App\Infrastructure\Logger\SicherheitsLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

class RegistrierungsController extends AbstractController
{
    public function __construct(
        private readonly VerifyEmailHelperInterface  $verifyEmailHelper,
        private readonly MailerInterface             $mailer,
        private readonly SicherheitsLogger           $logger,
    ) {}

    #[Route('/registrieren', name: 'app_registrieren')]
    public function registrieren(
        Request                     $request,
        UserPasswordHasherInterface $passwortHasher,
        EntityManagerInterface      $em,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_lobby');
        }

        $user = new User();
        $formular = $this->createForm(RegistrierungsFormularType::class, $user);
        $formular->handleRequest($request);

        if ($formular->isSubmitted() && $formular->isValid()) {
            $user->setPassword(
                $passwortHasher->hashPassword($user, $formular->get('plainPassword')->getData())
            );

            $em->persist($user);
            $em->flush();

            $this->sendeVerifizierungsEmail($user);

            $this->logger->registrierungErfolgreich($user->getUsername());

            $this->addFlash('success', 'Registrierung erfolgreich! Bitte bestätige deine E-Mail-Adresse.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('registration/registrieren.html.twig', [
            'formular' => $formular,
        ]);
    }

    #[Route('/e-mail-bestaetigen', name: 'app_email_bestaetigen')]
    public function emailBestaetigen(
        Request                $request,
        EntityManagerInterface $em,
    ): Response {
        $user = $this->getUser();

        if (!$user instanceof User) {
            $this->addFlash('warning', 'Bitte erst einloggen, um die E-Mail zu bestätigen.');

            return $this->redirectToRoute('app_login');
        }

        try {
            $this->verifyEmailHelper->validateEmailConfirmationFromRequest(
                $request,
                $user->getId()->toRfc4122(),
                $user->getEmail(),
            );
        } catch (VerifyEmailExceptionInterface $e) {
            $this->logger->verifikationFehlgeschlagen($user->getUsername(), $e->getReason());
            $this->addFlash('error', 'Der Bestätigungslink ist ungültig oder abgelaufen.');

            return $this->redirectToRoute('app_registrieren');
        }

        $user->setIsVerified(true);
        $em->flush();

        $this->logger->emailVerifiziert($user->getUsername());
        $this->addFlash('success', 'E-Mail-Adresse bestätigt! Viel Spaß beim Spielen.');

        return $this->redirectToRoute('app_lobby');
    }

    #[Route('/verifikations-email-senden', name: 'app_verifikation_senden')]
    public function verifikationsEmailErneutSenden(): Response
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        if ($user->isVerified()) {
            return $this->redirectToRoute('app_lobby');
        }

        $this->sendeVerifizierungsEmail($user);
        $this->addFlash('success', 'Bestätigungsmail wurde erneut versendet.');

        return $this->redirectToRoute('app_login');
    }

    private function sendeVerifizierungsEmail(User $user): void
    {
        $signaturDetails = $this->verifyEmailHelper->generateSignature(
            'app_email_bestaetigen',
            $user->getId()->toRfc4122(),
            $user->getEmail(),
            ['id' => $user->getId()->toRfc4122()],
        );

        $email = (new Email())
            ->to($user->getEmail())
            ->subject('Doppelkopf – E-Mail-Adresse bestätigen')
            ->html($this->renderView('registration/verifikations_email.html.twig', [
                'signedUrl'  => $signaturDetails->getSignedUrl(),
                'expiresAt'  => $signaturDetails->getExpiresAt(),
                'username'   => $user->getUsername(),
            ]));

        $this->mailer->send($email);
    }
}
