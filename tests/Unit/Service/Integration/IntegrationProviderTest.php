<?php

/**
 * Tests for IntegrationProvider interface contract and AbstractIntegrationProvider defaults.
 *
 * @spec openspec/changes/integration-openproject/tasks.md#task-6
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use PHPUnit\Framework\TestCase;

/**
 * Concrete test implementation of AbstractIntegrationProvider.
 */
class ConcreteTestProvider extends AbstractIntegrationProvider
{
    public function getId(): string { return 'test'; }
    public function getLabel(): string { return 'Test Integration'; }
    public function getIcon(): string { return 'TestIcon'; }
    public function getGroup(): ?string { return 'builtin'; }
    public function isEnabled(): bool { return true; }

    public function getRequiredApp(): ?string { return null; }

    public function getStorageStrategy(): string { return 'local'; }

    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        return [];
    }
}

/**
 * Tests for AbstractIntegrationProvider default method implementations.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author   Conduction Development Team <info@conduction.nl>
 */
class IntegrationProviderTest extends TestCase
{

    private ConcreteTestProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new ConcreteTestProvider();
    }

    /**
     * @spec openspec/changes/integration-openproject/tasks.md#task-1
     */
    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(IntegrationProvider::class, $this->provider);
    }

    /**
     * @spec openspec/changes/integration-openproject/tasks.md#task-1
     */
    public function testGetIdReturnsConcreteValue(): void
    {
        $this->assertSame(expected: 'test', actual: $this->provider->getId());
    }

    /**
     * @spec openspec/changes/integration-openproject/tasks.md#task-1
     */
    public function testGetRequiredAppDefaultsToNull(): void
    {
        $this->assertNull($this->provider->getRequiredApp());
    }

    /**
     * @spec openspec/changes/integration-openproject/tasks.md#task-1
     */
    public function testGetStorageStrategyDefaultsToLocal(): void
    {
        $this->assertSame(expected: 'local', actual: $this->provider->getStorageStrategy());
    }

    /**
     * @spec openspec/changes/integration-openproject/tasks.md#task-1
     */
    public function testAuthRequirementsDefaultsToNone(): void
    {
        $this->assertSame(expected: ['type' => 'none'], actual: $this->provider->authRequirements());
    }

    /**
     * @spec openspec/changes/integration-openproject/tasks.md#task-1
     */
    public function testRequiresPermissionDefaultsToNull(): void
    {
        $this->assertNull($this->provider->requiresPermission());
    }

    /**
     * Default getOpenConnectorSource() returns null for non-external providers.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-1
     */
    public function testGetOpenConnectorSourceDefaultsToNull(): void
    {
        $this->assertNull($this->provider->getOpenConnectorSource());
    }

    /**
     * @spec openspec/changes/integration-openproject/tasks.md#task-1
     */
    public function testHealthDefaultsToOkConfigured(): void
    {
        $this->assertSame(
            expected: [
                'status'     => 'ok',
                'authStatus' => 'configured',
                'message'    => null,
            ],
            actual: $this->provider->health()
        );
    }

    /**
     * @spec openspec/changes/integration-openproject/tasks.md#task-1
     */
    public function testLabelAndIconAreAccessible(): void
    {
        $this->assertSame(expected: 'Test Integration', actual: $this->provider->getLabel());
        $this->assertSame(expected: 'TestIcon', actual: $this->provider->getIcon());
        $this->assertSame(expected: 'builtin', actual: $this->provider->getGroup());
        $this->assertTrue($this->provider->isEnabled());
    }

}//end class
