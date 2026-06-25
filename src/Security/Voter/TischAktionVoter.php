<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Tisch;
use App\Entity\User;
use App\Enum\TischSteuerungsModus;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Entscheidet, wer Tisch-Einstellungen verändern darf (Regelwerk, Auto-Start,
 * Bots auffüllen). Subjekt ist immer ein {@see Tisch}.
 *
 * Regel: Der Nutzer muss aktiver Spieler am Tisch sein. Steht der Tisch auf
 * {@see TischSteuerungsModus::NUR_ERSTELLER}, muss er zusätzlich der Ersteller sein.
 */
final class TischAktionVoter extends Voter
{
    /** Tisch-Einstellungen verwalten. */
    public const VERWALTEN = 'TISCH_VERWALTEN';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::VERWALTEN && $subject instanceof Tisch;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /** @var Tisch $tisch */
        $tisch = $subject;

        if (!$this->istAktiverSpieler($tisch, $user)) {
            return false;
        }

        if ($tisch->getSteuerungsModus() === TischSteuerungsModus::NUR_ERSTELLER) {
            return $tisch->getErsteller()->getId() == $user->getId();
        }

        return true;
    }

    private function istAktiverSpieler(Tisch $tisch, User $user): bool
    {
        foreach ($tisch->getAktiveSpieler() as $ts) {
            if ($ts->getUser()?->getId() == $user->getId()) {
                return true;
            }
        }

        return false;
    }
}
