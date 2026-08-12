<?php

declare(strict_types=1);

namespace Unit\Service\Integration;

use OCA\OpenRegister\Service\Integration\TimeProvider;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TimeProvider.
 *
 * @spec openspec/changes/integration-time-tracker/tasks.md#task-4
 */
class TimeProviderTest extends TestCase {

	private IAppConfig&MockObject $appConfig;
	private TimeProvider $provider;

	protected function setUp(): void {
		parent::setUp();

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->provider = new TimeProvider(appConfig: $this->appConfig);
	}//end setUp()

	public function testGetIdReturnsTimeTracker(): void {
		$this->assertSame('time-tracker', $this->provider->getId());
	}//end testGetIdReturnsTimeTracker()

	public function testGetLabelReturnsTime(): void {
		$this->assertSame('Time', $this->provider->getLabel());
	}//end testGetLabelReturnsTime()

	public function testGetIconReturnsClock(): void {
		$this->assertSame('Clock', $this->provider->getIcon());
	}//end testGetIconReturnsClock()

	public function testGetGroupReturnsWorkflow(): void {
		$this->assertSame('workflow', $this->provider->getGroup());
	}//end testGetGroupReturnsWorkflow()

	public function testGetStorageStrategyReturnsLinkTable(): void {
		$this->assertSame('link-table', $this->provider->getStorageStrategy());
	}//end testGetStorageStrategyReturnsLinkTable()

	public function testRequiresPermissionReturnsNull(): void {
		$this->assertNull($this->provider->requiresPermission());
	}//end testRequiresPermissionReturnsNull()

	public function testGetRequiredAppUsesAdminSetting(): void {
		$this->appConfig->method('getValueString')
			->with('openregister', 'time-tracker.backend', 'timemanager')
			->willReturn('my-time-app');

		$this->assertSame('my-time-app', $this->provider->getRequiredApp());
	}//end testGetRequiredAppUsesAdminSetting()

	public function testGetRequiredAppDefaultsToTimemanager(): void {
		$this->appConfig->method('getValueString')
			->willReturn('timemanager');

		$this->assertSame('timemanager', $this->provider->getRequiredApp());
	}//end testGetRequiredAppDefaultsToTimemanager()
}//end class
