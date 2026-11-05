<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Tests\App;

use EasyAdminMessengerManager\Message\MessageView;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, MessageView>
 */
final class TestSupportVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return 'EAMM_RETRY' === $attribute && $subject instanceof MessageView;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $message = $subject->envelope->getMessage();

        return $message instanceof TestMessage && 'support' === $message->label && null !== $token->getUser();
    }
}
