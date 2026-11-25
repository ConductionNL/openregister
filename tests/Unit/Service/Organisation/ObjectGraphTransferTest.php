<?php

/**
 * ObjectGraphTransfer: moving an object graph between organisations under a per-type policy.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Organisation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md#requirement-an-object-graph-moves-between-organisations-under-a-per-type-policy-req-sle-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Organisation;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\AuditEntry;
use OCA\OpenRegister\Kernel\Contracts\Core\AuditEntryWriterInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\Object\CopyPayload;
use OCA\OpenRegister\Service\Organisation\ObjectGraphTransfer;
use OCA\OpenRegister\Service\Organisation\ObjectGraphTransferRefusal;
use OCA\OpenRegister\Service\Relation\AffectedSet;
use OCA\OpenRegister\Service\Relation\AffectedSetQuery;
use OCA\OpenRegister\Service\Relation\AffectedSets;
use OCA\OpenRegister\Service\Relation\GraphExportOptions;
use OCA\OpenRegister\Service\Relation\RelationGraphServiceInterface;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the previewed, approved and audited object graph transfer.
 *
 * The graph: a case (root) in organisation A, a document and a note that
 * belong to the case in A, and a shared party held by organisation C.
 */
#[CoversClass(ObjectGraphTransfer::class)]
#[CoversClass(ObjectGraphTransferRefusal::class)]
#[UsesClass(ObjectEntity::class)]
#[UsesClass(Organisation::class)]
#[UsesClass(Schema::class)]
#[UsesClass(AuditEntry::class)]
#[UsesClass(CopyPayload::class)]
#[UsesClass(AffectedSet::class)]
#[UsesClass(AffectedSetQuery::class)]
#[UsesClass(AffectedSets::class)]
#[UsesClass(GraphExportOptions::class)]
#[UsesClass(\OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions::class)]
#[UsesClass(\OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget::class)]
#[UsesClass(\OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions::class)]
#[UsesClass(\OCA\OpenRegister\Kernel\Contracts\Value\SaveActor::class)]
#[UsesClass(\OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy::class)]
class ObjectGraphTransferTest extends TestCase {

	private const ORG_A = 'aaaaaaaa-0000-4000-8000-000000000001';

	private const ORG_B = 'bbbbbbbb-0000-4000-8000-000000000002';

	private const ORG_C = 'cccccccc-0000-4000-8000-000000000003';

	private const CASE = '11111111-1111-4111-8111-111111111111';

	private const DOC = '22222222-2222-4222-8222-222222222222';

	private const NOTE = '33333333-3333-4333-8333-333333333333';

	private const PARTY = '44444444-4444-4444-8444-444444444444';

	private const POLICIES = ['10' => 'move', '11' => 'drop', '12' => 'copy'];

	/**
	 * The objects by uuid.
	 *
	 * @var array<string, ObjectEntity>
	 */
	private array $entities = [];

	/**
	 * The read and save path.
	 *
	 * @var ObjectOperationsInterface&MockObject
	 */
	private ObjectOperationsInterface $objects;

	/**
	 * The relation walk.
	 *
	 * @var RelationGraphServiceInterface&MockObject
	 */
	private RelationGraphServiceInterface $graphs;

	/**
	 * The audit trail.
	 *
	 * @var AuditEntryWriterInterface&MockObject
	 */
	private AuditEntryWriterInterface $audit;

	/**
	 * The transaction.
	 *
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection $db;

	/**
	 * The organisations.
	 *
	 * @var OrganisationMapperInterface&MockObject
	 */
	private OrganisationMapperInterface $organisations;

	/**
	 * Set up the graph and the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->entities = [
			self::CASE => $this->entity(uuid: self::CASE, schema: '9', organisation: self::ORG_A, data: ['title' => 'Bezwaar']),
			self::DOC => $this->entity(uuid: self::DOC, schema: '10', organisation: self::ORG_A, data: ['name' => 'Bezwaarschrift']),
			self::NOTE => $this->entity(uuid: self::NOTE, schema: '11', organisation: self::ORG_A, data: ['text' => 'Intern']),
			self::PARTY => $this->entity(uuid: self::PARTY, schema: '12', organisation: self::ORG_C, data: ['name' => 'J. Jansen', 'id' => self::PARTY]),
		];

		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->objects->method('find')->willReturnCallback(
			fn (string|int $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true): ?ObjectEntity => $this->entities[(string)$id] ?? null
		);
		$this->graphs = $this->createMock(RelationGraphServiceInterface::class);
		$this->graphs->method('graph')->willReturnCallback(fn (): array => $this->graph());
		$this->audit = $this->createMock(AuditEntryWriterInterface::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->organisations = $this->createMock(OrganisationMapperInterface::class);
		$this->organisations->method('findByUuid')->willReturnCallback(
			static function (string $uuid): Organisation {
				if ($uuid !== self::ORG_B) {
					throw new RuntimeException('not found');
				}

				return new Organisation();
			}
		);
	}//end setUp()

	/**
	 * An object entity.
	 *
	 * @param string               $uuid         The uuid.
	 * @param string               $schema       The schema id.
	 * @param string               $organisation The organisation uuid.
	 * @param array<string, mixed> $data         The data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, string $schema, string $organisation, array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('3');
		$object->setSchema($schema);
		$object->setOrganisation($organisation);
		$object->setObject($data);
		return $object;
	}//end entity()

	/**
	 * The relation walk's answer around the case.
	 *
	 * @param array<string, mixed> $override Keys to replace.
	 *
	 * @return array<string, mixed>
	 */
	private function graph(array $override = []): array {
		$nodes = [];
		foreach ($this->entities as $uuid => $entity) {
			$nodes[] = ['uuid' => $uuid, 'register' => '3', 'schema' => $entity->getSchema(), 'resolved' => true];
		}

		return array_replace(
			[
				'root' => self::CASE,
				'nodes' => $nodes,
				'edges' => [
					['from' => self::DOC, 'to' => self::CASE, 'type' => 'belongsTo', 'direction' => 'incoming'],
					['from' => self::NOTE, 'to' => self::CASE, 'type' => 'about', 'direction' => 'incoming'],
					['from' => self::CASE, 'to' => self::PARTY, 'type' => 'requester'],
				],
				'truncated' => false,
			],
			$override
		);
	}//end graph()

