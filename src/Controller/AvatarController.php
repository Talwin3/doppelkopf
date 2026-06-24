<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\AvatarService;
use App\Enum\AvatarStil;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AvatarController extends AbstractController
{
    public function __construct(
        private readonly AvatarService $avatarService,
    ) {}

    /**
     * Liefert ein deterministisches Avatar-SVG für Stil + Seed.
     * Wird als <img src> eingebunden; aggressiv gecacht (Avatare sind unveränderlich
     * pro Stil+Seed – ein neuer Seed erzeugt einfach eine neue URL).
     */
    #[Route('/avatar/{stil}/{seed}.svg', name: 'app_avatar', methods: ['GET'], requirements: ['seed' => '[^/]+'])]
    public function avatar(string $stil, string $seed, Request $request): Response
    {
        $stilEnum = AvatarStil::tryFrom($stil);
        if ($stilEnum === null) {
            throw $this->createNotFoundException('Unbekannter Avatar-Stil.');
        }

        $groesse = $request->query->getInt('size');
        $svg = $this->avatarService->rendern(
            $stilEnum,
            rawurldecode($seed),
            $groesse > 0 ? $groesse : null,
        );

        $response = new Response($svg, Response::HTTP_OK, ['Content-Type' => 'image/svg+xml']);
        $response->setPublic();
        $response->setMaxAge(2592000);       // 30 Tage Browser-Cache
        $response->setImmutable();
        $response->setEtag(substr(sha1($svg), 0, 16));
        $response->isNotModified($request);

        return $response;
    }
}
