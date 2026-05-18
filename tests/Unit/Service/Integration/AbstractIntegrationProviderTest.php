<?php

/**
 * Unit tests for AbstractIntegrationProvider defaults.
 *
 * Covers:
 *  - default getGroup / requiresPermission / authRequirements /
 *    getOpenConnectorSource shapes
 *  - get / create / update / delete throw NotImplementedException
 *  - health() returns the documented healthy descriptor
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

use OCA\OpenRegister\Exception\NotImplementedException;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use PHPUnit\Framework\TestCase;

/**
 * Concrete provider with only the abstract metadata overrides.
 */
class _MinimalProvider extends AbstractIntegrationProvider
{

    public function getId(): string
    {
        return 'minimal';
    }//end getId()

    public function getLabel(): string
    {
        return 'Minimal';
    }//end getLabel()

    public function getIcon(): string
    {
        return 'Cube';
    }//end getIcon()

    public function getRequiredApp(): ?string
    {
        return null;
    }//end getRequiredApp()

    public function getStorageStrategy(): string
    {
        return 'magic-column';
    }//end getStorageStrategy()

    public function isEnabled(): bool
    {
        return true;
    }//end isEnabled()

    public function list(string $register, string $schema, string $objectId, array $filters = []): array
    {
        return [];
    }//end list()

}//end class

/**
 * Unit tests for AbstractIntegrationProvider.
 */
class AbstractIntegrationProviderTest extends TestCase
{

    private _MinimalProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new _MinimalProvider();
    }//end setUp()

    public function testGroupDefaultsToNull(): void
    {
        $this->assertNull($this->provider->getGroup());
    }//end testGroupDefaultsToNull()

    public function testRequiresPermissionDefaultsToNull(): void
    {
        $this->assertNull($this->provider->requiresPermission());
    }//end testRequiresPermissionDefaultsToNull()

    public function testAuthRequirementsDefaultsToNone(): void
    {
        $this->assertSame(['type' => 'none'], $this->provider->authRequirements());
    }//end testAuthRequirementsDefaultsToNone()

    public function testOpenConnectorSourceDefaultsToNull(): void
    {
        $this->assertNull($this->provider->getOpenConnectorSource());
    }//end testOpenConnectorSourceDefaultsToNull()

    public function testGetThrowsNotImplemented(): void
    {
        $this->expectException(NotImplementedException::class);
        $this->provider->get('r', 's', 'o', 'e');
    }//end testGetThrowsNotImplemented()

    public function testCreateThrowsNotImplemented(): void
    {
        $this->expectException(NotImplementedException::class);
        $this->provider->create('r', 's', 'o', []);
    }//end testCreateThrowsNotImplemented()

    public function testUpdateThrowsNotImplemented(): void
    {
        $this->expectException(NotImplementedException::class);
        $this->provider->update('r', 's', 'o', 'e', []);
    }//end testUpdateThrowsNotImplemented()

    public function testDeleteThrowsNotImplemented(): void
    {
        $this->expectException(NotImplementedException::class);
        $this->provider->delete('r', 's', 'o', 'e');
    }//end testDeleteThrowsNotImplemented()

    public function testHealthReturnsHealthyDescriptor(): void
    {
        $health = $this->provider->health();
        $this->assertSame('ok', $health['status']);
        $this->assertSame('configured', $health['authStatus']);
        $this->assertNull($health['message']);
    }//end testHealthReturnsHealthyDescriptor()

}//end class
