<?php

/**
 * Drafts past their schema's lifetime are removed through the ordinary delete; everything else stays.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-one-background-job-must-remove-drafts-past-their-schemas-lifetime
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\DraftExpiry;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Decision 216: one sweep for every schema that sets `draftLifetimeDays`.
 *
 * @covers \OCA\OpenRegister\Service\Object\DraftExpiry
 * @uses \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses \OCA\OpenRegister\Service\Object\DraftListLens
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 */
class DraftExpiryTest extends TestCase {

	/**
	 * Stored objects by "<register>/<schema>", in list order.
	 *
	 * @var array<string, array<int, mixed>>
	 */
	private array $stored = [];

	/**
	 * Searches run: the query and the flags.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $searches = [];

	/**
	 * Deletes run: uuid, scope and flags.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $deletes = [];

	/**
	 * Warnings logged.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * Schemas by id.
	 *
	 * @var array<int, Schema>
	 */
	private array $schemas = [];

	/**
	 * Registers the mapper lists.
	 *
	 * @var array<int, mixed>
	 */
	private array $registers = [];

	private DraftExpiry $expiry;

	private DateTimeImmutable $now;

	protected function setUp(): void {
		$this->now = new DateTimeImmutable('2026-10-12T03:00:00+00:00');

		$objects = $this->createMock(ObjectService::class);
		$objects->method('searchObjects')->willReturnCallback(
			function (array $query = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->searches[] = ['query' => $query, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				$key = $query['@self']['register'] . '/' . $query['@self']['schema'];
				if ($key === '10/9') {
					throw new RuntimeException('table missing');
				}

				return array_slice(($this->stored[$key] ?? []), (int)$query['_offset'], (int)$query['_limit']);
			}
		);
		$objects->method('deleteObject')->willReturnCallback(
			function (string $uuid, $register = null, $schema = null, bool $_rbac = true, bool $_multitenancy = true, bool $_retentionSweep = false, $currentUser = null, bool $permanent = false): bool {
				$this->deletes[] = [
					'uuid' => $uuid,
					'register' => $register,
					'schema' => $schema,
					'rbac' => $_rbac,
					'multitenancy' => $_multitenancy,
					'retentionSweep' => $_retentionSweep,
					'permanent' => $permanent,
				];
				if ($uuid === 'frozen') {
					throw new RuntimeException('This schema refuses deletes.');
				}

				return $uuid !== 'kept-by-the-handler';
			}
		);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('findAll')->willReturnCallback(fn (): array => $this->registers);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			function (string|int $id, ?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true): Schema {
				if (isset($this->schemas[(int)$id]) === false || $_rbac !== false || $_multitenancy !== false) {
					throw new RuntimeException('no schema ' . $id);
				}

				return $this->schemas[(int)$id];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);

		$this->expiry = new DraftExpiry(objects: $objects, registers: $registerMapper, schemas: $schemaMapper, logger: $logger);
	}//end setUp()

	/**
	 * A schema, with or without a draft lifetime.
	 *
	 * @param array<string, mixed> $configuration Its configuration, as an app's register file declares it.
	 */
	private function schema(int $id, string $slug, array $configuration = []): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		$schema->setConfiguration($configuration);
		$this->schemas[$id] = $schema;

		return $schema;
	}//end schema()

	/**
	 * A register listing schema ids.
	 *
	 * @param array<int, int|string> $schemaIds The ids.
	 */
	private function register(int $id, string $slug, array $schemaIds): Register {
		$register = new Register();
		$register->setId($id);
		$register->setSlug($slug);
		$register->setSchemas($schemaIds);
		$this->registers[] = $register;

		return $register;
	}//end register()

	/**
	 * A stored object.
	 */
	private function object(string $where, string $uuid, ?string $status, ?string $updated, ?string $created = '2026-09-01T10:00:00+00:00'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setStatus($status);
		if ($updated !== null) {
			$entity->setUpdated(new DateTime($updated));
		}

		if ($created !== null) {
			$entity->setCreated(new DateTime($created));
		}

		$this->stored[$where][] = $entity;

		return $entity;
	}//end object()

