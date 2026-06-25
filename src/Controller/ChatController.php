<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Doppelkopf\ChatService;
use App\Entity\Tisch;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/spieltisch')]
#[IsGranted('ROLE_USER')]
class ChatController extends AbstractController
{
    public function __construct(
        private readonly ChatService $chatService,
    ) {}

    #[Route('/{id}/chat', name: 'app_chat_senden', methods: ['POST'])]
    public function senden(Tisch $tisch, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('chat_' . $tisch->getId(), $request->request->get('_token'))) {
            return new JsonResponse(['fehler' => 'Ungültige Anfrage.'], Response::HTTP_FORBIDDEN);
        }

        /** @var User $user */
        $user = $this->getUser();

        try {
            $this->chatService->sendeNachricht($tisch, $user, (string) $request->request->get('text', ''));
        } catch (\DomainException $e) {
            return new JsonResponse(['fehler' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        }

        // Die Nachricht erreicht den Client (auch den Absender) live über Mercure.
        return new JsonResponse(['ok' => true]);
    }
}
