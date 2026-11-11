<?php

/**
 * Golden test: the audit query pages, pair resolution and filters
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
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

namespace OCA\OpenRegister\Tests\Unit\Service;

use DateTime;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\AuditQueryService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;

/**
 * Pins, over a small fixed store, which register/schema pairs each filter
 * combination searches (explicit pair, explicit schema on every register
 * that has it, register only, nothing: audit-named schemas only; missing
 * register, schema or referenced register), the exact search calls (RBAC
 * and multitenancy left at their defaults), skipped pairs, the timestamp
 * bounds, and the page shape.
 */
class AuditQueryServicePageGoldenTest extends TestCase {

	/** @var array<int, string> The searches made, as "register/schema filters #args". */
	private array $searches = [];

	/**
	 * The service over the fixed store.
	 *
	 * @return AuditQueryService
	 */
	private function service(): AuditQueryService {
		$registers = ['r1' => $this->register(1, 'r1'), 'r2' => $this->register(2, 'r2'), 'r3' => $this->register(3, 'r3')];
		$byId = [1 => $registers['r1'], 2 => $registers['r2'], 3 => $registers['r3']];
		$schemas = [
			'auditLog' => $this->schema(10, 'auditLog', 'Log'),
			'zaak' => $this->schema(11, 'zaak', 'Zaak'),
			'trail' => $this->schema(12, 'trail', 'Audit Trail'),
			'broken' => $this->schema(13, 'broken', 'audit broken'),
			'counted' => $this->schema(14, 'counted-audit', ''),
		];

		$registerMapper = $this->createMock(RegisterMapperInterface::class);
		$registerMapper->method('find')->willReturnCallback(
			function (string|int $id) use ($registers, $byId): Register {
				$found = ($registers[$id] ?? $byId[$id] ?? null);
				if ($found === null) {
					throw new DoesNotExistException('no register ' . $id);
				}

				return $found;
			}
		);
		$registerMapper->method('findAll')->willReturn(array_values($registers));
		$registerMapper->method('getSchemasByRegisterId')->willReturnCallback(
			fn (int $id): array => [
				1 => [$schemas['auditLog'], $schemas['zaak']],
				2 => [$schemas['trail'], $schemas['broken'], $schemas['counted']],
				3 => [],
			][$id]
		);
		$registerMapper->method('getAllRegisterIdsWithSchema')->willReturnCallback(
			fn (int $schemaId): array => [10 => [1, 99, 2], 11 => [1]][$schemaId] ?? []
		);

		$schemaMapper = $this->createMock(SchemaMapperInterface::class);
		$schemaMapper->method('find')->willReturnCallback(
			function (string|int $id) use ($schemas): Schema {
				if (isset($schemas[$id]) === false) {
					throw new DoesNotExistException('no schema ' . $id);
				}

				return $schemas[$id];
			}
		);

		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('searchObjectsBySlug')->willReturnCallback(
			function (string $registerSlug, string $schemaSlug, array $filters = [], bool $_rbac = true, bool $_multitenancy = true) {
				$this->searches[] = $registerSlug . '/' . $schemaSlug . ' ' . json_encode($filters) . ' #' . (int)$_rbac . (int)$_multitenancy;
				if ($schemaSlug === 'broken') {
					throw new DoesNotExistException('gone');
				}

				if ($schemaSlug === 'counted-audit') {
					return 3;
				}

				return [
					$this->entry($registerSlug . '-' . $schemaSlug . '-old', '2026-01-01T00:00:00+00:00'),
					$this->entry($registerSlug . '-' . $schemaSlug . '-new', '2026-06-01T00:00:00+00:00'),
				];
			}
		);

		return new AuditQueryService($objects, new SchemaCatalog(registers: $registerMapper, schemas: $schemaMapper, tables: $this->createMock(MagicMapperInterface::class)));
	}//end service()

	/**
	 * A register.
	 *
	 * @param int    $id   The id.
	 * @param string $slug The slug.
	 *
	 * @return Register
	 */
	private function register(int $id, string $slug): Register {
		$register = new Register();
		$register->setId($id);
		$register->setSlug($slug);
		return $register;
	}//end register()

