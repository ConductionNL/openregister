<?php

/**
 * Store install ledger tests.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Store
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Store;

use OCA\OpenRegister\AppHost\Store\StoreInstallLedger;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The ledger keeps, per app, which item went into which target.
 *
 * @spec openspec/changes/store-installed-in/specs/apphost-store-plane/spec.md#requirement-the-store-reports-where-each-item-is-installed
 */
class StoreInstallLedgerTest extends TestCase {

	/**
	 * The fake config values, app id to key to value.
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $values = [];

	/**
	 * A ledger over an in-memory IAppConfig.
	 *
	 * @return StoreInstallLedger
	 */
	private function ledger(): StoreInstallLedger {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default=''): string => ($this->values[$app][$key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->values[$app][$key] = $value;
				return true;
			}
		);

		return new StoreInstallLedger(appConfig: $config, logger: $this->createMock(originalClassName: LoggerInterface::class));
	}//end ledger()

	/**
	 * A skill tree installed into two worlds is reported in both, once each.
	 *
	 * @return void
	 */
	public function testAnItemInstalledIntoTwoTargetsIsReportedInBoth(): void {
		$ledger = $this->ledger();
		$ledger->record(appId: 'larpinq', slug: 'fantasy-skills', target: 'world-1');
		$ledger->record(appId: 'larpinq', slug: 'fantasy-skills', target: 'world-2');
		$ledger->record(appId: 'larpinq', slug: 'fantasy-skills', target: 'world-1');

		$this->assertSame(expected: ['fantasy-skills' => ['world-1', 'world-2']], actual: $ledger->installedIn(appId: 'larpinq'));
	}//end testAnItemInstalledIntoTwoTargetsIsReportedInBoth()

	/**
	 * An install without a target is recorded as the empty target.
	 *
	 * @return void
	 */
	public function testAnInstallWithoutTargetIsTheEmptyTarget(): void {
		$ledger = $this->ledger();
		$ledger->record(appId: 'larpinq', slug: 'sheet-templates');

		$this->assertSame(expected: ['sheet-templates' => ['']], actual: $ledger->installedIn(appId: 'larpinq'));
	}//end testAnInstallWithoutTargetIsTheEmptyTarget()

	/**
	 * Each app reads only its own ledger.
	 *
	 * @return void
	 */
	public function testEachAppKeepsItsOwnLedger(): void {
		$ledger = $this->ledger();
		$ledger->record(appId: 'larpinq', slug: 'a-set', target: 'world-1');

		$this->assertSame(expected: [], actual: $ledger->installedIn(appId: 'dossiq'));
		$this->assertArrayHasKey(key: StoreInstallLedger::CONFIG_KEY, array: $this->values['larpinq']);
	}//end testEachAppKeepsItsOwnLedger()

	/**
	 * A slug or target that is not an id is never recorded.
	 *
	 * @return void
	 */
	public function testIdsThatAreNotIdsAreNotRecorded(): void {
		$ledger = $this->ledger();
		$ledger->record(appId: 'larpinq', slug: '../etc', target: 'world-1');
		$ledger->record(appId: 'larpinq', slug: 'a-set', target: 'world 1; drop');

		$this->assertSame(expected: [], actual: $ledger->installedIn(appId: 'larpinq'));
	}//end testIdsThatAreNotIdsAreNotRecorded()

	/**
	 * A stored value that is not a ledger reads as empty, and bad rows are skipped.
	 *
	 * @return void
	 */
	public function testAMangledValueReadsAsEmptyRowByRow(): void {
		$ledger = $this->ledger();
		$this->values['larpinq'][StoreInstallLedger::CONFIG_KEY] = 'not json';
		$this->assertSame(expected: [], actual: $ledger->installedIn(appId: 'larpinq'));

		$this->values['larpinq'][StoreInstallLedger::CONFIG_KEY] = (string)json_encode(
			['good' => ['world-1', 7, '../x'], '../bad' => ['world-1'], 'flat' => 'world-1']
		);
		$this->assertSame(expected: ['good' => ['world-1']], actual: $ledger->installedIn(appId: 'larpinq'));
	}//end testAMangledValueReadsAsEmptyRowByRow()

	/**
	 * Past the item cap the least recently installed item is dropped first.
	 *
	 * @return void
	 */
	public function testTheLeastRecentItemGoesFirstPastTheCap(): void {
		$ledger = $this->ledger();
		for ($i = 0; $i <= StoreInstallLedger::MAX_ITEMS; $i++) {
			$ledger->record(appId: 'larpinq', slug: 'item-'.$i);
		}

		$installed = $ledger->installedIn(appId: 'larpinq');
		$this->assertCount(expectedCount: StoreInstallLedger::MAX_ITEMS, haystack: $installed);
		$this->assertArrayNotHasKey(key: 'item-0', array: $installed);
		$this->assertArrayHasKey(key: 'item-'.StoreInstallLedger::MAX_ITEMS, array: $installed);
	}//end testTheLeastRecentItemGoesFirstPastTheCap()

	/**
	 * A failing write is logged, never thrown: the install already succeeded.
	 *
	 * @return void
	 */
	public function testAFailingWriteIsLoggedNotThrown(): void {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$config->method('setValueString')->willThrowException(exception: new RuntimeException(message: 'db down'));
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		(new StoreInstallLedger(appConfig: $config, logger: $logger))->record(appId: 'larpinq', slug: 'a-set', target: 'world-1');
	}//end testAFailingWriteIsLoggedNotThrown()
}//end class