	/**
	 * The transfer service.
	 *
	 * @return ObjectGraphTransfer
	 */
	private function transfer(): ObjectGraphTransfer {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$user->method('getDisplayName')->willReturn('Beheerder');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn(new Schema());

		return new ObjectGraphTransfer(
			objects: $this->objects,
			graphs: $this->graphs,
			organisations: $this->organisations,
			schemas: $schemas,
			audit: $this->audit,
			db: $this->db,
			userSession: $session
		);
	}//end transfer()

	/**
	 * The preview lists the root and every reached object with its policy, and its digest is stable.
	 *
	 * @return void
	 */
	public function testThePreviewListsEveryObjectWithItsPolicy(): void {
		$plan = $this->transfer()->preview(root: $this->entities[self::CASE], target: self::ORG_B, policies: self::POLICIES);

		$this->assertSame(self::CASE, $plan['root']);
		$this->assertSame(self::ORG_A, $plan['source']);
		$this->assertSame(self::ORG_B, $plan['target']);
		$policies = array_column($plan['objects'], 'policy', 'uuid');
		$this->assertSame([self::CASE => 'move', self::DOC => 'move', self::NOTE => 'drop', self::PARTY => 'copy'], $policies);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $plan['digest']);
		$this->assertSame($plan['digest'], $this->transfer()->preview(root: $this->entities[self::CASE], target: self::ORG_B, policies: self::POLICIES)['digest']);
		$this->assertNotSame(
			$plan['digest'],
			$this->transfer()->preview(root: $this->entities[self::CASE], target: self::ORG_B, policies: ['10' => 'copy'] + self::POLICIES)['digest']
		);
	}

	/**
	 * A reached schema without a policy refuses the preview and names the schema.
	 *
	 * @return void
	 */
	public function testAReachedSchemaWithoutAPolicyIsRefused(): void {
		$this->expectException(ObjectGraphTransferRefusal::class);
		$this->expectExceptionCode(400);
		$this->expectExceptionMessage('11');

		$this->transfer()->preview(root: $this->entities[self::CASE], target: self::ORG_B, policies: ['10' => 'move', '12' => 'copy']);
	}

	/**
	 * Moving an object another organisation holds is refused: it would take it from that organisation.
	 *
	 * @return void
	 */
	public function testMovingAnotherOrganisationsObjectIsRefused(): void {
		$this->expectException(ObjectGraphTransferRefusal::class);
		$this->expectExceptionCode(400);
		$this->expectExceptionMessage(self::PARTY);

		$this->transfer()->preview(root: $this->entities[self::CASE], target: self::ORG_B, policies: ['12' => 'move'] + self::POLICIES);
	}

	/**
	 * A policy other than move, copy or drop, a missing or unknown target, and the current organisation are refused.
	 *
	 * @return void
	 */
	public function testABadPolicyOrTargetIsRefused(): void {
		$cases = [
			[self::ORG_B, ['11' => 'archive'] + self::POLICIES, 400],
			['', self::POLICIES, 400],
			[self::ORG_A, self::POLICIES, 400],
			[self::ORG_C, self::POLICIES, 404],
		];
		foreach ($cases as [$target, $policies, $status]) {
			try {
				$this->transfer()->preview(root: $this->entities[self::CASE], target: $target, policies: $policies);
				$this->fail('Expected a refusal for target '.$target);
			} catch (ObjectGraphTransferRefusal $e) {
				$this->assertSame($status, $e->getCode(), $e->getMessage());
			}
		}
	}

	/**
	 * A walk that stopped early, or that reached an object the caller may not read, refuses.
	 *
	 * @return void
	 */
	public function testAPartialGraphIsRefused(): void {
		$graphs = $this->createMock(RelationGraphServiceInterface::class);
		$graphs->method('graph')->willReturnOnConsecutiveCalls(
			$this->graph(override: ['truncated' => true]),
			$this->graph(override: ['nodes' => [['uuid' => self::CASE, 'resolved' => true], ['uuid' => self::DOC, 'resolved' => false]]])
		);
		$this->graphs = $graphs;

		foreach ([400, 403] as $status) {
			try {
				$this->transfer()->preview(root: $this->entities[self::CASE], target: self::ORG_B, policies: self::POLICIES);
				$this->fail('Expected a refusal');
			} catch (ObjectGraphTransferRefusal $e) {
				$this->assertSame($status, $e->getCode());
			}
		}
	}

	/**
	 * Without an approval, or with the digest of another plan, nothing is written.
	 *
	 * @return void
	 */
	public function testAnUnapprovedTransferDoesNotRun(): void {
		$this->objects->expects($this->never())->method('saveObject');
		$this->db->expects($this->never())->method('beginTransaction');
		$this->audit->expects($this->never())->method('writeAuditEntries');

		foreach (['', str_repeat('0', 64)] as $approval) {
			try {
				$this->transfer()->transfer(root: $this->entities[self::CASE], target: self::ORG_B, policies: self::POLICIES, approval: $approval);
				$this->fail('Expected a refusal');
			} catch (ObjectGraphTransferRefusal $e) {
				$this->assertSame(409, $e->getCode());
			}
		}
	}

	/**
	 * The approved transfer copies the party, then moves the case and the document, leaves the note, and audits both trails.
	 *
	 * @return void
	 */
	public function testTheApprovedTransferMovesCopiesDropsAndAuditsBothOrganisations(): void {
		$approval = $this->transfer()->preview(root: $this->entities[self::CASE], target: self::ORG_B, policies: self::POLICIES)['digest'];

		$saves = [];
		$copy = $this->entity(uuid: '55555555-5555-4555-8555-555555555555', schema: '12', organisation: self::ORG_B, data: []);
		$this->objects->expects($this->exactly(3))->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ObjectSaveOptions $options) use (&$saves, $copy): ObjectEntity {
				$saves[] = ['object' => $object, 'uuid' => $options->uuid];
				return $options->uuid === null ? $copy : $this->entities[$options->uuid];
			}
		);
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');
		$written = [];
		$this->audit->expects($this->once())->method('writeAuditEntries')->willReturnCallback(
			static function (array $entries) use (&$written): void {
				$written = $entries;
			}
		);

		$done = $this->transfer()->transfer(root: $this->entities[self::CASE], target: self::ORG_B, policies: self::POLICIES, approval: $approval);

		$this->assertSame([self::CASE, self::DOC], $done['moved']);
		$this->assertSame([['from' => self::PARTY, 'to' => '55555555-5555-4555-8555-555555555555']], $done['copied']);
		$this->assertSame([self::NOTE], $done['dropped']);

		// The copy is written first and is a new object (no uuid), without the original's identity.
		$this->assertNull($saves[0]['uuid']);
		$this->assertSame(['name' => 'J. Jansen', '@self' => ['organisation' => self::ORG_B]], $saves[0]['object']);
		// The moves keep their uuid and carry the target organisation.
		$this->assertSame(self::CASE, $saves[1]['uuid']);
		$this->assertSame(['id' => self::CASE, 'title' => 'Bezwaar', '@self' => ['organisation' => self::ORG_B]], $saves[1]['object']);
		$this->assertSame(self::DOC, $saves[2]['uuid']);

		$this->assertCount(2, $written);
		$this->assertSame([ObjectGraphTransfer::ACTION_OUT, ObjectGraphTransfer::ACTION_IN], [$written[0]->action, $written[1]->action]);
		$this->assertSame([self::ORG_A, self::ORG_B], [$written[0]->organisationId, $written[1]->organisationId]);
		$this->assertSame($done['correlation'], $written[0]->changed['correlation']);
		$this->assertSame($done['correlation'], $written[1]->changed['correlation']);
		$this->assertSame(self::CASE, $written[0]->objectUuid);
		$this->assertSame('admin', $written[1]->user);
		$this->assertSame([self::NOTE], $written[1]->changed['dropped']);
	}

	/**
	 * A write that fails rolls the transaction back, rethrows, and writes no audit entry.
	 *
	 * @return void
	 */
	public function testAFailedWriteRollsBack(): void {
		$approval = $this->transfer()->preview(root: $this->entities[self::CASE], target: self::ORG_B, policies: self::POLICIES)['digest'];
		$this->objects->method('saveObject')->willThrowException(new DbException('disk full'));
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		$this->audit->expects($this->never())->method('writeAuditEntries');

		$this->expectException(DbException::class);

		$this->transfer()->transfer(root: $this->entities[self::CASE], target: self::ORG_B, policies: self::POLICIES, approval: $approval);
	}
}
