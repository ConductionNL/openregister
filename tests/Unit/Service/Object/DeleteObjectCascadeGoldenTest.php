<?php

/**
 * Characterisation tests for the legacy `cascade: true` delete path of DeleteObject.
 *
 * Pins, on the code as it was before it was split into named steps: which
 * referenced ids are collected from which properties (and in what order),
 * that a failed batched lookup is logged and every id goes to the per-id
 * pipeline, that a refused child refuses the cascade (and an allowed one is
 * deleted), and how cascade audit rows are written when audit trails are
 * off, when one row cannot be built, and when the bulk insert fails.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Service\Object\AuditRecording;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Service\Object\DeletionRequest;
use OCA\OpenRegister\Service\Object\ReferentialIntegrityService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden tests for the cascade collection, lookup fallback, guard and audit rows.
 */
class DeleteObjectCascadeGoldenTest extends TestCase {

	private DeleteObject $handler;

	private MagicMapper&MockObject $objectMapper;

	private AuditTrailMapper&MockObject $auditTrailMapper;

	private SettingsService&MockObject $settingsService;

	private LoggerInterface&MockObject $logger;

	/**
	 * Messages of every warning logged, in order.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	protected function setUp(): void {
		parent::setUp();

		$this->objectMapper     = $this->createMock(MagicMapper::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->settingsService  = $this->createMock(SettingsService::class);
		$this->logger           = $this->createMock(LoggerInterface::class);
		$this->logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);

		$integrity = $this->createMock(ReferentialIntegrityService::class);
		$integrity->method('hasIncomingOnDeleteReferences')->willReturn(false);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$this->handler = new DeleteObject(
			$this->objectMapper,
			$this->createMock(CacheHandler::class),
			$userSession,
			new AuditRecording($this->auditTrailMapper, $this->logger),
			$this->settingsService,
			$integrity,
			$this->createMock(IDBConnection::class),
			$this->createMock(OrganisationMapper::class)
		);
	}//end setUp()

	/**
	 * An entity with a uuid and optionally an object body.
	 *
	 * @param string                    $uuid   The uuid.
	 * @param array<string, mixed>|null $object The body.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, ?array $object = null): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setRegister('1');
		$entity->setSchema('10');
		if ($object !== null) {
			$entity->setObject($object);
		}

		return $entity;
	}//end entity()

	/**
	 * Route the root lookup to the parent and any other id to a fresh child.
	 *
	 * @param ObjectEntity         $parent     The parent.
	 * @param array<string, mixed> $properties The schema properties.
	 * @param array<int, string>   $perId      Collects ids resolved per id.
	 *
	 * @return void
	 */
	private function rootIs(ObjectEntity $parent, array $properties, array &$perId): void {
		$register = new Register();
		$register->setId(1);
		$schema = new Schema();
		$schema->setId(10);
		$schema->setProperties($properties);

		$this->objectMapper->method('findAcrossAllSources')->willReturnCallback(
			function (string $identifier) use ($parent, $register, $schema, &$perId): array {
				if ($identifier === 'parent-uuid') {
					return ['object' => $parent, 'register' => $register, 'schema' => $schema];
				}

				$perId[] = $identifier;
				return ['object' => $this->entity($identifier), 'register' => $register, 'schema' => $schema];
			}
		);
		$this->objectMapper->method('update')->willReturnArgument(0);
	}//end rootIs()

	/**
	 * Audit trails on or off.
	 *
	 * @param bool $enabled On or off.
	 *
	 * @return void
	 */
	private function auditTrails(bool $enabled): void {
		$this->settingsService->method('getRetentionSettingsOnly')->willReturn(['auditTrailsEnabled' => $enabled]);
	}//end auditTrails()

	/**
	 * Run the delete of the parent.
	 *
	 * @return bool
	 */
	private function deleteParent(): bool {
		return $this->handler->deleteObject(new DeletionRequest(register: 1, schema: 10, uuid: 'parent-uuid'));
	}//end deleteParent()

