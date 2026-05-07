<?php

namespace Unit\Service;

use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\OperatorEvaluator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class ConditionMatcherTest extends TestCase
{

    /**
     * @var IUserSession&MockObject
     */
    private IUserSession $userSession;

    /**
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface $container;

    /**
     * @var OperatorEvaluator&MockObject
     */
    private OperatorEvaluator $operatorEvaluator;

    /**
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    private ConditionMatcher $matcher;

    protected function setUp(): void
    {
        $this->userSession = $this->createMock(IUserSession::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->operatorEvaluator = $this->createMock(OperatorEvaluator::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->matcher = new ConditionMatcher(
            $this->userSession,
            $this->container,
            $this->operatorEvaluator,
            $this->logger
        );
    }

    // --- objectMatchesConditions ---

    public function testObjectMatchesConditionsWithExactStringMatch(): void
    {
        $object = ['status' => 'active', 'type' => 'task'];
        $match = ['status' => 'active'];

        $result = $this->matcher->objectMatchesConditions($object, $match);

        $this->assertTrue($result);
    }

    public function testObjectMatchesConditionsFailsOnMismatch(): void
    {
        $object = ['status' => 'inactive'];
        $match = ['status' => 'active'];

        $result = $this->matcher->objectMatchesConditions($object, $match);

        $this->assertFalse($result);
    }

    public function testObjectMatchesConditionsWithMultipleConditions(): void
    {
        $object = ['status' => 'active', 'type' => 'task'];
        $match = ['status' => 'active', 'type' => 'task'];

        $result = $this->matcher->objectMatchesConditions($object, $match);

        $this->assertTrue($result);
    }

    public function testObjectMatchesConditionsFailsWhenOneConditionFails(): void
    {
        $object = ['status' => 'active', 'type' => 'project'];
        $match = ['status' => 'active', 'type' => 'task'];

        $result = $this->matcher->objectMatchesConditions($object, $match);

        $this->assertFalse($result);
    }

    public function testObjectMatchesConditionsWithEmptyMatch(): void
    {
        $object = ['status' => 'active'];
        $match = [];

        $result = $this->matcher->objectMatchesConditions($object, $match);

        $this->assertTrue($result);
    }

    public function testObjectMatchesConditionsWithMissingProperty(): void
    {
        $object = ['status' => 'active'];
        $match = ['nonexistent' => 'value'];

        $result = $this->matcher->objectMatchesConditions($object, $match);

        $this->assertFalse($result);
    }

    public function testObjectMatchesConditionsWithAtSelfLookup(): void
    {
        // Underscore-prefixed property should check @self.
        $object = ['@self' => ['organisation' => 'org-uuid-123']];
        $match = ['_organisation' => 'org-uuid-123'];

        $result = $this->matcher->objectMatchesConditions($object, $match);

        $this->assertTrue($result);
    }

    public function testObjectMatchesConditionsDirectPropertyOverAtSelf(): void
    {
        // Direct property takes precedence.
        $object = [
            '_organisation' => 'direct-value',
            '@self' => ['organisation' => 'self-value'],
        ];
        $match = ['_organisation' => 'direct-value'];

        $result = $this->matcher->objectMatchesConditions($object, $match);

        $this->assertTrue($result);
    }

    public function testObjectMatchesConditionsWithOperatorArray(): void
    {
        $object = ['count' => 5];
        $operators = ['$gt' => 3];

        $this->operatorEvaluator
            ->expects($this->once())
            ->method('valueMatchesOperator')
            ->with(5, $operators)
            ->willReturn(true);

        $result = $this->matcher->objectMatchesConditions($object, ['count' => $operators]);

        $this->assertTrue($result);
    }

    public function testObjectMatchesConditionsWithOperatorArrayFails(): void
    {
        $object = ['count' => 2];
        $operators = ['$gt' => 3];

        $this->operatorEvaluator
            ->expects($this->once())
            ->method('valueMatchesOperator')
            ->with(2, $operators)
            ->willReturn(false);

        $result = $this->matcher->objectMatchesConditions($object, ['count' => $operators]);

        $this->assertFalse($result);
    }

    // --- Dynamic variables ---

    public function testObjectMatchesWithUserIdVariable(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user123');

        $this->userSession
            ->method('getUser')
            ->willReturn($user);

        $object = ['owner' => 'user123'];
        $match = ['owner' => '$userId'];

        $result = $this->matcher->objectMatchesConditions($object, $match);

        $this->assertTrue($result);
    }

    public function testObjectMatchesWithUserVariable(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user123');

        $this->userSession
            ->method('getUser')
            ->willReturn($user);

        $object = ['owner' => 'user123'];
        $match = ['owner' => '$user'];

        $result = $this->matcher->objectMatchesConditions($object, $match);

        $this->assertTrue($result);
    }

    public function testObjectMatchesWithNullUserReturnsNull(): void
    {
        $this->userSession
            ->method('getUser')
            ->willReturn(null);

        $object = ['owner' => 'user123'];
        $match = ['owner' => '$userId'];

        // $userId resolves to null, which differs from 'user123', so false.
        $result = $this->matcher->objectMatchesConditions($object, $match);

        $this->assertFalse($result);
    }

    public function testObjectMatchesWithNullValueConditionAndNullObjectValue(): void
    {
        $object = [];
        $match = ['field' => null];

        // Both are null => condition passes.
        $result = $this->matcher->objectMatchesConditions($object, $match);

        $this->assertTrue($result);
    }

    public function testObjectMatchesWithNullValueConditionAndNonNullObjectValue(): void
    {
        $object = ['field' => 'something'];
        $match = ['field' => null];

        // Null check: objectValue is not null => false.
        $result = $this->matcher->objectMatchesConditions($object, $match);

        $this->assertFalse($result);
    }

    // --- filterOrganisationMatchForCreate ---

    public function testFilterOrganisationMatchForCreateRemovesOrgConditions(): void
    {
        $match = [
            '_organisation' => '$organisation',
            'status' => 'active',
            'organisation' => '$activeOrganisation',
        ];

        $result = $this->matcher->filterOrganisationMatchForCreate($match);

        $this->assertArrayNotHasKey('_organisation', $result);
        $this->assertArrayNotHasKey('organisation', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertSame('active', $result['status']);
    }

    public function testFilterOrganisationMatchForCreateKeepsNonDynamicOrg(): void
    {
        $match = [
            '_organisation' => 'specific-org-uuid',
            'status' => 'active',
        ];

        $result = $this->matcher->filterOrganisationMatchForCreate($match);

        // Non-dynamic organisation values are kept.
        $this->assertArrayHasKey('_organisation', $result);
        $this->assertSame('specific-org-uuid', $result['_organisation']);
    }

    public function testFilterOrganisationMatchForCreateKeepsRegularConditions(): void
    {
        $match = [
            'status' => 'active',
            'type' => 'project',
        ];

        $result = $this->matcher->filterOrganisationMatchForCreate($match);

        $this->assertSame($match, $result);
    }

    public function testFilterOrganisationMatchForCreateWithEmptyMatch(): void
    {
        $result = $this->matcher->filterOrganisationMatchForCreate([]);

        $this->assertSame([], $result);
    }

    // --- Boolean and numeric matching ---

    public function testObjectMatchesWithBooleanValue(): void
    {
        $object = ['active' => true];
        $match = ['active' => true];

        $result = $this->matcher->objectMatchesConditions($object, $match);

        $this->assertTrue($result);
    }

    public function testObjectMatchesWithNumericValue(): void
    {
        $object = ['priority' => 5];
        $match = ['priority' => 5];

        $result = $this->matcher->objectMatchesConditions($object, $match);

        $this->assertTrue($result);
    }

    // --- Resolved relation unwrapping ---

    public function testObjectMatchesResolvedRelationViaIdKey(): void
    {
        // When a property has been expanded to its full related object,
        // the 'id' key must be unwrapped so rules can compare against the scalar id.
        $object = ['parent' => ['id' => 'uuid-123', 'name' => 'Parent']];
        $match  = ['parent' => 'uuid-123'];

        $this->assertTrue($this->matcher->objectMatchesConditions($object, $match));
    }

    public function testObjectMatchesResolvedRelationMismatch(): void
    {
        $object = ['parent' => ['id' => 'uuid-123', 'name' => 'Parent']];
        $match  = ['parent' => 'uuid-456'];

        $this->assertFalse($this->matcher->objectMatchesConditions($object, $match));
    }

    public function testObjectMatchesPlainArrayValueWithoutIdKeyStaysArray(): void
    {
        // Arrays without an 'id' key are NOT resolved relations — they stay as-is.
        // The comparison falls into the "null/array" branch below and returns true
        // (no null mismatch), which mirrors the pre-unification behaviour.
        $object = ['tags' => ['tag-1', 'tag-2']];
        $match  = ['tags' => 'tag-1'];

        // This is denied because an array-valued property does NOT equal a scalar
        // under strict comparison — the test documents the semantics explicitly.
        $this->assertFalse($this->matcher->objectMatchesConditions($object, $match));
    }
}
