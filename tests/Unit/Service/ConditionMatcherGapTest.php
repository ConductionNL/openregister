<?php

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\OperatorEvaluator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Gap tests for ConditionMatcher covering uncovered branches.
 */
class ConditionMatcherGapTest extends TestCase
{
    private IUserSession&MockObject $userSession;
    private ContainerInterface&MockObject $container;
    private OperatorEvaluator&MockObject $operatorEvaluator;
    private LoggerInterface&MockObject $logger;
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

    /**
     * Test $organisation variable resolves via OrganisationService.
     */
    public function testOrganisationVariableResolvesViaService(): void
    {
        // Create a mock organisation object
        $orgMock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getUuid'])
            ->getMock();
        $orgMock->method('getUuid')->willReturn('org-uuid-123');

        $orgServiceMock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getActiveOrganisation'])
            ->getMock();
        $orgServiceMock->method('getActiveOrganisation')->willReturn($orgMock);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\OrganisationService')
            ->willReturn($orgServiceMock);

        $object = ['_organisation' => 'org-uuid-123'];
        $match = ['_organisation' => '$organisation'];

        // First call resolves; second would use cache.
        $result = $this->matcher->objectMatchesConditions($object, $match);
        $this->assertTrue($result);

        // Second call uses cached value
        $result2 = $this->matcher->objectMatchesConditions($object, $match);
        $this->assertTrue($result2);
    }

    /**
     * Test $activeOrganisation variable (alias for $organisation).
     */
    public function testActiveOrganisationVariableAlias(): void
    {
        $orgMock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getUuid'])
            ->getMock();
        $orgMock->method('getUuid')->willReturn('org-uuid-456');

        $orgServiceMock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getActiveOrganisation'])
            ->getMock();
        $orgServiceMock->method('getActiveOrganisation')->willReturn($orgMock);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\OrganisationService')
            ->willReturn($orgServiceMock);

        $object = ['_organisation' => 'org-uuid-456'];
        $match = ['_organisation' => '$activeOrganisation'];

        $result = $this->matcher->objectMatchesConditions($object, $match);
        $this->assertTrue($result);
    }

    /**
     * Test $organisation when service returns null (covers null branch).
     */
    public function testOrganisationVariableReturnsNullWhenNoActiveOrg(): void
    {
        $orgServiceMock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getActiveOrganisation'])
            ->getMock();
        $orgServiceMock->method('getActiveOrganisation')->willReturn(null);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\OrganisationService')
            ->willReturn($orgServiceMock);

        $object = ['_organisation' => 'some-org'];
        $match = ['_organisation' => '$organisation'];

        // $organisation resolves to null, differs from original value => false
        $result = $this->matcher->objectMatchesConditions($object, $match);
        $this->assertFalse($result);
    }

    /**
     * Test $organisation when service throws exception (covers exception branch).
     */
    public function testOrganisationVariableExceptionReturnsNull(): void
    {
        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\OrganisationService')
            ->willThrowException(new \Exception('Service unavailable'));

        $this->logger->expects($this->atLeastOnce())
            ->method('debug');

        $object = ['_organisation' => 'some-org'];
        $match = ['_organisation' => '$organisation'];

        $result = $this->matcher->objectMatchesConditions($object, $match);
        $this->assertFalse($result);
    }

    /**
     * Test filterOrganisationMatchForCreate with non-string value for org key.
     */
    public function testFilterOrganisationMatchForCreateNonStringValue(): void
    {
        $match = [
            '_organisation' => ['$gt' => 5], // Array value, not string
            'status' => 'active',
        ];

        $result = $this->matcher->filterOrganisationMatchForCreate($match);

        // Non-string org values are NOT filtered out.
        $this->assertArrayHasKey('_organisation', $result);
        $this->assertArrayHasKey('status', $result);
    }

    /**
     * Test condition with non-underscore property that doesn't match @self.
     */
    public function testNonUnderscorePropertyMissing(): void
    {
        $object = ['status' => 'active'];
        $match = ['missing_field' => 'value'];

        // missing_field doesn't start with _, so @self lookup is skipped
        // objectValue is null, resolvedValue is 'value', so false
        $result = $this->matcher->objectMatchesConditions($object, $match);
        $this->assertFalse($result);
    }
}
