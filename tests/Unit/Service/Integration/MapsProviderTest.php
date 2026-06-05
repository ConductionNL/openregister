<?php

declare(strict_types=1);

namespace Unit\Service\Integration;

use OCA\OpenRegister\Service\Integration\MapsProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MapsProvider.
 */
class MapsProviderTest extends TestCase
{

    private MapsProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new MapsProvider();
    }//end setUp()

    public function testGetIdReturnsMaps(): void
    {
        $this->assertSame('maps', $this->provider->getId());
    }//end testGetIdReturnsMaps()

    public function testGetLabelReturnsLocation(): void
    {
        $this->assertSame('Location', $this->provider->getLabel());
    }//end testGetLabelReturnsLocation()

    public function testGetRequiredAppReturnsMaps(): void
    {
        $this->assertSame('maps', $this->provider->getRequiredApp());
    }//end testGetRequiredAppReturnsMaps()

    public function testGetStorageStrategyReturnsLinkTable(): void
    {
        $this->assertSame('link-table', $this->provider->getStorageStrategy());
    }//end testGetStorageStrategyReturnsLinkTable()

    public function testRequiresPermissionReturnsNull(): void
    {
        $this->assertNull($this->provider->requiresPermission());
    }//end testRequiresPermissionReturnsNull()

    public function testToArrayContainsAllRequiredKeys(): void
    {
        $data = $this->provider->toArray();

        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('label', $data);
        $this->assertArrayHasKey('icon', $data);
        $this->assertArrayHasKey('group', $data);
        $this->assertArrayHasKey('requiredApp', $data);
        $this->assertArrayHasKey('storageStrategy', $data);
        $this->assertArrayHasKey('requiresPermission', $data);
        $this->assertSame('maps', $data['id']);
        $this->assertNull($data['requiresPermission']);
    }//end testToArrayContainsAllRequiredKeys()

    public function testConstantsMatchMethods(): void
    {
        $this->assertSame(MapsProvider::ID, $this->provider->getId());
        $this->assertSame(MapsProvider::LABEL, $this->provider->getLabel());
        $this->assertSame(MapsProvider::ICON, $this->provider->getIcon());
        $this->assertSame(MapsProvider::GROUP, $this->provider->getGroup());
        $this->assertSame(MapsProvider::REQUIRED_APP, $this->provider->getRequiredApp());
        $this->assertSame(MapsProvider::STORAGE_STRATEGY, $this->provider->getStorageStrategy());
    }//end testConstantsMatchMethods()
}//end class
