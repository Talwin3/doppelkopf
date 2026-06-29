<?php

declare(strict_types=1);

namespace App\Application\Doppelkopf;

use App\Entity\ChatNachricht;
use App\Entity\SpielTeilnehmer;
use App\Entity\Tisch;
use App\Enum\ChatNachrichtTyp;
use App\Infrastructure\Mercure\SpielMercurePublisher;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tisch-Event-Log: schreibt wichtige Spiel-/Tisch-Ereignisse als System-Nachrichten
 * in den Chat. Sie laufen über denselben tischbezogenen Mercure-Kanal wie normale
 * Chat-Nachrichten, werden im Frontend aber als System-Events abgesetzt dargestellt.
 *
 * Aufrufer rufen {@see ereignis()} stets NACH ihrem eigenen flush() auf, damit die
 * Persistenz der Chat-Zeile keinen halben Spielzustand mit-flusht.
 */
final class TischProtokollService
{
    public const ABSENDER = 'System';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SpielMercurePublisher $mercurePublisher,
    ) {}

    public function ereignis(Tisch $tisch, string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }
        if (mb_strlen($text) > ChatService::MAX_LAENGE) {
            $text = mb_substr($text, 0, ChatService::MAX_LAENGE);
        }

        $nachricht = new ChatNachricht();
        $nachricht->setTisch($tisch);
        $nachricht->setTyp(ChatNachrichtTyp::SYSTEM);
        $nachricht->setAbsender(null);
        $nachricht->setAbsenderName(self::ABSENDER);
        $nachricht->setText($text);

        $this->em->persist($nachricht);
        $this->em->flush();

        $this->mercurePublisher->chatNachricht($nachricht);
    }

    /**
     * Meldet, dass ein Bot nach einem Zug-Timeout für einen (menschlichen) Spieler
     * übernimmt. Wird nur beim ersten Mal protokolliert — das Flag `vonBotVertreten`
     * verhindert eine Wiederholung bei jedem Folgezug. Aufrufer (Worker) muss
     * danach nicht flushen; {@see ereignis()} persistiert das Flag mit.
     */
    public function botUebernimmt(SpielTeilnehmer $teilnehmer): void
    {
        if ($teilnehmer->isVonBotVertreten()) {
            return;
        }

        $teilnehmer->setVonBotVertreten(true);
        $this->ereignis(
            $teilnehmer->getSpiel()->getTisch(),
            sprintf('%s reagiert nicht – ein Bot übernimmt.', $teilnehmer->getAnzeigeName()),
        );
    }

    /** Meldet ein (Super-)Schweinchen beim Spielstart. */
    public function schweinchen(SpielTeilnehmer $halter, bool $super): void
    {
        $this->ereignis(
            $halter->getSpiel()->getTisch(),
            sprintf('%s hat %s.', $halter->getAnzeigeName(), $super ? 'Superschweinchen' : 'Schweinchen'),
        );
    }

    /**
     * Gegenstück zu {@see botUebernimmt()}: meldet, dass ein zuvor vertretener Spieler
     * wieder selbst handelt. No-op, wenn der Spieler nicht vertreten wurde.
     */
    public function spielerZurueck(SpielTeilnehmer $teilnehmer): void
    {
        if (!$teilnehmer->isVonBotVertreten()) {
            return;
        }

        $teilnehmer->setVonBotVertreten(false);
        $this->ereignis(
            $teilnehmer->getSpiel()->getTisch(),
            sprintf('%s ist zurück und spielt selbst weiter.', $teilnehmer->getAnzeigeName()),
        );
    }
}
