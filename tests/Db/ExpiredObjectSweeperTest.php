<?php

/**
 * Live-database tests for the expired-object sweep.
 *
 * The unit test drives the sweeper over a stubbed store. These tests drive the
 * REAL store over real magic tables, so the parts a stub cannot prove are
 * proven here: the `_expires < now` query, the delete through the object
 * operations, the anonymising write and the removal of the object's files, the
 * legal-hold skip on a stored retention block, the opt-in through the schema
 * configuration, the batch limit read from app config, and the audit entries
 * that land in the audit trail table.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/expired-object-sweep/specs/expired-object-sweep/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Db;

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\File\FileTarget;
use OCA\OpenRegister\Service\File\FileUpload;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Retention\ExpiredObjectSweeper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * @group DB
 */
class ExpiredObjectSweeperTest extends TestCase {

	private ExpiredObjectSweeper $sweeper;

	private MagicMapper $magic;

	private RegisterMapper $registerMapper;

	private SchemaMapper $schemaMapper;

	private FileService $files;

	private IAppConfig $appConfig;

	private IDBConnection $db;

	private ?Register $register = null;

	/** @var int[] Schemas created by a test. */
	private array $schemaIds = [];

	/** @var string[] Magic tables created by a test, with prefix. */
	private array $tables = [];

	/** @var string[] Object uuids created by a test, for the audit cleanup. */
	private array $uuids = [];

	private ?IUser $previousUser = null;

	private ?int $previousBatch = null;

	protected function setUp(): void {
		parent::setUp();
		$this->sweeper        = \OC::$server->get(ExpiredObjectSweeper::class);
		$this->magic          = \OC::$server->get(MagicMapper::class);
		$this->registerMapper = \OC::$server->get(RegisterMapper::class);
		$this->schemaMapper   = \OC::$server->get(SchemaMapper::class);
		$this->files          = \OC::$server->get(FileService::class);
		$this->appConfig      = \OC::$server->get(IAppConfig::class);
		$this->db             = \OC::$server->get(IDBConnection::class);

		// Files attach to a user's folder; restored in tearDown().
		$session            = \OC::$server->get(IUserSession::class);
		$this->previousUser = $session->getUser();
		$admin              = \OC::$server->get(IUserManager::class)->get('admin');
		if ($admin instanceof IUser) {
			$session->setUser($admin);
		}

		if ($this->appConfig->hasKey('openregister', ExpiredObjectSweeper::CONFIG_BATCH) === true) {
			$this->previousBatch = $this->appConfig->getValueInt('openregister', ExpiredObjectSweeper::CONFIG_BATCH);
		}

		$register = new Register();
		$register->setTitle('phpunit-expiry-'.uniqid());
		$register->setDescription('Expired object sweep tests');
		$register->setUuid(Uuid::v4()->toRfc4122());
		$register->setSlug('phpunit-expiry-'.uniqid());
		$register->setSchemas([]);
		$this->register = $this->registerMapper->insert($register);
	}

