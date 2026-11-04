<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Tests\Unit;

use EasyAdminMessengerManager\Security\MessengerManagerVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class MessengerManagerVoterTest extends TestCase
{
    public function testDelegatesEachPermissionToItsConfiguredRole(): void
    {
        $decisions = $this->createStub(AccessDecisionManagerInterface::class);
        $decisions->method('decide')->willReturnCallback(static fn ($token, array $roles): bool => ['ROLE_SUPPORT'] === $roles);
        $voter = new MessengerManagerVoter($decisions, ['view' => 'ROLE_SUPPORT', 'retry' => 'ROLE_SUPPORT', 'remove' => 'ROLE_SUPER_ADMIN']);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote(new NullToken(), null, ['EAMM_VIEW']));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote(new NullToken(), null, ['EAMM_RETRY']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), null, ['EAMM_REMOVE']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(new NullToken(), null, ['ROLE_USER']));
    }
}
