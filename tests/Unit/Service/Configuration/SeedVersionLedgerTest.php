<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Configuration
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Service\Configuration\SeedVersionLedger;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The shipped version of each seed is written once per import, not once per seed.
 *
 * @covers \OCA\OpenRegister\Service\Configuration\SeedVersionLedger
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
class SeedVersionLedgerTest extends TestCase {

	/**
	 * The ledger over an in-memory app config; writes are counted.
	 *
	 * @param array<string, string> $store  The stored values by key.
	 * @param int                   $writes The number of writes (by reference).
	 *
	 * @return SeedVersionLedger
	 */
	private function ledger(array &$store, int &$writes): SeedVersionLedger {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$store): string {
				return ($store[$key] ?? $default);
			}
		);
		$config->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$store, &$writes): bool {
				$store[$key] = $value;
				$writes++;

				return true;
			}
		);

		return new SeedVersionLedger($config);
	}//end ledger()

	/**
	 * A hundred seeds are one write, and only on flush; a recorded version reads back at once.
	 *
	 * @return void
	 */
	public function testManySeedsAreOneWriteOnFlush(): void {
		$store  = [];
		$writes = 0;
		$ledger = $this->ledger($store, $writes);

		for ($i = 0; $i < 100; $i++) {
			$ledger->record(appId: 'dossiq', seed: '1/2/seed-' . $i, version: '1.4.8');
		}

		$this->assertSame(0, $writes, 'nothing is written per seed');
		$this->assertSame('1.4.8', $ledger->shipped(appId: 'dossiq', seed: '1/2/seed-7'), 'the import reads its own records back');

		$ledger->flush();
		$this->assertSame(1, $writes);
		$this->assertCount(100, json_decode($store['imported_seed_versions_dossiq'], true));

		$ledger->flush();
		$this->assertSame(1, $writes, 'a second flush has nothing to write');
	}//end testManySeedsAreOneWriteOnFlush()

	/**
	 * A version that is already recorded is not a change; each app id is written once; a long app id is hashed.
	 *
	 * @return void
	 */
	public function testUnchangedVersionsAreNotWrittenAndEachAppIsItsOwnWrite(): void {
		$store  = ['imported_seed_versions_dossiq' => (string)json_encode(['1/2/a' => '1.0.0', 'bad' => 5])];
		$writes = 0;
		$ledger = $this->ledger($store, $writes);

		$ledger->record(appId: 'dossiq', seed: '1/2/a', version: '1.0.0');
		$ledger->flush();
		$this->assertSame(0, $writes);

		$ledger->record(appId: 'dossiq', seed: '1/2/a', version: '1.1.0');
		$ledger->record(appId: 'pipelinq', seed: '1/2/b', version: '2.0.0');
		$ledger->record(appId: str_repeat('x', 60), seed: '1/2/c', version: '3.0.0');
		$ledger->flush();

		$this->assertSame(3, $writes);
		$this->assertSame(['1/2/a' => '1.1.0'], json_decode($store['imported_seed_versions_dossiq'], true), 'a non-string version is not carried');
		$this->assertArrayHasKey('imported_seed_versions_' . sha1(str_repeat('x', 60)), $store);
	}//end testUnchangedVersionsAreNotWrittenAndEachAppIsItsOwnWrite()
}//end class
