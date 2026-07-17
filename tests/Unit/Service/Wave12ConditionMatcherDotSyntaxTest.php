<?php
/**
 * Regression tests for dot-syntax dynamic-token resolution in ConditionMatcher.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://openregister.app
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\OperatorEvaluator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Before this change `$user.uid` was not a token: it fell through
 * resolveDynamicValue() as the literal string '$user.uid' and was compared
 * against the object's stored value. The rule silently never matched, with no
 * diagnostic. These tests pin resolution and the unknown-token deny.
 */
class Wave12ConditionMatcherDotSyntaxTest extends TestCase
{
    private IUserSession $userSession;
    private ContainerInterface $container;
    private LoggerInterface $logger;
    private ConditionMatcher $matcher;

    protected function setUp(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $user->method('getEMailAddress')->willReturn('alice@example.org');
        $user->method('getDisplayName')->willReturn('Alice Example');

        $this->userSession = $this->createMock(IUserSession::class);
        $this->userSession->method('getUser')->willReturn($user);

        $this->container = $this->createMock(ContainerInterface::class);
        $this->logger    = $this->createMock(LoggerInterface::class);

        $this->matcher = new ConditionMatcher(
            $this->userSession,
            $this->container,
            new OperatorEvaluator($this->createMock(LoggerInterface::class)),
            $this->logger
        );
    }

    public function testUserUidResolves(): void
    {
        $this->assertTrue(
            $this->matcher->objectMatchesConditions(['owner' => 'alice'], ['owner' => '$user.uid'])
        );
    }

    public function testUserUidDoesNotMatchAnotherUser(): void
    {
        $this->assertFalse(
            $this->matcher->objectMatchesConditions(['owner' => 'bob'], ['owner' => '$user.uid'])
        );
    }

    public function testUserEmailResolves(): void
    {
        $this->assertTrue(
            $this->matcher->objectMatchesConditions(
                ['contact' => 'alice@example.org'],
                ['contact' => '$user.email']
            )
        );
    }

    public function testUserDisplayNameResolves(): void
    {
        $this->assertTrue(
            $this->matcher->objectMatchesConditions(
                ['author' => 'Alice Example'],
                ['author' => '$user.displayName']
            )
        );
    }

    public function testUnknownUserTokenDenies(): void
    {
        // The regression: previously '$user.unknownThing' was compared as a
        // literal and the rule silently never matched. It must now resolve to
        // null, which the matcher treats as a deny.
        $this->assertFalse(
            $this->matcher->objectMatchesConditions(
                ['x' => 'anything'],
                ['x' => '$user.unknownThing']
            )
        );
    }

    public function testUnknownUserTokenDeniesEvenWhenObjectStoresTheLiteral(): void
    {
        // This is the sharp edge. Before the fix an object that happened to
        // store the literal string '$user.unknownThing' would MATCH the rule —
        // a client-controllable value silently satisfying an authorization
        // condition. Resolution to null must deny regardless of stored data.
        $this->assertFalse(
            $this->matcher->objectMatchesConditions(
                ['x' => '$user.unknownThing'],
                ['x' => '$user.unknownThing']
            )
        );
    }

    public function testUnknownOrganisationTokenDenies(): void
    {
        $this->assertFalse(
            $this->matcher->objectMatchesConditions(
                ['x' => 'anything'],
                ['x' => '$organisation.bogus']
            )
        );
    }

    public function testUnknownTokenIsLogged(): void
    {
        // A silent deny is as hard to debug as a silent allow. The warning is
        // part of the contract, not a nicety.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $matcher = new ConditionMatcher(
            $this->userSession,
            $this->container,
            new OperatorEvaluator($this->createMock(LoggerInterface::class)),
            $logger
        );

        $matcher->objectMatchesConditions(['x' => 'y'], ['x' => '$user.nope']);
    }

    public function testBareUserTokenStillResolves(): void
    {
        // BC: dot-syntax must not disturb the existing bare tokens.
        $this->assertTrue(
            $this->matcher->objectMatchesConditions(['owner' => 'alice'], ['owner' => '$user'])
        );
        $this->assertTrue(
            $this->matcher->objectMatchesConditions(['owner' => 'alice'], ['owner' => '$userId'])
        );
    }

    public function testNonTokenStringIsUntouched(): void
    {
        // A plain literal must not be dragged into token resolution.
        $this->assertTrue(
            $this->matcher->objectMatchesConditions(['status' => 'open'], ['status' => 'open'])
        );
    }

    public function testDollarStringThatIsNotADottedTokenIsUntouched(): void
    {
        // '$price' is not a dotted token and must remain a literal.
        $this->assertTrue(
            $this->matcher->objectMatchesConditions(['label' => '$price'], ['label' => '$price'])
        );
    }

    public function testUserGroupsIsNotSupportedAndDenies(): void
    {
        // Deliberately unsupported: it resolves to an array, which the SQL-path
        // twin cannot express as a scalar equality. Supporting it here alone
        // would make list and find endpoints disagree. It must deny, loudly,
        // rather than half-work.
        $this->assertFalse(
            $this->matcher->objectMatchesConditions(
                ['g' => 'admin'],
                ['g' => '$user.groups']
            )
        );
    }

    public function testAnonymousUserDeniesDottedUserToken(): void
    {
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn(null);

        $matcher = new ConditionMatcher(
            $session,
            $this->container,
            new OperatorEvaluator($this->createMock(LoggerInterface::class)),
            $this->logger
        );

        $this->assertFalse(
            $matcher->objectMatchesConditions(['owner' => 'alice'], ['owner' => '$user.uid'])
        );
    }
}
