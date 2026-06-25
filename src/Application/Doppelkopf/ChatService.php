<?php

declare(strict_types=1);

namespace App\Application\Doppelkopf;

use App\Entity\ChatNachricht;
use App\Entity\Tisch;
use App\Entity\User;
use App\Infrastructure\Mercure\SpielMercurePublisher;
use App\Repository\ChatNachrichtRepository;
use App\Repository\TischSpielerRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tisch-Chat: nimmt Nachrichten aktiver Spieler entgegen, persistiert sie und
 * verteilt sie live über Mercure an alle am Tisch.
 */
final class ChatService
{
    public const MAX_LAENGE = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TischSpielerRepository $tischSpielerRepo,
        private readonly ChatNachrichtRepository $chatRepo,
        private readonly SpielMercurePublisher $mercurePublisher,
    ) {}

    /**
     * @throws \DomainException wenn der Nutzer nicht aktiv am Tisch sitzt oder der Text leer ist
     */
    public function sendeNachricht(Tisch $tisch, User $user, string $text): ChatNachricht
    {
        $tischSpieler = $this->tischSpielerRepo->findByTischAndUser($tisch, $user);
        if ($tischSpieler === null || !$tischSpieler->istAktiv()) {
            throw new \DomainException('Nur aktive Spieler am Tisch können chatten.');
        }

        $text = trim($text);
        if ($text === '') {
            throw new \DomainException('Leere Nachricht.');
        }
        if (mb_strlen($text) > self::MAX_LAENGE) {
            $text = mb_substr($text, 0, self::MAX_LAENGE);
        }

        $nachricht = new ChatNachricht();
        $nachricht->setTisch($tisch);
        $nachricht->setAbsender($user);
        $nachricht->setAbsenderName($user->getUsername());
        $nachricht->setText($text);

        $this->em->persist($nachricht);
        $this->em->flush();

        $this->mercurePublisher->chatNachricht($nachricht);

        return $nachricht;
    }

    /**
     * @return ChatNachricht[] Älteste zuerst.
     */
    public function verlauf(Tisch $tisch, int $limit = 50): array
    {
        return $this->chatRepo->findeLetzteFuerTisch($tisch, $limit);
    }
}