	protected function tearDown(): void {
		if ($this->previousBatch === null) {
			$this->appConfig->deleteKey('openregister', ExpiredObjectSweeper::CONFIG_BATCH);
		} else {
			$this->appConfig->setValueInt('openregister', ExpiredObjectSweeper::CONFIG_BATCH, $this->previousBatch);
		}

		foreach ($this->tables as $table) {
			$this->bestEffort(fn () => $this->db->prepare('DROP TABLE IF EXISTS "'.$table.'"')->execute());
		}

		foreach ($this->uuids as $uuid) {
			$this->bestEffort(function () use ($uuid): void {
				$qb = $this->db->getQueryBuilder();
				$qb->delete('openregister_audit_trails')
					->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($uuid)));
				$qb->executeStatement();
			});
		}

		foreach ($this->schemaIds as $id) {
			$this->bestEffort(function () use ($id): void {
				$qb = $this->db->getQueryBuilder();
				$qb->delete('openregister_schemas')
					->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
				$qb->executeStatement();
			});
		}

		if ($this->register !== null) {
			$id = $this->register->getId();
			$this->bestEffort(function () use ($id): void {
				$qb = $this->db->getQueryBuilder();
				$qb->delete('openregister_registers')
					->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
				$qb->executeStatement();
			});
		}

		\OC::$server->get(IUserSession::class)->setUser($this->previousUser);

		parent::tearDown();
	}

	public function testASaveStoresTheExpiryInTheTableAndALaterSaveKeepsIt(): void {
		$schema  = $this->schema(expiry: ['action' => 'delete']);
		$objects = \OC::$server->get(ObjectService::class);
		$target  = new ObjectSaveOptions(target: new ObjectFindTarget(register: $this->register, schema: $schema));

		$saved = $objects->saveObject(
			object: ['reference' => 'S-1', '@self' => ['expires' => '2020-01-01T00:00:00+00:00']],
			options: $target,
			_rbac: false,
			_multitenancy: false
		);
		$uuid          = (string)$saved->getUuid();
		$this->uuids[] = $uuid;
		$this->assertSame('2020-01-01 00:00:00', $this->storedExpiry(schema: $schema, uuid: $uuid));

		// A save that does not mention the expiry leaves it as it is.
		$objects->saveObject(
			object: ['reference' => 'S-1', 'name' => 'Changed'],
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $this->register, schema: $schema), uuid: $uuid),
			_rbac: false,
			_multitenancy: false
		);
		$this->assertSame('Changed', $this->load(schema: $schema, uuid: $uuid)->getObject()['name']);
		$this->assertSame('2020-01-01 00:00:00', $this->storedExpiry(schema: $schema, uuid: $uuid));

		$report = $this->reportFor(schema: $schema, reports: $this->sweeper->sweep(now: new DateTimeImmutable()));
		$this->assertSame(1, $report['deleted']);
		$this->assertFalse($this->isLive(schema: $schema, uuid: $uuid));
	}

	public function testAnExpiredObjectOfADeleteSchemaIsDeletedAndAudited(): void {
		$schema  = $this->schema(expiry: ['action' => 'delete']);
		$expired = $this->object(schema: $schema, data: ['reference' => 'R-1', 'name' => 'Draft'], expires: '-1 day');
		$current = $this->object(schema: $schema, data: ['reference' => 'R-2', 'name' => 'Draft'], expires: '+1 day');

		$report = $this->reportFor(schema: $schema, reports: $this->sweeper->sweep(now: new DateTimeImmutable()));

		$this->assertSame(1, $report['deleted']);
		$this->assertSame(0, $report['anonymised']);
		$this->assertSame(0, $report['left']);
		$this->assertFalse($this->isLive(schema: $schema, uuid: $expired), 'the expired object MUST be gone');
		$this->assertTrue($this->isLive(schema: $schema, uuid: $current), 'an object not yet expired MUST stay');
		$this->assertSame(1, $this->auditCount(uuid: $expired, action: ExpiredObjectSweeper::AUDIT_DELETED));
		$this->assertSame(0, $this->auditCount(uuid: $current, action: ExpiredObjectSweeper::AUDIT_DELETED));
	}

	public function testAnExpiredObjectOfAnAnonymiseSchemaKeepsOnlyItsKeptPropertiesAndLosesItsFiles(): void {
		$schema = $this->schema(expiry: ['action' => 'anonymise', 'keep' => ['reference']]);
		$uuid   = $this->object(schema: $schema, data: ['reference' => 'R-3', 'name' => 'Jane Doe', 'tags' => ['a'], 'address' => ['city' => 'Utrecht']], expires: '-1 hour');
		$object = $this->load(schema: $schema, uuid: $uuid);
		$this->files->addFile(new FileTarget($object), new FileUpload('evidence.txt', 'personal data', false, []));
		$this->assertCount(1, $this->fileList(object: $object));

		$report = $this->reportFor(schema: $schema, reports: $this->sweeper->sweep(now: new DateTimeImmutable()));

		$this->assertSame(1, $report['anonymised']);
		$this->assertSame(0, $report['deleted']);
		$after = $this->load(schema: $schema, uuid: $uuid);
		$data  = $after->getObject();
		$this->assertSame('R-3', $data['reference']);
		$this->assertNull($data['name'] ?? null);
		$this->assertSame([], ($data['tags'] ?? null));
		$this->assertNotSame(['city' => 'Utrecht'], ($data['address'] ?? null));
		$this->assertNull($after->getExpires(), 'an anonymised object MUST lose its expiry');
		$this->assertCount(0, $this->fileList(object: $after), 'its files MUST be removed');
		$this->assertSame(1, $this->auditCount(uuid: $uuid, action: ExpiredObjectSweeper::AUDIT_ANONYMISED));

		// The cleared expiry keeps the next run from touching it again.
		$again = $this->reportFor(schema: $schema, reports: $this->sweeper->sweep(now: new DateTimeImmutable()));
		$this->assertSame(0, $again['anonymised']);
	}

	public function testAnObjectUnderALegalHoldIsKept(): void {
		$schema = $this->schema(expiry: ['action' => 'delete']);
		$uuid   = $this->object(
			schema: $schema,
			data: ['reference' => 'R-4', 'name' => 'Held'],
			expires: '-1 day',
			retention: ['legalHold' => ['active' => true, 'reason' => 'phpunit']]
		);

		$report = $this->reportFor(schema: $schema, reports: $this->sweeper->sweep(now: new DateTimeImmutable()));

		$this->assertSame(1, $report['held']);
		$this->assertSame(0, $report['deleted']);
		$this->assertTrue($this->isLive(schema: $schema, uuid: $uuid));
		$this->assertSame('Held', $this->load(schema: $schema, uuid: $uuid)->getObject()['name']);
		$this->assertSame(0, $this->auditCount(uuid: $uuid, action: ExpiredObjectSweeper::AUDIT_DELETED));
	}

	public function testASchemaThatDidNotOptInIsLeftAlone(): void {
		$schema = $this->schema(expiry: null);
		$uuid   = $this->object(schema: $schema, data: ['reference' => 'R-5', 'name' => 'Not opted in'], expires: '-30 days');

		$reports = $this->sweeper->sweep(now: new DateTimeImmutable());

		$slugs = array_column($reports, 'schema');
		$this->assertNotContains((string)$schema->getSlug(), $slugs, 'a schema without the key MUST not be swept');
		$this->assertTrue($this->isLive(schema: $schema, uuid: $uuid));
		$this->assertSame('Not opted in', $this->load(schema: $schema, uuid: $uuid)->getObject()['name']);
	}

	public function testOneRunHandlesAtMostTheBatchAndLeavesTheRestForTheNext(): void {
		$this->appConfig->setValueInt('openregister', ExpiredObjectSweeper::CONFIG_BATCH, 2);
		$schema = $this->schema(expiry: ['action' => 'delete']);
		$oldest = $this->object(schema: $schema, data: ['reference' => 'R-6'], expires: '-3 days');
		$middle = $this->object(schema: $schema, data: ['reference' => 'R-7'], expires: '-2 days');
		$newest = $this->object(schema: $schema, data: ['reference' => 'R-8'], expires: '-1 day');

		$first = $this->reportFor(schema: $schema, reports: $this->sweeper->sweep(now: new DateTimeImmutable()));

		$this->assertSame(2, $first['deleted']);
		$this->assertSame(1, $first['left']);
		$this->assertFalse($this->isLive(schema: $schema, uuid: $oldest), 'the batch MUST take the oldest expiry first');
		$this->assertFalse($this->isLive(schema: $schema, uuid: $middle));
		$this->assertTrue($this->isLive(schema: $schema, uuid: $newest));

		$second = $this->reportFor(schema: $schema, reports: $this->sweeper->sweep(now: new DateTimeImmutable()));
		$this->assertSame(1, $second['deleted']);
		$this->assertSame(0, $second['left']);
		$this->assertFalse($this->isLive(schema: $schema, uuid: $newest));
	}

	public function testADryRunCountsAndChangesNothing(): void {
		$schema = $this->schema(expiry: ['action' => 'delete']);
		$uuid   = $this->object(schema: $schema, data: ['reference' => 'R-9'], expires: '-1 day');

		$report = $this->reportFor(schema: $schema, reports: $this->sweeper->sweep(now: new DateTimeImmutable(), dryRun: true));

		$this->assertSame(1, $report['deleted']);
		$this->assertTrue($this->isLive(schema: $schema, uuid: $uuid));
		$this->assertSame(0, $this->auditCount(uuid: $uuid, action: ExpiredObjectSweeper::AUDIT_DELETED));
	}

	/**
	 * A schema in the test register, with a magic table, and with the given expiry block or none.
	 *
	 * @param array<string, mixed>|null $expiry The x-openregister.expiry block.
	 *
	 * @return Schema
	 */
	private function schema(?array $expiry): Schema {
		$schema = new Schema();
		$schema->setTitle('phpunit-expiry-schema-'.uniqid());
		$schema->setDescription('Schema for expired object sweep tests');
		$schema->setUuid(Uuid::v4()->toRfc4122());
		$schema->setSlug('phpunit-expiry-schema-'.uniqid());
		$schema->setProperties([
			'reference' => ['type' => 'string', 'title' => 'Reference'],
			'name' => ['type' => 'string', 'title' => 'Name'],
			'tags' => ['type' => 'array', 'title' => 'Tags', 'items' => ['type' => 'string']],
			'address' => ['type' => 'object', 'title' => 'Address'],
		]);
		if ($expiry !== null) {
			$schema->setConfiguration(['x-openregister' => ['expiry' => $expiry]]);
		}

		$schema            = $this->schemaMapper->insert($schema);
		$this->schemaIds[] = $schema->getId();

		$this->register->setSchemas(array_merge(($this->register->getSchemas() ?? []), [$schema->getId()]));
		$this->register = $this->registerMapper->update($this->register);

		$this->magic->ensureTableForRegisterSchema($this->register, $schema);
		$this->tables[] = 'oc_'.$this->magic->getTableNameForRegisterSchema($this->register, $schema);

		return $schema;
	}

	/**
	 * Store an object with an expiry relative to now, and return its uuid.
	 *
	 * @param Schema                    $schema    The schema.
	 * @param array<string, mixed>      $data      The object data.
	 * @param string                    $expires   A relative date such as '-1 day'.
	 * @param array<string, mixed>|null $retention The retention block.
	 *
	 * @return string
	 */
	private function object(Schema $schema, array $data, string $expires, ?array $retention=null): string {
		$uuid   = Uuid::v4()->toRfc4122();
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setRegister((string)$this->register->getId());
		$entity->setSchema((string)$schema->getId());
		$entity->setObject($data);
		$entity->setExpires(new DateTime($expires));
		if ($retention !== null) {
			$entity->setRetention($retention);
		}

		$this->magic->insertObjectEntity($entity, $this->register, $schema, false);
		$this->uuids[] = $uuid;

		return $uuid;
	}

	/**
	 * Load an object straight from its table.
	 *
	 * @param Schema $schema The schema.
	 * @param string $uuid   The uuid.
	 *
	 * @return ObjectEntity
	 */
	private function load(Schema $schema, string $uuid): ObjectEntity {
		return $this->magic->find($uuid, $this->register, $schema, false, false, false);
	}

	/**
	 * Whether an object can still be found and is not in the trash.
	 *
	 * @param Schema $schema The schema.
	 * @param string $uuid   The uuid.
	 *
	 * @return bool
	 */
	private function isLive(Schema $schema, string $uuid): bool {
		try {
			return $this->load(schema: $schema, uuid: $uuid)->isSoftDeleted() === false;
		} catch (Throwable $error) {
			return false;
		}
	}

	/**
	 * The report row of one schema.
	 *
	 * @param Schema                           $schema  The schema.
	 * @param array<int, array<string, mixed>> $reports The sweep reports.
	 *
	 * @return array<string, mixed>
	 */
	private function reportFor(Schema $schema, array $reports): array {
		foreach ($reports as $report) {
			if ($report['schema'] === (string)$schema->getSlug()) {
				return $report;
			}
		}

		$this->fail('the sweep MUST report on an opted-in schema');
	}

	/**
	 * The `_expires` column of a row, as the database returns it.
	 *
	 * @param Schema $schema The schema.
	 * @param string $uuid   The uuid.
	 *
	 * @return string|null
	 */
	private function storedExpiry(Schema $schema, string $uuid): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('_expires')
			->from($this->magic->getTableNameForRegisterSchema($this->register, $schema))
			->where($qb->expr()->eq('_uuid', $qb->createNamedParameter($uuid)));
		$cursor = $qb->executeQuery();
		$value  = $cursor->fetchOne();
		$cursor->closeCursor();

		return $value === false || $value === null ? null : substr((string)$value, 0, 19);
	}

	/**
	 * How many audit entries with this action the object has.
	 *
	 * @param string $uuid   The object uuid.
	 * @param string $action The audit action.
	 *
	 * @return int
	 */
	private function auditCount(string $uuid, string $action): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))
			->from('openregister_audit_trails')
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($uuid)))
			->andWhere($qb->expr()->eq('action', $qb->createNamedParameter($action)));
		$cursor = $qb->executeQuery();
		$count  = (int)$cursor->fetchOne();
		$cursor->closeCursor();

		return $count;
	}

	/**
	 * The object's files as a list.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<int, mixed>
	 */
	private function fileList(ObjectEntity $object): array {
		$files = $this->files->getFiles($object);

		return is_array($files) === true ? array_values($files) : iterator_to_array($files, false);
	}

	/**
	 * Run a cleanup step and ignore its failure.
	 *
	 * @param callable $step The step.
	 *
	 * @return void
	 */
	private function bestEffort(callable $step): void {
		try {
			$step();
		} catch (Throwable $error) {
			// Already gone.
		}
	}
}