	/**
	 * Spec scenario: a draft nobody changed for longer than the lifetime is removed; a younger one stays.
	 */
	public function testAnExpiredDraftIsRemovedAndAYoungOneStays(): void {
		$schema = $this->schema(id: 1, slug: 'ticket', configuration: ['draftLifetimeDays' => 7]);
		$register = $this->register(id: 10, slug: 'service', schemaIds: [1]);
		// Eight days untouched: expired. Six days: kept. Created long ago but changed yesterday: kept.
		$this->object(where: '10/1', uuid: 'old', status: 'draft', updated: '2026-10-04T02:59:59+00:00');
		$this->object(where: '10/1', uuid: 'young', status: 'draft', updated: '2026-10-06T03:00:00+00:00');
		$this->object(where: '10/1', uuid: 'touched', status: 'draft', updated: '2026-10-11T12:00:00+00:00', created: '2026-01-01T00:00:00+00:00');
		// Never updated: the created moment counts.
		$this->object(where: '10/1', uuid: 'never-touched', status: 'draft', updated: null, created: '2026-10-01T00:00:00+00:00');

		$counts = $this->expiry->sweep(now: $this->now);

		$this->assertSame(['schemas' => 1, 'expired' => 2, 'removed' => 2, 'refused' => 0], $counts);
		$this->assertSame(['old', 'never-touched'], array_column($this->deletes, 'uuid'));

		// The ordinary delete, in the draft's own register and schema: no RBAC
		// (a job has no session) and not the archival sweep's bypass. The sweep
		// does not ask for a permanent delete: ObjectService::deleteObject()
		// decides that for every draft (ObjectServiceDraftDeleteTest).
		$this->assertSame($register, $this->deletes[0]['register']);
		$this->assertSame($schema, $this->deletes[0]['schema']);
		$this->assertFalse($this->deletes[0]['rbac']);
		$this->assertFalse($this->deletes[0]['multitenancy']);
		$this->assertFalse($this->deletes[0]['permanent']);
		$this->assertFalse($this->deletes[0]['retentionSweep']);

		// The list asked for drafts alone, of everybody, in that register and schema.
		$this->assertSame(['register' => '10', 'schema' => '1'], $this->searches[0]['query']['@self']);
		$this->assertSame('true', $this->searches[0]['query']['_drafts']);
		$this->assertFalse($this->searches[0]['rbac']);
		$this->assertFalse($this->searches[0]['multitenancy']);
	}//end testAnExpiredDraftIsRemovedAndAYoungOneStays()

	/**
	 * Spec scenario: a schema without the setting keeps every draft, and is not even listed.
	 */
	public function testASchemaWithoutALifetimeKeepsItsDrafts(): void {
		$this->schema(id: 2, slug: 'case');
		$this->schema(id: 3, slug: 'note', configuration: ['draftsVisible' => true]);
		$this->register(id: 10, slug: 'service', schemaIds: [2, 3]);
		$this->object(where: '10/2', uuid: 'ancient', status: 'draft', updated: '2020-01-01T00:00:00+00:00');
		$this->object(where: '10/3', uuid: 'ancient-too', status: 'draft', updated: '2020-01-01T00:00:00+00:00');

		$counts = $this->expiry->sweep(now: $this->now);

		$this->assertSame(['schemas' => 0, 'expired' => 0, 'removed' => 0, 'refused' => 0], $counts);
		$this->assertSame([], $this->searches);
		$this->assertSame([], $this->deletes);
	}//end testASchemaWithoutALifetimeKeepsItsDrafts()

	/**
	 * Each schema follows its own lifetime, in every register that carries it.
	 */
	public function testEachSchemaFollowsItsOwnLifetime(): void {
		$this->schema(id: 1, slug: 'ticket', configuration: ['draftLifetimeDays' => 7]);
		$this->schema(id: 4, slug: 'request', configuration: ['draftLifetimeDays' => 30]);
		$this->register(id: 10, slug: 'service', schemaIds: [1, 4]);
		$this->register(id: 11, slug: 'archive', schemaIds: ['1']);
		// Ten days untouched: past seven, within thirty.
		$this->object(where: '10/1', uuid: 'ticket-10', status: 'draft', updated: '2026-10-02T03:00:00+00:00');
		$this->object(where: '10/4', uuid: 'request-10', status: 'draft', updated: '2026-10-02T03:00:00+00:00');
		$this->object(where: '11/1', uuid: 'ticket-11', status: 'draft', updated: '2026-10-02T03:00:00+00:00');
		$this->object(where: '10/4', uuid: 'request-old', status: 'draft', updated: '2026-09-01T03:00:00+00:00');

		$counts = $this->expiry->sweep(now: $this->now);

		$this->assertSame(['schemas' => 2, 'expired' => 3, 'removed' => 3, 'refused' => 0], $counts);
		$this->assertSame(['ticket-10', 'request-old', 'ticket-11'], array_column($this->deletes, 'uuid'));
	}//end testEachSchemaFollowsItsOwnLifetime()

