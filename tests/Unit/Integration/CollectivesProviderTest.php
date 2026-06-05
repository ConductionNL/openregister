<?php

/**
 * Unit tests for CollectivesProvider.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Integration
 *
 * @spec openspec/changes/integration-collectives/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Integration;

use OCA\OpenRegister\Integration\CollectivesProvider;
use PHPUnit\Framework\TestCase;

class CollectivesProviderTest extends TestCase
{

    private CollectivesProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new CollectivesProvider();
    }//end setUp()

    public function testGetIdReturnsCollectives(): void
    {
        $this->assertSame('collectives', $this->provider->getId());
    }//end testGetIdReturnsCollectives()

    public function testGetLabelReturnsKnowledge(): void
    {
        $this->assertSame('Knowledge', $this->provider->getLabel());
    }//end testGetLabelReturnsKnowledge()

    public function testGetIconReturnsBookOpenPageVariant(): void
    {
        $this->assertSame('BookOpenPageVariant', $this->provider->getIcon());
    }//end testGetIconReturnsBookOpenPageVariant()

    public function testGetGroupReturnsDocs(): void
    {
        $this->assertSame('docs', $this->provider->getGroup());
    }//end testGetGroupReturnsDocs()

    public function testGetRequiredAppReturnsCollectives(): void
    {
        $this->assertSame('collectives', $this->provider->getRequiredApp());
    }//end testGetRequiredAppReturnsCollectives()

    public function testGetStorageStrategyReturnsLinkTable(): void
    {
        $this->assertSame('link-table', $this->provider->getStorageStrategy());
    }//end testGetStorageStrategyReturnsLinkTable()

    public function testRequiresPermissionReturnsNull(): void
    {
        $this->assertNull($this->provider->requiresPermission());
    }//end testRequiresPermissionReturnsNull()
}//end class
