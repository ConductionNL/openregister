<?php

/**
 * Unit tests for ActivityProvider after Phase B-3 payload widening.
 *
 * The widened `list()` flattens activity rows so `CnActivityTab` can
 * read `type`, `timestamp`, `affecteduser`, and `subject` at the leaf
 * row root rather than walking the nested `data` envelope. This test
 * stubs the trait-level `findByMarker()` via a test subclass and
 * asserts the contract.
 *
 * Metadata + isEnabled are exercised by the leaf-providers metadata
 * aggregator test; this file isolates the regression surface
 * introduced by the widening commit.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/integration-activity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Integration\Providers\ActivityProvider;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Test double that injects pre-baked rows in place of the trait's
 * actual `findByMarker()` DB call.
 */
class TestableActivityProvider extends ActivityProvider {

	/**
	 * Pre-baked rows returned in place of the trait DB call.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $stubRows = [];

	protected function findByMarker(
		IDBConnection $db,
		string $table,
		string $markerColumn,
		string $marker,
		array $extraColumns = [],
		string $idColumn = 'id',
	): array {
		return $this->stubRows;
	}//end findByMarker()
}//end class

/**
 * Unit tests for ActivityProvider.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ActivityProviderTest extends TestCase {
	private function buildProvider(bool $installed = true): TestableActivityProvider {
		$db = $this->createMock(IDBConnection::class);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn($installed);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		return new TestableActivityProvider(db: $db, appManager: $apps, l10n: $l10n);
	}//end buildProvider()

	public function testListEmptyWhenAppMissing(): void {
		$provider = $this->buildProvider(installed: false);
		$provider->stubRows = [
			['activity_id' => 1, 'subject' => 'x [or:uuid]', 'type' => 'files', 'timestamp' => 100, 'affecteduser' => 'a', 'object_id' => 'uuid'],
		];
		self::assertSame([], $provider->list(register: 'r', schema: 's', objectId: 'uuid'));
	}//end testListEmptyWhenAppMissing()

	public function testListWidenedPayloadFlattensFields(): void {
		$provider = $this->buildProvider();
		$provider->stubRows = [
			[
				'activity_id' => 17,
				'subject' => 'admin uploaded file.pdf [or:uuid-1]',
				'type' => 'files',
				'timestamp' => 1779002021,
				'affecteduser' => 'alice',
				'object_id' => 'uuid-1',
			],
			[
				'activity_id' => 18,
				'subject' => 'admin updated decision [or:uuid-1]',
				'type' => 'or:decision',
				'timestamp' => 1779002099,
				'affecteduser' => 'bob',
				'object_id' => 'uuid-1',
			],
		];

		$rows = $provider->list(register: 'r', schema: 's', objectId: 'uuid-1');
		self::assertCount(2, $rows);

		// Row 1 — verify all flattened fields.
		$r0 = $rows[0];
		self::assertSame('17', $r0['id']);
		self::assertSame('admin uploaded file.pdf [or:uuid-1]', $r0['subject']);
		self::assertSame('admin uploaded file.pdf [or:uuid-1]', $r0['title']);
		self::assertSame('files', $r0['type']);
		self::assertSame(1779002021, $r0['timestamp']);
		self::assertSame('alice', $r0['affecteduser']);
		self::assertSame('alice', $r0['actor_id']);
		self::assertSame('uuid-1', $r0['object_id']);
		self::assertSame('/index.php/apps/activity/17', $r0['url']);
		// `data` envelope is retained for generic consumers.
		self::assertIsArray($r0['data']);
		self::assertSame('files', $r0['data']['type']);

		$r1 = $rows[1];
		self::assertSame('or:decision', $r1['type']);
		self::assertSame('bob', $r1['actor_id']);
	}//end testListWidenedPayloadFlattensFields()

	public function testListFiltersByType(): void {
		$provider = $this->buildProvider();
		$provider->stubRows = [
			['activity_id' => 1, 'subject' => 'a [or:u]', 'type' => 'files', 'timestamp' => 100, 'affecteduser' => 'alice', 'object_id' => 'u'],
			['activity_id' => 2, 'subject' => 'b [or:u]', 'type' => 'or:decision', 'timestamp' => 200, 'affecteduser' => 'bob', 'object_id' => 'u'],
		];

		$rows = $provider->list(register: 'r', schema: 's', objectId: 'u', filters: ['type' => 'or:decision']);
		self::assertCount(1, $rows);
		self::assertSame('or:decision', $rows[0]['type']);
	}//end testListFiltersByType()

	public function testListFiltersByActor(): void {
		$provider = $this->buildProvider();
		$provider->stubRows = [
			['activity_id' => 1, 'subject' => 'a [or:u]', 'type' => 'files', 'timestamp' => 100, 'affecteduser' => 'alice', 'object_id' => 'u'],
			['activity_id' => 2, 'subject' => 'b [or:u]', 'type' => 'files', 'timestamp' => 200, 'affecteduser' => 'bob', 'object_id' => 'u'],
		];

		$rows = $provider->list(register: 'r', schema: 's', objectId: 'u', filters: ['actor' => 'bob']);
		self::assertCount(1, $rows);
		self::assertSame('bob', $rows[0]['actor_id']);
	}//end testListFiltersByActor()

	public function testListFiltersByAfterTimestamp(): void {
		$provider = $this->buildProvider();
		$provider->stubRows = [
			['activity_id' => 1, 'subject' => 'old [or:u]', 'type' => 'files', 'timestamp' => 100, 'affecteduser' => 'alice', 'object_id' => 'u'],
			['activity_id' => 2, 'subject' => 'new [or:u]', 'type' => 'files', 'timestamp' => 500, 'affecteduser' => 'bob', 'object_id' => 'u'],
		];

		$rows = $provider->list(register: 'r', schema: 's', objectId: 'u', filters: ['after' => 300]);
		self::assertCount(1, $rows);
		self::assertSame(500, $rows[0]['timestamp']);
	}//end testListFiltersByAfterTimestamp()

	public function testListHandlesEmptyResult(): void {
		$provider = $this->buildProvider();
		$provider->stubRows = [];
		self::assertSame([], $provider->list(register: 'r', schema: 's', objectId: 'uuid'));
	}//end testListHandlesEmptyResult()

	public function testHealthReportsOk(): void {
		$provider = $this->buildProvider();
		$h = $provider->health();
		self::assertSame('ok', $h['status']);
		self::assertSame('configured', $h['authStatus']);
		self::assertNull($h['message']);
	}//end testHealthReportsOk()

	public function testHealthReportsUnavailable(): void {
		$provider = $this->buildProvider(installed: false);
		$h = $provider->health();
		self::assertSame('unavailable', $h['status']);
		self::assertNotNull($h['message']);
	}//end testHealthReportsUnavailable()
}//end class