	/**
	 * A hit that is not a draft is never removed, whatever the list answered.
	 */
	public function testOnlyADraftIsEverRemoved(): void {
		$this->schema(id: 1, slug: 'ticket', configuration: ['draftLifetimeDays' => 7]);
		$this->register(id: 10, slug: 'service', schemaIds: [1]);
		// A list that ignored the lens: an active object, one without a stored status, and something that is no object.
		$this->object(where: '10/1', uuid: 'sent', status: 'active', updated: '2020-01-01T00:00:00+00:00');
		$this->object(where: '10/1', uuid: 'legacy', status: null, updated: '2020-01-01T00:00:00+00:00');
		$this->stored['10/1'][] = ['uuid' => 'an-array'];
		// A draft with no date at all, and one without an id, are left alone.
		$this->object(where: '10/1', uuid: 'dateless', status: 'draft', updated: null, created: null);
		$this->object(where: '10/1', uuid: '', status: 'draft', updated: '2020-01-01T00:00:00+00:00');

		$counts = $this->expiry->sweep(now: $this->now);

		$this->assertSame(['schemas' => 1, 'expired' => 0, 'removed' => 0, 'refused' => 0], $counts);
		$this->assertSame([], $this->deletes);
	}//end testOnlyADraftIsEverRemoved()

	/**
	 * A delete the ordinary path refuses is counted and logged; the sweep carries on.
	 */
	public function testARefusedDeleteIsCountedAndTheSweepCarriesOn(): void {
		$this->schema(id: 1, slug: 'ticket', configuration: ['draftLifetimeDays' => 1]);
		$this->register(id: 10, slug: 'service', schemaIds: [1]);
		$this->object(where: '10/1', uuid: 'frozen', status: 'draft', updated: '2026-10-01T00:00:00+00:00');
		$this->object(where: '10/1', uuid: 'kept-by-the-handler', status: 'draft', updated: '2026-10-01T00:00:00+00:00');
		$this->object(where: '10/1', uuid: 'fine', status: 'draft', updated: '2026-10-01T00:00:00+00:00');

		$counts = $this->expiry->sweep(now: $this->now);

		$this->assertSame(['schemas' => 1, 'expired' => 3, 'removed' => 1, 'refused' => 2], $counts);
		$this->assertSame(['frozen', 'kept-by-the-handler', 'fine'], array_column($this->deletes, 'uuid'));
		$this->assertSame(['[DraftExpiry] An expired draft was not removed'], $this->warnings);
	}//end testARefusedDeleteIsCountedAndTheSweepCarriesOn()

	/**
	 * Every page is read before the first delete, so no draft is skipped by a shifting page.
	 */
	public function testEveryPageIsReadBeforeTheFirstDelete(): void {
		$this->schema(id: 1, slug: 'ticket', configuration: ['draftLifetimeDays' => 7]);
		$this->register(id: 10, slug: 'service', schemaIds: [1]);
		for ($i = 0; $i < (DraftExpiry::PAGE_SIZE + 5); $i++) {
			$this->object(where: '10/1', uuid: 'draft-' . $i, status: 'draft', updated: '2026-09-01T00:00:00+00:00');
		}

		$counts = $this->expiry->sweep(now: $this->now);

		$this->assertSame(DraftExpiry::PAGE_SIZE + 5, $counts['removed']);
		$this->assertCount(2, $this->searches);
		$this->assertSame(0, $this->searches[0]['query']['_offset']);
		$this->assertSame(DraftExpiry::PAGE_SIZE, $this->searches[1]['query']['_offset']);
		$this->assertSame(DraftExpiry::PAGE_SIZE, $this->searches[1]['query']['_limit']);
	}//end testEveryPageIsReadBeforeTheFirstDelete()

	/**
	 * A schema or a list that cannot be read is logged and skipped; the other schemas are still swept.
	 */
	public function testAnUnreadableSchemaOrListIsSkipped(): void {
		$this->schema(id: 1, slug: 'ticket', configuration: ['draftLifetimeDays' => 7]);
		$this->schema(id: 9, slug: 'broken', configuration: ['draftLifetimeDays' => 7]);
		$this->registers[] = 'not a register';
		// Schema 404 does not exist, 9 cannot be listed, and an id that is no id is ignored.
		$this->register(id: 10, slug: 'service', schemaIds: [404, 9, ['nested'], 1]);
		$this->object(where: '10/1', uuid: 'old', status: 'draft', updated: '2026-09-01T00:00:00+00:00');

		$counts = $this->expiry->sweep(now: $this->now);

		$this->assertSame(['schemas' => 2, 'expired' => 1, 'removed' => 1, 'refused' => 0], $counts);
		$this->assertSame(['old'], array_column($this->deletes, 'uuid'));
		$this->assertSame(
			['[DraftExpiry] A schema could not be loaded, its drafts are left alone', '[DraftExpiry] The drafts of a schema could not be listed'],
			$this->warnings
		);
	}//end testAnUnreadableSchemaOrListIsSkipped()
}//end class
