<?php

/**
 * Golden: what the seed version ledger reads, keeps and writes, pinned on
 * exact app-config calls.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
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

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Service\Configuration\SeedVersionLedger;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Configuration\SeedVersionLedger
 */
class SeedVersionLedgerGoldenTest extends TestCase {
	/**
	 * Stored maps are read once, non-text versions dropped, and only changed maps written, once each.
	 *
	 * @return void
	 */
	public function testReadRecordFlush(): void {
		$longApp = str_repeat('a', 60);
		$stored  = [
			'imported_seed_versions_app'             => '{"1:2:x":"1.0","1:2:y":2,"7":"0.1","1:2:z":null}',
			'imported_seed_versions_broken'          => 'not json',
			'imported_seed_versions_'.sha1($longApp) => '{"s":"9"}',
		];
		$reads  = [];
		$writes = [];
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(function (string $app, string $key, string $default, bool $lazy) use (&$reads, $stored) {
			$reads[] = [$app, $key, $default, $lazy];
			return $stored[$key] ?? $default;
		});
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value, bool $lazy) use (&$writes) {
			$writes[] = [$app, $key, $value, $lazy];
			return true;
		});
		$ledger = new SeedVersionLedger($config);

		$this->assertSame('1.0', $ledger->shipped('app', '1:2:x'));
		$this->assertNull($ledger->shipped('app', '1:2:y'));
		$this->assertNull($ledger->shipped('app', '1:2:z'));
		$this->assertSame('0.1', $ledger->shipped('app', '7'));
		$this->assertNull($ledger->shipped('broken', 'a'));
		$this->assertSame('9', $ledger->shipped($longApp, 's'));
		$this->assertNull($ledger->shipped('fresh', 'a'));

		$ledger->record('app', '1:2:x', '1.0');
		$ledger->record('broken', 'a', '1');
		$ledger->record('app', '1:2:w', '3');
		$ledger->record($longApp, 's', '9');
		$ledger->flush();
		$ledger->flush();

		$this->assertSame(
			[
				['openregister', 'imported_seed_versions_app', '', true],
				['openregister', 'imported_seed_versions_broken', '', true],
				['openregister', 'imported_seed_versions_'.sha1($longApp), '', true],
				['openregister', 'imported_seed_versions_fresh', '', true],
			],
			$reads
		);
		$this->assertSame(
			[
				['openregister', 'imported_seed_versions_broken', '{"a":"1"}', true],
				['openregister', 'imported_seed_versions_app', '{"1:2:x":"1.0","7":"0.1","1:2:w":"3"}', true],
			],
			$writes
		);
	}//end testReadRecordFlush()

	/**
	 * Another prefix keeps another map, and hashes past the key limit on its own length.
	 *
	 * @return void
	 */
	public function testPrefix(): void {
		$keys   = [];
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(function (string $app, string $key) use (&$keys) {
			$keys[] = $key;
			return '';
		});
		$ledger = new SeedVersionLedger($config, 'seed_hash_');
		$ledger->shipped(str_repeat('b', 54), 's');
		$ledger->shipped(str_repeat('b', 55), 's');

		$this->assertSame(['seed_hash_'.str_repeat('b', 54), 'seed_hash_'.sha1(str_repeat('b', 55))], $keys);
	}//end testPrefix()
}//end class
