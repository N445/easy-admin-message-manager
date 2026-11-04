<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, mixed>
 */
final class MessengerManagerVoter extends Voter
{
    /**
     * @param array<string, string> $roles
     */
    public function __construct(
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
        private readonly array $roles,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return null !== Permission::tryFrom($attribute);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $role = $this->roles[Permission::from($attribute)->configKey()] ?? 'ROLE_ADMIN';

        return $this->accessDecisionManager->decide($token, [$role]);
    }
}