	/**
	 * Only `cascade: true` properties contribute, scalar and non-empty, de-duplicated, in property order.
	 *
	 * @return void
	 */
	public function testCascadeIdsAreCollectedFromCascadePropertiesOnly(): void {
		$perId  = [];
		$parent = $this->entity(
			'parent-uuid',
			[
				'one'     => 'a',
				'many'    => ['b', '', null, ['x'], 7, 'a', 'c'],
				'map'     => ['k' => 'd'],
				'plain'   => 'not-cascaded',
				'truthy'  => 'not-cascaded-either',
				'missing' => null,
			]
		);
		$this->rootIs(
			$parent,
			[
				'one'     => ['cascade' => true],
				'many'    => ['cascade' => true, 'type' => 'array'],
				'map'     => ['cascade' => true],
				'plain'   => ['type' => 'string'],
				'truthy'  => ['cascade' => 'yes'],
				'missing' => ['cascade' => true],
				'absent'  => ['cascade' => true],
			],
			$perId
		);
		$this->auditTrails(false);

		$this->objectMapper->expects($this->once())
			->method('findStoredAcrossAllMagicTables')
			->with(['a', 'b', '7', 'c', 'd'], true)
			->willReturn([]);

		$this->assertTrue($this->deleteParent());
		$this->assertSame(['a', 'b', '7', 'c', 'd'], $perId, 'unresolved ids go to the per-id pipeline in order');
	}//end testCascadeIdsAreCollectedFromCascadePropertiesOnly()

	/**
	 * No cascade property, or no referenced value, means no lookup at all.
	 *
	 * @return void
	 */
	public function testNothingToCascadeMeansNoLookup(): void {
		$perId = [];
		$this->rootIs($this->entity('parent-uuid', ['ref' => '']), ['ref' => ['cascade' => true]], $perId);
		$this->auditTrails(false);
		$this->objectMapper->expects($this->never())->method('findStoredAcrossAllMagicTables');

		$this->assertTrue($this->deleteParent());
		$this->assertSame([], $perId);
	}//end testNothingToCascadeMeansNoLookup()

	/**
	 * A failed batched lookup is logged and every id falls back to the per-id pipeline.
	 *
	 * @return void
	 */
	public function testAFailedBatchLookupFallsBackPerId(): void {
		$perId = [];
		$this->rootIs($this->entity('parent-uuid', ['refs' => ['c1', 'c2']]), ['refs' => ['cascade' => true]], $perId);
		$this->auditTrails(false);
		$this->objectMapper->method('findStoredAcrossAllMagicTables')->willThrowException(new RuntimeException('down'));
		$this->objectMapper->expects($this->never())->method('softDeleteMultipleObjectEntities');

		$this->assertTrue($this->deleteParent());
		$this->assertSame(['c1', 'c2'], $perId);
		$this->assertContains('[DeleteObject] Batched cascade lookup failed; falling back to per-id deletes', $this->warnings);
	}//end testAFailedBatchLookupFallsBackPerId()

	/**
	 * Arrange one cascade child whose guard refuses or allows.
	 *
	 * @param bool $refuse Whether the child's guard refuses.
	 *
	 * @return void
	 */
	private function oneGuardedChild(bool $refuse): void {
		$perId = [];
		$this->rootIs($this->entity('parent-uuid', ['refs' => ['c1']]), ['refs' => ['cascade' => true]], $perId);
		$this->auditTrails(false);
		$this->objectMapper->method('findStoredAcrossAllMagicTables')->willReturn([$this->entity('c1')]);
		$this->objectMapper->method('assertDeletable')->willReturnCallback(
			static function (ObjectEntity $entity) use ($refuse): void {
				if ($refuse === true && $entity->getUuid() === 'c1') {
					throw new HookStoppedException('no');
				}
			}
		);
	}//end oneGuardedChild()

	/**
	 * A child the guard refuses refuses the cascade before anything is written.
	 *
	 * @return void
	 */
	public function testARefusedChildRefusesTheCascade(): void {
		$this->oneGuardedChild(refuse: true);
		$this->objectMapper->expects($this->never())->method('softDeleteMultipleObjectEntities');

		$this->expectException(HookStoppedException::class);
		$this->deleteParent();
	}//end testARefusedChildRefusesTheCascade()

	/**
	 * A child the guard allows is soft-deleted in the batch.
	 *
	 * @return void
	 */
	public function testAnAllowedChildIsDeleted(): void {
		$this->oneGuardedChild(refuse: false);
		$this->objectMapper->expects($this->once())->method('softDeleteMultipleObjectEntities')->willReturnArgument(0);

		$this->assertTrue($this->deleteParent());
	}//end testAnAllowedChildIsDeleted()

