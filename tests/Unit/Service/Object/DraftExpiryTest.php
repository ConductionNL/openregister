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
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\Object\DraftExpiry;
use OCA\OpenRegister\Service\Object\DraftRemovalRecord;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
  * Decisions 216 and 228: one sweep for every schema, 30 days unless the schema says otherwise.
 *
 * @covers \OCA\OpenRegister\Service\Object\DraftExpiry
 * @uses \OCA\OpenRegister\Service\Object\DraftRemovalRecord
 * @uses \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses \OCA\OpenRegister\Service\Object\DraftListLens
 * @uses \OCA\OpenRegister\Db\MagicMapper\DeletedLens
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\AuditTrail
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
	 * Counts asked for what lies beyond the read cap: the query.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $counts = [];

	/**
	 * Deletes run: uuid, scope and flags.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $deletes = [];

		/**
	 * The actor each delete would be recorded under on the audit trail.
	 *
	 * @var array<int, string|null>
	 */
	private array $actors = [];

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
	 * Uuids read again just before their delete.
	 *
	 * @var array<int, string>
	 */
	private array $finds = [];

	/**
	 * What the second read answers for a uuid, instead of the listed object (a draft sent in between).
	 *
	 * @var array<string, ObjectEntity>
	 */
	private array $replaced = [];

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

				return array_slice($this->matching(query: $query), (int)$query['_offset'], (int)$query['_limit']);
			}
		);
		$objects->method('countSearchObjects')->willReturnCallback(
			function (array $query = [], bool $_rbac = true, bool $_multitenancy = true): int {
				$this->counts[] = $query;

				return count($this->matching(query: $query));
			}
		);
		$objects->method('find')->willReturnCallback(
			function (string|int $id, ...$arguments): ?ObjectEntity {
				$this->finds[] = (string)$id;
				if (isset($this->replaced[(string)$id]) === true) {
					return $this->replaced[(string)$id];
				}

				foreach ($this->stored as $list) {
					foreach ($list as $entity) {
						if ($entity instanceof ObjectEntity && $entity->getUuid() === (string)$id) {
							return $entity;
						}
					}
				}

				throw new DoesNotExistException('gone');
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
								// What the real delete path does for a draft: ask the removal record to write its entry.
				$audit = $this->createMock(AuditTrailMapper::class);
				$audit->method('createAuditTrailEntry')->willReturnCallback(
					function (ObjectEntity $object, string $action, array $context = [], ?string $actorId = null): AuditTrail {
						$this->actors[] = $actorId;

						return new AuditTrail();
					}
				);
				(new DraftRemovalRecord(auditTrail: $audit, logger: $this->createMock(LoggerInterface::class)))->write(draft: new ObjectEntity(), register: null, schema: null);

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
	 * What the store answers for a list query: the stored objects the `@self` date filters let through, in list order.
	 *
	 * Only the two filters the sweep sends are understood: `updated` before a moment, and
	 * `updated` null with `created` before a moment. Whatever else is stored (a non-object)
	 * comes back as it is, for the sweep's own check to turn down.
	 *
	 * @param array<string, mixed> $query The list query.
	 *
	 * @return array<int, mixed> The matching objects.
	 */
	private function matching(array $query): array {
		$self = $query['@self'];
		$list = ($this->stored[$self['register'] . '/' . $self['schema']] ?? []);

		return array_values(
			array_filter(
				$list,
				static function (mixed $entity) use ($self): bool {
					if (($entity instanceof ObjectEntity) === false) {
						return true;
					}

					$before = static fn (?\DateTime $moment, string $limit): bool => $moment !== null
						&& $moment->getTimestamp() < (new DateTimeImmutable($limit, new \DateTimeZone('UTC')))->getTimestamp();
					if (is_array($self['updated'] ?? null) === true) {
						return $before($entity->getUpdated(), $self['updated']['lt']);
					}

					if (($self['updated'] ?? null) === 'IS NULL') {
						return $entity->getUpdated() === null && $before($entity->getCreated(), $self['created']['lt']);
					}

					return true;
				}
			)
		);
	}//end matching()

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

				$this->assertSame(
			[
				'schemas' => 1,
				'expired' => 2,
				'removed' => 2,
				'refused' => 0,
				'deferred' => 0,
				// The count per schema the job logs (decision 228).
				'perSchema' => [
					['register' => 'service', 'schema' => 'ticket', 'lifetimeDays' => 7, 'expired' => 2, 'removed' => 2, 'refused' => 0, 'deferred' => 0],
				],
			],
			$counts
		);
		$this->assertSame(['old', 'never-touched'], array_column($this->deletes, 'uuid'));
		// Outside the job nobody is named: the job gives its own name (DraftExpiryJobTest).
		$this->assertSame([null, null], $this->actors);

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
		$this->assertSame('10', $this->searches[0]['query']['@self']['register']);
		$this->assertSame('1', $this->searches[0]['query']['@self']['schema']);
		// ... and only the expired ones: last changed before the cutoff (seven days before the sweep, in UTC), then the never-changed ones.
		$this->assertSame(['lt' => '2026-10-05 03:00:00'], $this->searches[0]['query']['@self']['updated']);
		$this->assertSame('IS NULL', $this->searches[1]['query']['@self']['updated']);
		$this->assertSame(['lt' => '2026-10-05 03:00:00'], $this->searches[1]['query']['@self']['created']);
		$this->assertSame('true', $this->searches[0]['query']['_drafts']);
		$this->assertFalse($this->searches[0]['rbac']);
		$this->assertFalse($this->searches[0]['multitenancy']);
	}//end testAnExpiredDraftIsRemovedAndAYoungOneStays()

	/**
	 * The totals of a sweep, without the rows per schema.
	 *
	 * @param array<string, mixed> $counts What the sweep answered.
	 *
	 * @return array<string, int> The totals.
	 */
	private function totals(array $counts): array {
		unset($counts['perSchema']);

		return $counts;
	}//end totals()

	/**
	 * Spec scenario: a schema that says nothing gets 30 days (decision 228).
	 */
	public function testASchemaWithoutTheSettingGetsThirtyDays(): void {
		$this->schema(id: 2, slug: 'case');
		$this->register(id: 10, slug: 'service', schemaIds: [2]);
		// 2026-09-12T03:00 is exactly 30 days before now: one second older is expired, the moment itself is not.
		$this->object(where: '10/2', uuid: 'thirty-days-and-a-second', status: 'draft', updated: '2026-09-12T02:59:59+00:00');
		$this->object(where: '10/2', uuid: 'thirty-days', status: 'draft', updated: '2026-09-12T03:00:00+00:00');

		$counts = $this->expiry->sweep(now: $this->now);

		$this->assertSame(['schemas' => 1, 'expired' => 1, 'removed' => 1, 'refused' => 0, 'deferred' => 0], $this->totals(counts: $counts));
		$this->assertSame(30, $counts['perSchema'][0]['lifetimeDays']);
		$this->assertSame(['thirty-days-and-a-second'], array_column($this->deletes, 'uuid'));
	}//end testASchemaWithoutTheSettingGetsThirtyDays()

	/**
	 * Spec scenario: a schema that opts out with false keeps every draft, and is not even listed.
	 */
	public function testASchemaThatOptsOutKeepsItsDrafts(): void {
		$this->schema(id: 2, slug: 'case', configuration: ['draftLifetimeDays' => false]);
		// A value the schema refused is stored as the opt-out.
		$this->schema(id: 3, slug: 'note', configuration: ['draftLifetimeDays' => 'never', 'draftsVisible' => true]);
		$this->register(id: 10, slug: 'service', schemaIds: [2, 3]);
		$this->object(where: '10/2', uuid: 'ancient', status: 'draft', updated: '2020-01-01T00:00:00+00:00');
		$this->object(where: '10/3', uuid: 'ancient-too', status: 'draft', updated: '2020-01-01T00:00:00+00:00');

		$counts = $this->expiry->sweep(now: $this->now);

		$this->assertSame(['schemas' => 0, 'expired' => 0, 'removed' => 0, 'refused' => 0, 'deferred' => 0, 'perSchema' => []], $counts);
		$this->assertSame([], $this->searches);
		$this->assertSame([], $this->deletes);
	}//end testASchemaThatOptsOutKeepsItsDrafts()

	/**
	 * A schema served from an object source holds no drafts of its own; that source is never asked.
	 */
	public function testASchemaServedFromAnObjectSourceIsNotListed(): void {
		$schema = $this->createMock(Schema::class);
		$schema->method('getObjectSource')->willReturn(['provider' => 'view']);
		$schema->method('getConfiguration')->willReturn([]);
		$this->schemas[5] = $schema;
		$this->register(id: 10, slug: 'service', schemaIds: [5]);

		$counts = $this->expiry->sweep(now: $this->now);

		$this->assertSame(0, $counts['schemas']);
		$this->assertSame([], $this->searches);
	}//end testASchemaServedFromAnObjectSourceIsNotListed()

	/**
	 * One run removes at most MAX_REMOVALS drafts; the rest is counted as deferred and waits (decision 228).
	 */
	public function testOneRunIsBounded(): void {
		$this->schema(id: 1, slug: 'ticket', configuration: ['draftLifetimeDays' => 7]);
		$this->schema(id: 2, slug: 'case');
		$this->register(id: 10, slug: 'service', schemaIds: [1, 2]);
		for ($i = 0; $i < (DraftExpiry::MAX_REMOVALS - 1); $i++) {
			$this->object(where: '10/1', uuid: 'ticket-' . $i, status: 'draft', updated: '2026-01-01T00:00:00+00:00');
		}

		for ($i = 0; $i < 3; $i++) {
			$this->object(where: '10/2', uuid: 'case-' . $i, status: 'draft', updated: '2026-01-01T00:00:00+00:00');
		}

		$counts = $this->expiry->sweep(now: $this->now);

		$this->assertSame(
			['schemas' => 2, 'expired' => (DraftExpiry::MAX_REMOVALS + 2), 'removed' => DraftExpiry::MAX_REMOVALS, 'refused' => 0, 'deferred' => 2],
			$this->totals(counts: $counts)
		);
		$this->assertCount(DraftExpiry::MAX_REMOVALS, $this->deletes);
		$this->assertSame(['expired' => 3, 'removed' => 1, 'deferred' => 2], array_intersect_key($counts['perSchema'][1], ['expired' => 0, 'removed' => 0, 'deferred' => 0]));
	}//end testOneRunIsBounded()

	/**
	 * The preview counts what a sweep would remove, per schema, and removes nothing (decision 228).
	 */
	public function testThePreviewCountsAndRemovesNothing(): void {
		$this->schema(id: 1, slug: 'ticket', configuration: ['draftLifetimeDays' => 7]);
		$this->schema(id: 2, slug: 'case');
		$this->schema(id: 3, slug: 'memo', configuration: ['draftLifetimeDays' => false]);
		$this->register(id: 10, slug: 'service', schemaIds: [1, 2, 3]);
		$this->object(where: '10/1', uuid: 'ticket-old', status: 'draft', updated: '2026-10-01T00:00:00+00:00');
		$this->object(where: '10/2', uuid: 'case-old', status: 'draft', updated: '2026-08-01T00:00:00+00:00');
		$this->object(where: '10/2', uuid: 'case-older', status: 'draft', updated: '2026-07-01T00:00:00+00:00');
		$this->object(where: '10/2', uuid: 'case-young', status: 'draft', updated: '2026-10-01T00:00:00+00:00');
		$this->object(where: '10/3', uuid: 'memo-ancient', status: 'draft', updated: '2020-01-01T00:00:00+00:00');

		$counts = $this->expiry->preview(now: $this->now);

		$this->assertSame([], $this->deletes);
		$this->assertSame(['schemas' => 2, 'expired' => 3, 'removed' => 0, 'refused' => 0, 'deferred' => 3], $this->totals(counts: $counts));
		$this->assertSame(
			[['service', 'ticket', 7, 1], ['service', 'case', 30, 2]],
			array_map(static fn (array $row): array => [$row['register'], $row['schema'], $row['lifetimeDays'], $row['expired']], $counts['perSchema'])
		);

		// Control: the sweep at the same moment removes exactly what the preview counted.
		$swept = $this->expiry->sweep(now: $this->now);
		$this->assertSame(3, $swept['removed']);
		$this->assertSame(['ticket-old', 'case-old', 'case-older'], array_column($this->deletes, 'uuid'));
	}//end testThePreviewCountsAndRemovesNothing()

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

				// Three pairs of register and schema: the ticket schema sits in two registers.
		$this->assertSame(['schemas' => 3, 'expired' => 3, 'removed' => 3, 'refused' => 0, 'deferred' => 0], $this->totals(counts: $counts));
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

				$this->assertSame(['schemas' => 1, 'expired' => 0, 'removed' => 0, 'refused' => 0, 'deferred' => 0, 'perSchema' => []], $counts);
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

				$this->assertSame(['schemas' => 1, 'expired' => 3, 'removed' => 1, 'refused' => 2, 'deferred' => 0], $this->totals(counts: $counts));
		$this->assertSame(['frozen', 'kept-by-the-handler', 'fine'], array_column($this->deletes, 'uuid'));
		$this->assertSame(['[DraftExpiry] An expired draft was not removed'], $this->warnings);
	}//end testARefusedDeleteIsCountedAndTheSweepCarriesOn()

	/**
	 * A draft the owner sent between the list and the delete is not deleted; a draft touched since is not either.
	 */
	public function testADraftSentAfterTheListIsNotDeleted(): void {
		$this->schema(id: 1, slug: 'ticket', configuration: ['draftLifetimeDays' => 7]);
		$this->register(id: 10, slug: 'service', schemaIds: [1]);
		$this->object(where: '10/1', uuid: 'sent', status: 'draft', updated: '2026-09-01T00:00:00+00:00');
		$this->object(where: '10/1', uuid: 'touched', status: 'draft', updated: '2026-09-01T00:00:00+00:00');
		$this->object(where: '10/1', uuid: 'stale', status: 'draft', updated: '2026-09-01T00:00:00+00:00');

		$sent = new ObjectEntity();
		$sent->setUuid('sent');
		$sent->setStatus('active');
		$sent->setUpdated(new DateTime('2026-10-12T02:59:00+00:00'));
		$this->replaced['sent'] = $sent;
		$touched = new ObjectEntity();
		$touched->setUuid('touched');
		$touched->setStatus('draft');
		$touched->setUpdated(new DateTime('2026-10-12T02:59:00+00:00'));
		$this->replaced['touched'] = $touched;

		$counts = $this->expiry->sweep(now: $this->now);

		$this->assertSame(['sent', 'touched', 'stale'], $this->finds);
		$this->assertSame(['stale'], array_column($this->deletes, 'uuid'));
		$this->assertSame(['schemas' => 1, 'expired' => 3, 'removed' => 1, 'refused' => 2, 'deferred' => 0], $this->totals(counts: $counts));
	}//end testADraftSentAfterTheListIsNotDeleted()

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
		// Two pages of expired drafts, then the one read for drafts that were never changed.
		$this->assertCount(3, $this->searches);
		$this->assertSame(0, $this->searches[0]['query']['_offset']);
		$this->assertSame(DraftExpiry::PAGE_SIZE, $this->searches[1]['query']['_offset']);
		$this->assertSame(DraftExpiry::PAGE_SIZE, $this->searches[1]['query']['_limit']);
		$this->assertSame([], $this->counts, 'Nothing lies beyond the cap, so nothing is counted.');
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

				$this->assertSame(['schemas' => 2, 'expired' => 1, 'removed' => 1, 'refused' => 0, 'deferred' => 0], $this->totals(counts: $counts));
		$this->assertSame(['old'], array_column($this->deletes, 'uuid'));
		$this->assertSame(
			['[DraftExpiry] A schema could not be loaded, its drafts are left alone', '[DraftExpiry] The drafts of a schema could not be listed'],
			$this->warnings
		);
	}//end testAnUnreadableSchemaOrListIsSkipped()

	/**
	 * Young drafts never hide an expired one: the store is asked for expired drafts alone, so the read cap holds expired rows only.
	 *
	 * Goes red without the date filter in the query: the first MAX_PAGES x PAGE_SIZE
	 * rows are then the young drafts and the expired ones behind them are never read.
	 */
	public function testYoungDraftsDoNotFillTheReadWindow(): void {
		$this->schema(id: 1, slug: 'ticket', configuration: ['draftLifetimeDays' => 7]);
		$this->register(id: 10, slug: 'service', schemaIds: [1]);
		for ($i = 0; $i < (DraftExpiry::MAX_PAGES * DraftExpiry::PAGE_SIZE); $i++) {
			$this->object(where: '10/1', uuid: 'young-' . $i, status: 'draft', updated: '2026-10-11T00:00:00+00:00');
		}

		$this->object(where: '10/1', uuid: 'old-1', status: 'draft', updated: '2026-09-01T00:00:00+00:00');
		$this->object(where: '10/1', uuid: 'old-2', status: 'draft', updated: '2026-09-02T00:00:00+00:00');

		$counts = $this->expiry->sweep(now: $this->now);

		$this->assertSame(['old-1', 'old-2'], array_column($this->deletes, 'uuid'));
		$this->assertSame(['schemas' => 1, 'expired' => 2, 'removed' => 2, 'refused' => 0, 'deferred' => 0], $this->totals(counts: $counts));
	}//end testYoungDraftsDoNotFillTheReadWindow()

	/**
	 * Expired drafts beyond the read cap are counted as deferred, not missing from the counts (decision 228).
	 *
	 * The cap is MAX_PAGES full pages per register and schema. A preview (nothing removed) shows
	 * the whole picture: what was read, and what lies beyond it.
	 */
	public function testExpiredDraftsBeyondTheReadCapAreCountedAsDeferred(): void {
		$this->schema(id: 1, slug: 'ticket', configuration: ['draftLifetimeDays' => 7]);
		$this->register(id: 10, slug: 'service', schemaIds: [1]);
		$cap = (DraftExpiry::MAX_PAGES * DraftExpiry::PAGE_SIZE);
		for ($i = 0; $i < ($cap + 20); $i++) {
			$this->object(where: '10/1', uuid: 'old-' . $i, status: 'draft', updated: '2026-09-01T00:00:00+00:00');
		}

		$counts = $this->expiry->preview(now: $this->now);

		$this->assertSame(['schemas' => 1, 'expired' => ($cap + 20), 'removed' => 0, 'refused' => 0, 'deferred' => ($cap + 20)], $this->totals(counts: $counts));
		$this->assertCount(DraftExpiry::MAX_PAGES, $this->searches, 'The read stops at the cap; the second list is not read, its rows are counted.');
		$this->assertCount(2, $this->counts, 'The remainder of both lists is counted.');
	}//end testExpiredDraftsBeyondTheReadCapAreCountedAsDeferred()

	/**
	 * A sweep removes what it read and counts what is left beyond the cap as deferred.
	 */
	public function testASweepCountsWhatIsLeftBeyondTheCapAsDeferred(): void {
		$this->schema(id: 1, slug: 'ticket', configuration: ['draftLifetimeDays' => 7]);
		$this->register(id: 10, slug: 'service', schemaIds: [1]);
		$cap = (DraftExpiry::MAX_PAGES * DraftExpiry::PAGE_SIZE);
		for ($i = 0; $i < ($cap + 20); $i++) {
			$this->object(where: '10/1', uuid: 'old-' . $i, status: 'draft', updated: '2026-09-01T00:00:00+00:00');
		}

		$counts = $this->expiry->sweep(now: $this->now);

		$this->assertSame(DraftExpiry::MAX_REMOVALS, $counts['removed']);
		$this->assertSame($cap + 20, $counts['expired']);
		$this->assertSame(($cap + 20) - DraftExpiry::MAX_REMOVALS, $counts['deferred'], 'Nothing is missing from the counts: expired = removed + refused + deferred.');
	}//end testASweepCountsWhatIsLeftBeyondTheCapAsDeferred()
}//end class
