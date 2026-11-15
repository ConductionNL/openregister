<?php

/**
 * Unit tests for ImportProgress.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Import\RecordImport
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/widget-record-import/tasks.md#5.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Import\RecordImport;

use OCA\OpenRegister\Service\Import\RecordImport\ImportProgress;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Import\RecordImport\ImportProgress
 */
class ImportProgressTest extends TestCase {

	/** @var array<string, array{0: mixed, 1: int}> */
	private array $stored = [];

	private function progress(bool $available = true): ImportProgress {
		$cache = $this->createMock(ICache::class);
		$cache->method('set')->willReturnCallback(function (string $key, mixed $value, int $ttl = 0): bool {
			$this->stored[$key] = [$value, $ttl];
			return true;
		});
		$cache->method('get')->willReturnCallback(fn (string $key): mixed => ($this->stored[$key][0] ?? null));
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn($available);
		$factory->expects($available ? $this->once() : $this->never())->method('createDistributed')
			->with('openregister_record_import_progress')->willReturn($cache);

		return new ImportProgress($factory);
	}

	public function testAReportIsReadBackWithAnHourToLive(): void {
		$progress = $this->progress();

		$progress->report(key: 'k', rows: 250);
		$this->assertSame(['rows' => 250, 'done' => false], $progress->read(key: 'k'));
		$this->assertSame(3600, $this->stored['k'][1]);

		$progress->report(key: 'k', rows: 300, done: true);
		$this->assertSame(['rows' => 300, 'done' => true], $progress->read(key: 'k'));
	}

	public function testNothingReportedReadsAsNull(): void {
		$progress = $this->progress();
		$this->stored['broken'] = ['not an entry', 0];

		$this->assertNull($progress->read(key: 'missing'));
		$this->assertNull($progress->read(key: 'broken'));
	}

	public function testWithoutACacheReportsAreDroppedAndReadAsNull(): void {
		$progress = $this->progress(available: false);

		$progress->report(key: 'k', rows: 10, done: true);

		$this->assertNull($progress->read(key: 'k'));
		$this->assertSame([], $this->stored);
	}

	public function testTheKeyBindsTheTokenToTheUser(): void {
		$alice = ImportProgress::key(userId: 'alice', token: 'abc-DEF_123');

		$this->assertSame(hash('sha256', 'alice') . ':abc-DEF_123', $alice);
		$this->assertNotSame($alice, ImportProgress::key(userId: 'bob', token: 'abc-DEF_123'));
	}

	public function testAnInvalidTokenOrMissingUserHasNoKey(): void {
		$this->assertNull(ImportProgress::key(userId: 'alice', token: 'short'));
		$this->assertNull(ImportProgress::key(userId: 'alice', token: str_repeat('a', 65)));
		$this->assertNull(ImportProgress::key(userId: 'alice', token: 'has a space'));
		$this->assertNull(ImportProgress::key(userId: 'alice', token: '../../etc'));
		$this->assertNull(ImportProgress::key(userId: '', token: 'abcdefgh'));
		$this->assertNotNull(ImportProgress::key(userId: 'alice', token: str_repeat('a', 64)));
	}
}
