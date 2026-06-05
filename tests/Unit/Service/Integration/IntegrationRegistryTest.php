<?php

/**
 * Tests for IntegrationRegistry.
 *
 * @spec openspec/changes/integration-openproject/tasks.md#task-6
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the pluggable IntegrationRegistry.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author   Conduction Development Team <info@conduction.nl>
 */
class IntegrationRegistryTest extends TestCase
{

    private LoggerInterface&MockObject $logger;
    private IntegrationRegistry $registry;

    protected function setUp(): void
    {
        $this->logger   = $this->createMock(LoggerInterface::class);
        $this->registry = new IntegrationRegistry(logger: $this->logger);
    }

    /**
     * @spec openspec/changes/integration-openproject/tasks.md#task-4
     */
    public function testRegisterAndGetProvider(): void
    {
        $provider = $this->createTestProvider(id: 'test', enabled: true);
        $this->registry->register(provider: $provider);

        $retrieved = $this->registry->get(id: 'test');
        $this->assertSame(expected: $provider, actual: $retrieved);
    }

    /**
     * @spec openspec/changes/integration-openproject/tasks.md#task-4
     */
    public function testGetReturnsNullForUnknownId(): void
    {
        $this->assertNull($this->registry->get(id: 'nonexistent'));
    }

    /**
     * @spec openspec/changes/integration-openproject/tasks.md#task-4
     */
    public function testGetEnabledFiltersDisabled(): void
    {
        $enabledProvider  = $this->createTestProvider(id: 'enabled', enabled: true);
        $disabledProvider = $this->createTestProvider(id: 'disabled', enabled: false);

        $this->registry->register(provider: $enabledProvider);
        $this->registry->register(provider: $disabledProvider);

        $enabled = $this->registry->getEnabled();
        $this->assertCount(expectedCount: 1, haystack: $enabled);
        $this->assertSame(expected: 'enabled', actual: $enabled[0]->getId());
    }

    /**
     * @spec openspec/changes/integration-openproject/tasks.md#task-4
     */
    public function testListIdsReturnsAllIds(): void
    {
        $this->registry->register(provider: $this->createTestProvider(id: 'alpha', enabled: true));
        $this->registry->register(provider: $this->createTestProvider(id: 'beta', enabled: false));

        $ids = $this->registry->listIds();
        $this->assertContains(needle: 'alpha', haystack: $ids);
        $this->assertContains(needle: 'beta', haystack: $ids);
        $this->assertCount(expectedCount: 2, haystack: $ids);
    }

    /**
     * @spec openspec/changes/integration-openproject/tasks.md#task-4
     */
    public function testGetCapabilitiesIncludesAuthStatus(): void
    {
        $provider = $this->createTestProvider(id: 'test', enabled: true, health: 'expired');
        $this->registry->register(provider: $provider);

        $caps = $this->registry->getCapabilities();

        $this->assertArrayHasKey(key: 'test', array: $caps);
        $this->assertSame(expected: 'expired', actual: $caps['test']['authStatus']);
    }

    /**
     * @spec openspec/changes/integration-openproject/tasks.md#task-4
     */
    public function testDuplicateRegistrationLogsWarning(): void
    {
        $this->logger->expects($this->once())->method('warning');

        $p1 = $this->createTestProvider(id: 'dup', enabled: true);
        $p2 = $this->createTestProvider(id: 'dup', enabled: true);

        $this->registry->register(provider: $p1);
        $this->registry->register(provider: $p2);

        // The second registration should overwrite.
        $this->assertSame(expected: $p2, actual: $this->registry->get(id: 'dup'));
    }

    /**
     * @spec openspec/changes/integration-openproject/tasks.md#task-4
     */
    public function testGetEnabledEmptyWhenNoneRegistered(): void
    {
        $this->assertEmpty($this->registry->getEnabled());
    }

    /**
     * Helper: create a mock IntegrationProvider.
     *
     * @param string $id      Integration id.
     * @param bool   $enabled Whether isEnabled() returns true.
     * @param string $health  Health status string.
     *
     * @return AbstractIntegrationProvider&MockObject
     */
    private function createTestProvider(
        string $id,
        bool $enabled,
        string $health = 'available'
    ): AbstractIntegrationProvider&MockObject {
        $mock = $this->getMockBuilder(AbstractIntegrationProvider::class)
            ->onlyMethods(['getId', 'getLabel', 'getIcon', 'getGroup', 'isEnabled', 'health'])
            ->getMock();

        $mock->method('getId')->willReturn($id);
        $mock->method('getLabel')->willReturn(ucfirst(string: $id));
        $mock->method('getIcon')->willReturn('Icon');
        $mock->method('getGroup')->willReturn('test');
        $mock->method('isEnabled')->willReturn($enabled);
        $mock->method('health')->willReturn($health);

        return $mock;
    }

}//end class