	/**
	 * A schema.
	 *
	 * @param int    $id    The id.
	 * @param string $slug  The slug.
	 * @param string $title The title.
	 *
	 * @return Schema
	 */
	private function schema(int $id, string $slug, string $title): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		$schema->setTitle($title);
		return $schema;
	}//end schema()

	/**
	 * An audit entry.
	 *
	 * @param string $uuid    The uuid.
	 * @param string $created The creation instant.
	 *
	 * @return ObjectEntity
	 */
	private function entry(string $uuid, string $created): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject(['objectId' => 'o-' . $uuid]);
		$entity->setCreated(new DateTime($created));
		$entity->setOwner('admin');
		return $entity;
	}//end entry()

	/**
	 * The ids, totals and searches of a query.
	 *
	 * @param array<string, mixed> $filters The filters.
	 * @param int                  $limit   The limit.
	 * @param int                  $offset  The offset.
	 *
	 * @return array<string, mixed>
	 */
	private function page(array $filters, int $limit = 50, int $offset = 0): array {
		$this->searches = [];
		$page = $this->service()->query(query: new ListQuery(limit: $limit, offset: $offset, filters: $filters));
		return [
			'keys' => array_keys($page),
			'ids' => array_column($page['entries'], 'id'),
			'total' => $page['total'],
			'limit' => $page['limit'],
			'offset' => $page['offset'],
			'searches' => $this->searches,
		];
	}//end run()

	/**
	 * Pair resolution per filter combination.
	 *
	 * @return void
	 */
	public function testPairsSearched(): void {
		$keys = ['entries', 'total', 'limit', 'offset'];
		$empty = ['keys' => $keys, 'ids' => [], 'total' => 0, 'limit' => 50, 'offset' => 0, 'searches' => []];

		$this->assertSame(['r1/zaak [] #11'], $this->page(['registerId' => 'r1', 'schemaId' => 'zaak'])['searches'], 'An explicit schema is trusted regardless of naming.');
		$this->assertSame(['r2/zaak [] #11'], $this->page(['app' => 'r2', 'schemaId' => 'zaak'])['searches']);
		$this->assertSame(['r1/zaak [] #11'], $this->page(['registerId' => 'r1', 'app' => 'r2', 'schemaId' => 'zaak'])['searches'], 'registerId beats app.');
		$this->assertSame(['r1/auditLog [] #11', 'r2/auditLog [] #11'], $this->page(['schemaId' => 'auditLog', 'registerId' => ''])['searches'], 'Every register that has it; a missing one is skipped.');
		$this->assertSame($empty, $this->page(['schemaId' => 'nope']));
		$this->assertSame($empty, $this->page(['schemaId' => 'zaak', 'registerId' => 'nope']));
		$this->assertSame(['r2/trail [] #11', 'r2/broken [] #11', 'r2/counted-audit [] #11'], $this->page(['registerId' => 'r2', 'schemaId' => ''])['searches']);
		$this->assertSame($empty, $this->page(['app' => 'nope']));
		$this->assertSame($empty, $this->page(['registerId' => 'r3']));
		$this->assertSame(
			['r1/auditLog [] #11', 'r2/trail [] #11', 'r2/broken [] #11', 'r2/counted-audit [] #11'],
			$this->page([])['searches'],
			'Nothing named: only audit-looking schemas, never every business object.'
		);
		$this->assertSame(['r1/auditLog {"objectId":"x"} #11'], $this->page(['schemaId' => 'auditLog', 'registerId' => 'r1', 'objectId' => 'x'])['searches']);
		$this->assertSame(['r1/auditLog [] #11'], $this->page(['schemaId' => 'auditLog', 'registerId' => 'r1', 'objectId' => ''])['searches']);
	}//end testPairsSearched()

	/**
	 * Rows, bounds, sorting and paging.
	 *
	 * @return void
	 */
	public function testRowsAndPage(): void {
		$this->assertSame(
			[
				'keys' => ['entries', 'total', 'limit', 'offset'],
				'ids' => ['r1-auditLog-new', 'r2-trail-new', 'r1-auditLog-old', 'r2-trail-old'],
				'total' => 4,
				'limit' => 50,
				'offset' => 0,
				'searches' => ['r1/auditLog [] #11', 'r2/trail [] #11', 'r2/broken [] #11', 'r2/counted-audit [] #11'],
			],
			$this->page([])
		);
		$this->assertSame(['r1-auditLog-old', 'r2-trail-old', 'r1-auditLog-new', 'r2-trail-new'], $this->page(['sort' => 'created:asc'])['ids']);
		$this->assertSame(['r1-auditLog-new', 'r2-trail-new'], $this->page(['timestampStart' => '2026-03-01T00:00:00+00:00'])['ids']);
		$this->assertSame(['r1-auditLog-old', 'r2-trail-old'], $this->page(['timestampEnd' => '2026-03-01T00:00:00+00:00'])['ids']);
		$this->assertSame(['r1-auditLog-new', 'r2-trail-new'], $this->page(['timestampStart' => '2026-06-01T00:00:00+00:00', 'timestampEnd' => '2026-06-01T00:00:00+00:00'])['ids'], 'Bounds are inclusive.');
		$this->assertSame([], $this->page(['timestampStart' => '2026-07-01', 'timestampEnd' => '2026-02-01'])['ids']);
		$this->assertCount(4, $this->page(['timestampStart' => 'garbage', 'timestampEnd' => 5])['ids'], 'Unparseable bounds are no bounds.');

		$page = $this->page([], 1, 2);
		$this->assertSame([['r1-auditLog-old'], 4, 1, 2], [$page['ids'], $page['total'], $page['limit'], $page['offset']]);
		$page = $this->page([], 500, -3);
		$this->assertSame([200, 0], [$page['limit'], $page['offset']]);
		$page = $this->page([], 0, 0);
		$this->assertSame(50, $page['limit']);
	}//end testRowsAndPage()

	/**
	 * One mapped entry in full.
	 *
	 * @return void
	 */
	public function testEntryShape(): void {
		$page = $this->service()->query(query: new ListQuery(limit: 1, offset: 0, filters: ['registerId' => 'r1', 'schemaId' => 'zaak']));
		$this->assertSame(
			[
				'id' => 'r1-zaak-new',
				'registerId' => 'r1',
				'schemaId' => 'zaak',
				'objectId' => 'o-r1-zaak-new',
				'data' => ['id' => 'r1-zaak-new', 'objectId' => 'o-r1-zaak-new'],
				'created' => '2026-06-01T00:00:00+00:00',
				'userId' => 'admin',
			],
			$page['entries'][0]
		);
		$this->assertSame(2, $page['total']);
	}//end testEntryShape()
}//end class
