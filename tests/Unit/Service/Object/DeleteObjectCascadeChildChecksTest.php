<?php

/**
 * Tests for the checked `cascade: true` children of a root delete.
 *
 * Each child is found in the table its property names, with the caller's RBAC and
 * organisation filters, and asked the questions a direct delete of it asks: the delete
 * right, transferred, append-only and shared master data. The checked children run as
 * cascade targets of the onDelete cascade, in one transaction with the root delete.
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

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Dto\DeletionAnalysis;
use OCA\OpenRegister\Exception\AppendOnlyException;
use OCA\OpenRegister\Exception\ReferentialIntegrityException;
use OCA\OpenRegister\Exception\SharedMasterDataWriteException;
use OCA\OpenRegister\Service\Object\AuditRecording;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\CascadeDeleteOrigin;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Service\Object\DeleteRowRules;
use OCA\OpenRegister\Service\Object\DeletionAttribution;
use OCA\OpenRegister\Service\Object\DeletionIntegrity;
use OCA\OpenRegister\Service\Object\DeletionRequest;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Object\ReferentialIntegrityService;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\SharedMasterDataService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The checks every `cascade: true` child passes before the root delete runs.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class DeleteObjectCascadeChildChecksTest extends TestCase {

	private MagicMapper&MockObject $objectMapper;

	private ReferentialIntegrityService&MockObject $integrity;

	private PermissionHandlerInterface&MockObject $permissions;

	private IDBConnection&MockObject $db;

	private ContainerInterface&MockObject $container;

	private Register $register;

	private Schema $parentSchema;

	private Schema $childSchema;

	private ObjectEntity $parent;

	/**
	 * The ids the child lookup was asked for, with its flags.
	 *
	 * @var array<int, array{0: string, 1: bool, 2: bool}>
	 */
	private array $childLookups = [];

	/**
	 * What the onDelete cascade was handed.
	 *
	 * @var array<int, array{0: DeletionAnalysis, 1: CascadeDeleteOrigin}>
	 */
	private array $applied = [];

	protected function setUp(): void {
		parent::setUp();

		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->integrity    = $this->createMock(ReferentialIntegrityService::class);
		$this->permissions  = $this->createMock(PermissionHandlerInterface::class);
		$this->db           = $this->createMock(IDBConnection::class);
		$this->container    = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willThrowException(new RuntimeException('not registered'));

		$this->register = new Register();
		$this->register->setId(1);
		$this->parentSchema = new Schema();
		$this->parentSchema->setId(10);
		$this->parentSchema->setSlug('parent');
		$this->parentSchema->setProperties(['kid' => ['cascade' => true, '$ref' => '#/components/schemas/kid']]);
		$this->childSchema = new Schema();
		$this->childSchema->setId(20);
		$this->childSchema->setSlug('kid');

		$this->parent = $this->entity(uuid: 'parent-uuid', schema: '10', object: ['kid' => 'child-uuid']);

		$this->objectMapper->method('findAcrossAllSources')->willReturn(
			['object' => $this->parent, 'register' => $this->register, 'schema' => $this->parentSchema]
		);
		$this->objectMapper->method('update')->willReturnArgument(0);

		$this->integrity->method('hasIncomingOnDeleteReferences')->willReturn(false);
		$this->integrity->method('applyDeletionActions')->willReturnCallback(
			function (DeletionAnalysis $analysis, CascadeDeleteOrigin $origin): array {
				$this->applied[] = [$analysis, $origin];
				return ['retained' => []];
			}
		);
	}//end setUp()

	/**
	 * An entity in register 1.
	 *
	 * @param string                    $uuid   The uuid.
	 * @param string                    $schema The schema id.
	 * @param array<string, mixed>|null $object The body.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, string $schema, ?array $object = null): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setRegister('1');
		$entity->setSchema($schema);
		$entity->setOwner('owner');
		if ($object !== null) {
			$entity->setObject($object);
		}

		return $entity;
	}//end entity()

	/**
	 * The handler under test.
	 *
	 * @return DeleteObject
	 */
	private function handler(): DeleteObject {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRetentionSettingsOnly')->willReturn(['auditTrailsEnabled' => false]);

		return new DeleteObject(
			$this->objectMapper,
			$this->createMock(CacheHandler::class),
			new AuditRecording($this->createMock(AuditTrailMapper::class), $this->createMock(LoggerInterface::class)),
			$settings,
			new DeletionIntegrity($this->integrity, $this->db),
			new DeletionAttribution($userSession, $this->createMock(OrganisationMapper::class), $this->createMock(LoggerInterface::class)),
			null,
			null,
			null,
			new DeleteRowRules($this->permissions, $this->container)
		);
	}//end handler()

	/**
	 * The property names the child's table, and the child lookup answers with this child.
	 *
	 * @param ObjectEntity|null $child The child, or null when the table does not hold the id.
	 *
	 * @return void
	 */
	private function childIs(?ObjectEntity $child): void {
		$this->integrity->method('cascadePropertyTable')->willReturn(
			new RegisterSchema(register: $this->register, schema: $this->childSchema)
		);
		$this->objectMapper->method('find')->willReturnCallback(
			function (string|int $identifier, ?Register $register, ?Schema $schema, bool $includeDeleted, bool $_rbac, bool $_multitenancy) use ($child): ObjectEntity {
				$this->assertSame($this->childSchema, $schema);
				$this->assertSame($this->register, $register);
				$this->childLookups[] = [(string)$identifier, $_rbac, $_multitenancy];
				if ($child === null) {
					throw new DoesNotExistException('Object not found');
				}

				return $child;
			}
		);
	}//end childIs()

	/**
	 * Delete the parent with these flags.
	 *
	 * @param bool $rbac         RBAC.
	 * @param bool $multitenancy The organisation filter.
	 *
	 * @return bool
	 */
	private function deleteParent(bool $rbac = true, bool $multitenancy = true): bool {
		return $this->handler()->deleteObject(new DeletionRequest(register: 1, schema: 10, uuid: 'parent-uuid'), $rbac, $multitenancy);
	}//end deleteParent()

	/**
	 * The root delete must not have happened, and nothing cascaded.
	 *
	 * @return void
	 */
	private function expectNothingWritten(): void {
		$this->objectMapper->expects($this->never())->method('update');
		$this->integrity->expects($this->never())->method('applyDeletionActions');
	}//end expectNothingWritten()

	/**
	 * A permitted child is asked the delete right and cascades as a target of the root delete.
	 *
	 * @return void
	 */
	public function testAPermittedChildCascadesAsATargetOfTheRootDelete(): void {
		$this->childIs($this->entity(uuid: 'child-uuid', schema: '20'));
		$this->permissions->expects($this->once())->method('checkPermission')->with(
			$this->childSchema,
			'delete',
			$this->anything(),
			true
		);
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');

		$this->assertTrue($this->deleteParent());

		$this->assertCount(1, $this->applied);
		[$analysis, $origin] = $this->applied[0];
		$this->assertSame('parent-uuid', $origin->cascadeSource);
		$this->assertSame('parent', $origin->triggerSchemaSlug);
		$this->assertSame(
			[['objectUuid' => 'child-uuid', 'register' => '1', 'schema' => '20', 'property' => 'kid', 'chain' => []]],
			$analysis->cascadeTargets
		);
	}//end testAPermittedChildCascadesAsATargetOfTheRootDelete()

	/**
	 * The caller's RBAC and organisation flags reach the child lookup.
	 *
	 * @return void
	 */
	public function testTheCallersFlagsReachTheChildLookup(): void {
		$this->childIs($this->entity(uuid: 'child-uuid', schema: '20'));

		$this->deleteParent(rbac: true, multitenancy: true);
		$this->deleteParent(rbac: false, multitenancy: false);

		$this->assertSame([['child-uuid', true, true], ['child-uuid', false, false]], $this->childLookups);
	}//end testTheCallersFlagsReachTheChildLookup()

	/**
	 * An id the property's table does not hold (another register, schema or organisation) refuses the delete.
	 *
	 * @return void
	 */
	public function testAChildOutsideItsPropertysTableRefusesTheDelete(): void {
		$this->childIs(null);
		$this->expectNothingWritten();

		try {
			$this->deleteParent();
			$this->fail('The delete went ahead.');
		} catch (ReferentialIntegrityException $e) {
			$blocker = $e->getAnalysis()->blockers[0];
			$this->assertSame('out_of_scope', $blocker['reason']);
			$this->assertSame('kid', $blocker['property']);
			$this->assertSame('', $blocker['objectUuid']);
		}
	}//end testAChildOutsideItsPropertysTableRefusesTheDelete()

	/**
	 * A property whose schema does not resolve refuses the delete.
	 *
	 * @return void
	 */
	public function testAnUnresolvablePropertyTableRefusesTheDelete(): void {
		$this->integrity->method('cascadePropertyTable')->willReturn(null);
		$this->objectMapper->expects($this->never())->method('find');
		$this->expectNothingWritten();

		$this->expectException(ReferentialIntegrityException::class);
		$this->deleteParent();
	}//end testAnUnresolvablePropertyTableRefusesTheDelete()

	/**
	 * A child the caller may not delete refuses the delete.
	 *
	 * @return void
	 */
	public function testAChildTheCallerMayNotDeleteRefusesTheDelete(): void {
		$this->childIs($this->entity(uuid: 'child-uuid', schema: '20'));
		$this->permissions->method('checkPermission')->willThrowException(new \Exception('Not allowed to delete'));
		$this->expectNothingWritten();

		$this->expectExceptionMessage('Not allowed to delete');
		$this->deleteParent();
	}//end testAChildTheCallerMayNotDeleteRefusesTheDelete()

	/**
	 * A child on an append-only schema refuses the delete.
	 *
	 * @return void
	 */
	public function testAChildOnAnAppendOnlySchemaRefusesTheDelete(): void {
		$this->childSchema->setAppendOnly(true);
		$this->childIs($this->entity(uuid: 'child-uuid', schema: '20'));
		$this->expectNothingWritten();

		$this->expectException(AppendOnlyException::class);
		$this->deleteParent();
	}//end testAChildOnAnAppendOnlySchemaRefusesTheDelete()

	/**
	 * A transferred child refuses the delete.
	 *
	 * @return void
	 */
	public function testATransferredChildRefusesTheDelete(): void {
		$child = $this->entity(uuid: 'child-uuid', schema: '20');
		$child->setRetention(['archiefstatus' => 'overgebracht']);
		$this->childIs($child);
		$this->expectNothingWritten();

		$this->expectException(DoesNotExistException::class);
		$this->expectExceptionMessage('OBJECT_TRANSFERRED');
		$this->deleteParent();
	}//end testATransferredChildRefusesTheDelete()

	/**
	 * A child in shared master data the caller's organisation only consumes refuses the delete.
	 *
	 * @return void
	 */
	public function testAConsumedSharedMasterDataChildRefusesTheDelete(): void {
		$shared = $this->createMock(SharedMasterDataService::class);
		$shared->method('assertWritable')->willThrowException(new SharedMasterDataWriteException(holderUuid: 'holder-org'));
		$organisations = $this->createMock(OrganisationServiceInterface::class);
		$organisations->method('getUserActiveOrganisations')->willReturn(['consumer-org']);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturnMap(
			[
				[SharedMasterDataService::class, $shared],
				[OrganisationServiceInterface::class, $organisations],
			]
		);
		$this->childIs($this->entity(uuid: 'child-uuid', schema: '20'));
		$this->expectNothingWritten();

		$this->expectException(SharedMasterDataWriteException::class);
		$this->deleteParent();
	}//end testAConsumedSharedMasterDataChildRefusesTheDelete()

	/**
	 * A child already in the trash is not asked again and not cascaded.
	 *
	 * @return void
	 */
	public function testADeletedChildIsLeftOut(): void {
		$child = $this->entity(uuid: 'child-uuid', schema: '20');
		$child->setDeleted(['deletedBy' => 'someone']);
		$this->childIs($child);
		$this->permissions->expects($this->never())->method('checkPermission');
		$this->integrity->expects($this->never())->method('applyDeletionActions');

		$this->assertTrue($this->deleteParent());
	}//end testADeletedChildIsLeftOut()

	/**
	 * When the root delete fails, the cascade rolls back with it.
	 *
	 * @return void
	 */
	public function testTheChildrenRollBackWhenTheRootDeleteFails(): void {
		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->objectMapper->method('findAcrossAllSources')->willReturn(
			['object' => $this->parent, 'register' => $this->register, 'schema' => $this->parentSchema]
		);
		$this->objectMapper->method('update')->willThrowException(new RuntimeException('write failed'));
		$this->childIs($this->entity(uuid: 'child-uuid', schema: '20'));
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		$this->expectExceptionMessage('write failed');
		$this->deleteParent();
	}//end testTheChildrenRollBackWhenTheRootDeleteFails()
}//end class
