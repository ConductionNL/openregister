<?php

declare(strict_types=1);

/**
 * ReferentialIntegrityService archival-cascade refusal tests.
 *
 * A RETAINED RECORD STAYS LIVE EVEN WHEN ITS PARENT GOES.
 *
 * The batch cascade soft-deletes with `hardDelete: false`, so an archival child
 * of a non-archival parent used to be tombstoned by a delete aimed at somebody
 * else: nothing in the cascade asked whether the child's own schema declared
 * `x-openregister-archival`, and the four HTTP delete doors that DO ask were
 * never on this path. The cascade skips such a child, names it in the result,
 * and the parent delete still proceeds.
 *
 * Every archival verdict here comes from the production predicate
 * {@see \OCA\OpenRegister\Db\Schema::hasArchivalAnnotation()} reading a real
 * Schema built with real configuration data. Nothing in this file restates what
 * "archival" means, so breaking that method fails these tests rather than
 * leaving them agreeing with a private copy of the rule.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Dto\DeletionAnalysis;
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCA\OpenRegister\Service\Object\ReferentialIntegrityService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The cascade refuses an archival child, names it, and still deletes the parent.
 */
class ReferentialIntegrityArchivalCascadeTest extends TestCase {

	/**
	 * Schema id of the ordinary, cascade-able child.
	 *
	 * @var string
	 */
	private const SCHEMA_ORDINARY = '10';

	/**
	 * Schema id of the child held under an archival obligation.
	 *
	 * @var string
	 */
	private const SCHEMA_ARCHIVAL = '77';

	/**
	 * Object entity mapper mock.
	 *
	 * @var MagicMapper&MockObject
	 */
	private $objectMapper;

	/**
	 * Audit trail mapper mock.
	 *
	 * @var AuditTrailMapper&MockObject
	 */
	private $auditTrailMapper;

	/**
	 * Subject under test.
	 *
	 * @var ReferentialIntegrityService
	 */
	private ReferentialIntegrityService $service;

	/**
	 * Set up the SUT with a REAL archival guard over real Schema entities.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			static function ($id): Schema {
				$schema = new Schema();
				$schema->setSlug('schema-' . $id);
				$configuration = [];
				if ((string)$id === self::SCHEMA_ARCHIVAL) {
					// Real annotation data. The predicate reads this, not a flag
					// the test set on a mock.
					$configuration['x-openregister-archival'] = [
						'retention' => ['default' => 'P10Y'],
					];
				}

				$schema->setConfiguration($configuration);

				return $schema;
			}
		);

		$this->service = new ReferentialIntegrityService(
			$schemaMapper,
			$this->createMock(RegisterMapper::class),
			$this->objectMapper,
			$this->auditTrailMapper,
			$this->createMock(LoggerInterface::class),
			$this->createMock(IDBConnection::class),
			$this->nullCacheFactory(),
			new ArchivalRetentionGuard($schemaMapper, $this->createMock(LoggerInterface::class))
		);

		$this->auditTrailMapper->method('buildAuditTrail')->willReturnCallback(
			static function (): AuditTrail {
				return new AuditTrail();
			}
		);

	}//end setUp()

	/**
	 * A cache factory whose caches are unavailable, so nothing is memoised.
	 *
	 * @return ICacheFactory&MockObject
	 */
	private function nullCacheFactory() {
		$cache = $this->createMock(ICache::class);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(false);
		$factory->method('createDistributed')->willReturn($cache);

		return $factory;
	}//end nullCacheFactory()

	/**
	 * Build a live ObjectEntity for a cascade target.
	 *
	 * @param string $uuid Target uuid.
	 * @param string $schema Schema identifier the target belongs to.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, string $schema): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setRegister('5');
		$entity->setSchema($schema);
		$entity->setObject(['name' => $uuid]);

		return $entity;
	}//end entity()

	/**
	 * Build a cascade-target array entry.
	 *
	 * @param string $uuid Target uuid.
	 * @param string $schema Schema identifier the target belongs to.
	 * @param string $property Referencing property.
	 *
	 * @return array<string, mixed>
	 */
	private function target(string $uuid, string $schema, string $property = 'parentRef'): array {
		return [
			'objectUuid' => $uuid,
			'register' => '5',
			'schema' => $schema,
			'property' => $property,
		];
	}//end target()