	/**
	 * With audit trails off no cascade row is built.
	 *
	 * @return void
	 */
	public function testNoCascadeAuditRowsWhenAuditTrailsAreOff(): void {
		$perId = [];
		$this->rootIs($this->entity('parent-uuid', ['refs' => ['c1']]), ['refs' => ['cascade' => true]], $perId);
		$this->auditTrails(false);
		$this->objectMapper->method('findStoredAcrossAllMagicTables')->willReturn([$this->entity('c1')]);
		$this->objectMapper->expects($this->once())->method('softDeleteMultipleObjectEntities')->willReturnArgument(0);
		$this->auditTrailMapper->expects($this->never())->method('buildEntry');
		$this->auditTrailMapper->expects($this->never())->method('insertAuditTrails');

		$this->assertTrue($this->deleteParent());
		$this->assertSame([], $perId);
	}//end testNoCascadeAuditRowsWhenAuditTrailsAreOff()

	/**
	 * A row that cannot be built is logged and skipped; the rest are inserted in one go.
	 *
	 * @return void
	 */
	public function testAnUnbuildableRowIsSkippedAndTheRestInserted(): void {
		$perId = [];
		$this->rootIs($this->entity('parent-uuid', ['refs' => ['c1', 'c2']]), ['refs' => ['cascade' => true]], $perId);
		$this->auditTrails(true);
		$this->auditTrailMapper->method('createAuditTrail')->willReturn(new AuditTrail());
		$this->objectMapper->method('findStoredAcrossAllMagicTables')->willReturn([$this->entity('c1'), $this->entity('c2')]);
		$this->objectMapper->method('softDeleteMultipleObjectEntities')->willReturnArgument(0);

		$row = new AuditTrail();
		$this->auditTrailMapper->expects($this->exactly(2))->method('buildEntry')->willReturnOnConsecutiveCalls(
			$this->throwException(new RuntimeException('bad row')),
			$row
		);
		$this->auditTrailMapper->expects($this->once())->method('insertAuditTrails')->with([$row])->willReturnArgument(0);

		$this->assertTrue($this->deleteParent());
		$this->assertSame(['[DeleteObject] Could not build cascade audit row'], $this->warnings);
	}//end testAnUnbuildableRowIsSkippedAndTheRestInserted()

	/**
	 * When no row can be built nothing is inserted; a failing bulk insert is logged, never thrown.
	 *
	 * @return void
	 */
	public function testNoRowsMeansNoInsertAndAFailedInsertIsLogged(): void {
		$perId = [];
		$this->rootIs($this->entity('parent-uuid', ['refs' => ['c1']]), ['refs' => ['cascade' => true]], $perId);
		$this->auditTrails(true);
		$this->auditTrailMapper->method('createAuditTrail')->willReturn(new AuditTrail());
		$this->objectMapper->method('findStoredAcrossAllMagicTables')->willReturn([$this->entity('c1')]);
		$this->objectMapper->method('softDeleteMultipleObjectEntities')->willReturnArgument(0);
		$this->auditTrailMapper->method('buildEntry')->willThrowException(new RuntimeException('bad row'));
		$this->auditTrailMapper->expects($this->never())->method('insertAuditTrails');

		$this->assertTrue($this->deleteParent());
		$this->assertSame(['[DeleteObject] Could not build cascade audit row'], $this->warnings);
	}//end testNoRowsMeansNoInsertAndAFailedInsertIsLogged()

	/**
	 * A failing bulk insert is logged and the delete still succeeds.
	 *
	 * @return void
	 */
	public function testAFailedBulkInsertIsLogged(): void {
		$perId = [];
		$this->rootIs($this->entity('parent-uuid', ['refs' => ['c1']]), ['refs' => ['cascade' => true]], $perId);
		$this->auditTrails(true);
		$this->auditTrailMapper->method('createAuditTrail')->willReturn(new AuditTrail());
		$this->objectMapper->method('findStoredAcrossAllMagicTables')->willReturn([$this->entity('c1')]);
		$this->objectMapper->method('softDeleteMultipleObjectEntities')->willReturnArgument(0);
		$this->auditTrailMapper->method('buildEntry')->willReturn(new AuditTrail());
		$this->auditTrailMapper->expects($this->once())->method('insertAuditTrails')->willThrowException(new RuntimeException('insert'));

		$this->assertTrue($this->deleteParent());
		$this->assertSame(['[DeleteObject] Bulk cascade audit insert failed'], $this->warnings);
	}//end testAFailedBulkInsertIsLogged()
}//end class
