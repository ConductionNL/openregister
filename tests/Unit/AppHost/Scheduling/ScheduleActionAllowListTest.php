<?php

/**
 * AppHost scheduling — action allow-list tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Scheduling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Scheduling;

use OCA\OpenRegister\AppHost\Scheduling\ScheduleActionAllowList;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * Covers the closed action → vetted jobClass map.
 *
 * @covers \OCA\OpenRegister\AppHost\Scheduling\ScheduleActionAllowList
 * @covers \OCA\OpenRegister\Support\FleetAppId
 */
class ScheduleActionAllowListTest extends TestCase {
	private ScheduleActionAllowList $allowList;

	protected function setUp(): void {
		$this->allowList = $this->allowListFor(['integriq']);
	}

	/**
	 * Build the allow-list against an instance carrying exactly these app ids.
	 *
	 * @param list<string> $installed Ids this fake instance has.
	 *
	 * @return ScheduleActionAllowList The configured allow-list.
	 */
	private function allowListFor(array $installed): ScheduleActionAllowList {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			static fn (string $id): bool => in_array($id, $installed, true)
		);

		return new ScheduleActionAllowList($appManager);
	}

	public function testVettedActionResolvesToServerClass(): void {
		$this->assertTrue($this->allowList->isAllowed('openconnector:synchronization'));
		$this->assertSame(
			'OCA\\Integriq\\Action\\SynchronizationAction',
			$this->allowList->resolve('openconnector:synchronization')
		);
	}

	/**
	 * The jobClass follows the id the instance ACTUALLY has, not the newest.
	 *
	 * This is the assertion that separates a fix from a hard swap: an instance
	 * still on the connector's beta/main release registers `openconnector` and
	 * loads `OCA\OpenConnector\…`. Handing its JobService the `OCA\Integriq`
	 * name would be the same silent miss as before, pointed the other way.
	 *
	 * @return void
	 */
	public function testVettedActionFollowsTheInstalledConnectorId(): void {
		$allowList = $this->allowListFor(['openconnector']);

		$this->assertSame(
			'OCA\\OpenConnector\\Action\\SynchronizationAction',
			$allowList->resolve('openconnector:synchronization')
		);
	}

	/**
	 * The manifest-facing ACTION KEY is unchanged by the namespace rename.
	 *
	 * Leaf apps ship `action: 'openconnector:synchronization'` in their own
	 * manifests. Renaming that key would un-allow-list every schedule already
	 * declared against it, so it is pinned here deliberately.
	 *
	 * @return void
	 */
	public function testTheManifestActionKeyIsUnchanged(): void {
		$this->assertTrue($this->allowListFor(['integriq'])->isAllowed('openconnector:synchronization'));
		$this->assertFalse($this->allowListFor(['integriq'])->isAllowed('integriq:synchronization'));
	}

	/**
	 * With no connector installed the canonical spelling is returned.
	 *
	 * @return void
	 */
	public function testResolvesToTheCanonicalNameWhenNoConnectorIsInstalled(): void {
		$this->assertSame(
			'OCA\\Integriq\\Action\\SynchronizationAction',
			$this->allowListFor([])->resolve('openconnector:synchronization')
		);
	}

	public function testNonAllowListedActionIsRejected(): void {
		$this->assertFalse($this->allowList->isAllowed('openconnector:unknown'));
		$this->assertNull($this->allowList->resolve('openconnector:unknown'));
	}

	public function testRawFqcnIsNeverResolved(): void {
		// A manifest-supplied FQCN must never be usable as a jobClass.
		$this->assertNull($this->allowList->resolve('OCA\\Evil\\Backdoor'));
		$this->assertFalse($this->allowList->isAllowed('OCA\\OpenConnector\\Action\\SynchronizationAction'));
	}
}//end class