	/**
	 * THE RULING: a retained child stays live, is named, and the parent still goes.
	 *
	 * The cascade covers one ordinary child and one child on an archival schema.
	 * The ordinary child is soft-deleted as before. The archival child never
	 * reaches the resolve or the soft-delete write at all, so it cannot be
	 * tombstoned on the way past, and it is reported in the return value with
	 * its ground and the wording a reader acts on.
	 *
	 * @return void
	 */
	public function testCascadeLeavesAnArchivalChildLiveNamesItAndStillRuns(): void {
		$ordinary = $this->entity('child-ordinary', self::SCHEMA_ORDINARY);

		// THE BATCH IS ASKED FOR THE ORDINARY CHILD ONLY. If the retained child
		// appeared in this list, the cascade would already have reached for it.
		$this->objectMapper->expects($this->once())
			->method('findMultipleAcrossAllMagicTables')
			->with(
				$this->equalTo(['child-ordinary']),
				$this->equalTo(false)
			)
			->willReturn([$ordinary]);

		$softDeleted = [];
		$this->objectMapper->expects($this->once())
			->method('softDeleteMultipleObjectEntities')
			->willReturnCallback(
				static function (array $entities) use (&$softDeleted): array {
					foreach ($entities as $entity) {
						$softDeleted[] = $entity->getUuid();
					}

					return $entities;
				}
			);

		// The per-object fallback must not pick the retained child up either.
		$this->objectMapper->expects($this->never())->method('deleteObjects');

		$analysis = new DeletionAnalysis(
			deletable: true,
			cascadeTargets: [
				$this->target('child-ordinary', self::SCHEMA_ORDINARY),
				$this->target('child-retained', self::SCHEMA_ARCHIVAL),
			]
		);

		$result = $this->service->applyDeletionActions($analysis, 'admin', 'parent-uuid', 'org-1', 'parent-slug');

		// The ordinary child went.
		$this->assertSame(['child-ordinary'], $softDeleted);

		// The retained child is NAMED, with the ground and the words to act on.
		$this->assertCount(1, $result['retained']);
		$retained = $result['retained'][0];
		$this->assertSame('child-retained', $retained['uuid']);
		$this->assertSame(ArchivalRetentionGuard::GROUND_ARCHIVAL, $retained['ground']);
		$this->assertSame(ArchivalRetentionGuard::CONTEXT_CASCADE, $retained['operation']);
		$this->assertSame(
			'The law requires us to keep this record. Its parent is gone, this record stays.',
			$retained['message']
		);
		$this->assertStringContainsString('Archiefwet', $retained['basis']);
		$this->assertNotSame('', trim($retained['action']));

	}//end testCascadeLeavesAnArchivalChildLiveNamesItAndStillRuns()

	/**
	 * A cascade made entirely of retained children does no delete work at all,
	 * and still reports every one of them.
	 *
	 * The PARENT delete is not this method's business and is untouched by the
	 * refusal: applyDeletionActions returns normally rather than throwing, which
	 * is what lets DeleteObject carry on and remove the parent.
	 *
	 * @return void
	 */
	public function testAnAllArchivalCascadeDeletesNothingAndReportsEveryChild(): void {
		$this->objectMapper->expects($this->never())->method('findMultipleAcrossAllMagicTables');
		$this->objectMapper->expects($this->never())->method('softDeleteMultipleObjectEntities');
		$this->objectMapper->expects($this->never())->method('deleteObjects');

		$analysis = new DeletionAnalysis(
			deletable: true,
			cascadeTargets: [
				$this->target('retained-a', self::SCHEMA_ARCHIVAL),
				$this->target('retained-b', self::SCHEMA_ARCHIVAL),
			]
		);

		$result = $this->service->applyDeletionActions($analysis, 'admin', 'parent-uuid');

		$this->assertCount(2, $result['retained']);
		// Deepest first: applyDeletionActions reverses the analysis order before
		// applying, and the report follows the order the cascade would have run.
		$this->assertSame(
			['retained-b', 'retained-a'],
			array_column($result['retained'], 'uuid')
		);

	}//end testAnAllArchivalCascadeDeletesNothingAndReportsEveryChild()

	/**
	 * A child referenced through two properties is reported once, not twice.
	 *
	 * The analysis lists such a target per referencing property, so a naive
	 * report would tell a records officer to go and look at two records when
	 * there is only one.
	 *
	 * @return void
	 */
	public function testARetainedChildReachedTwiceIsReportedOnce(): void {
		$analysis = new DeletionAnalysis(
			deletable: true,
			cascadeTargets: [
				$this->target('retained-a', self::SCHEMA_ARCHIVAL, 'refA'),
				$this->target('retained-a', self::SCHEMA_ARCHIVAL, 'refB'),
			]
		);

		$result = $this->service->applyDeletionActions($analysis, 'admin', 'parent-uuid');

		$this->assertCount(1, $result['retained']);
		$this->assertSame('retained-a', $result['retained'][0]['uuid']);

	}//end testARetainedChildReachedTwiceIsReportedOnce()

	/**
	 * A cascade with no retained children reports an empty list, not a missing key.
	 *
	 * DeleteObject reads `$result['retained']` unconditionally to work out how
	 * many rows the cascade actually took. A missing key there would be an
	 * undefined-index warning on the overwhelmingly common delete.
	 *
	 * @return void
	 */
	public function testAnOrdinaryCascadeReportsAnEmptyRetainedList(): void {
		$ordinary = $this->entity('child-ordinary', self::SCHEMA_ORDINARY);
		$this->objectMapper->method('findMultipleAcrossAllMagicTables')->willReturn([$ordinary]);
		$this->objectMapper->method('softDeleteMultipleObjectEntities')->willReturnArgument(0);

		$analysis = new DeletionAnalysis(
			deletable: true,
			cascadeTargets: [$this->target('child-ordinary', self::SCHEMA_ORDINARY)]
		);

		$result = $this->service->applyDeletionActions($analysis, 'admin', 'parent-uuid');

		$this->assertArrayHasKey('retained', $result);
		$this->assertSame([], $result['retained']);

	}//end testAnOrdinaryCascadeReportsAnEmptyRetainedList()
}//end class
