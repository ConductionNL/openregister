<?php

/**
 * OpenRegister Object Graph Transfer
 *
 * Moves an object and the objects its relations reach from one organisation to
 * another, under a policy per object type, previewed first and run only on the
 * approved preview.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Organisation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md#requirement-an-object-graph-moves-between-organisations-under-a-per-type-policy-req-sle-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Organisation;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\AuditEntry;
use OCA\OpenRegister\Kernel\Contracts\Core\AuditEntryWriterInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\Object\CopyPayload;
use OCA\OpenRegister\Service\Relation\AffectedSetQuery;
use OCA\OpenRegister\Service\Relation\AffectedSets;
use OCA\OpenRegister\Service\Relation\GraphExportOptions;
use OCA\OpenRegister\Service\Relation\RelationGraphServiceInterface;
use OCP\IDBConnection;
use OCP\IUserSession;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Previews and runs the move of an object graph between organisations (D-3, D-4).
 *
 * Every object a relation reaches from the root takes the policy named for its
 * schema: `move` (it changes organisation), `copy` (the target gets its own
 * copy and the original stays) or `drop` (it is not carried and stays where it
 * is). The root always moves. Nothing is defaulted: an object whose schema has
 * no policy refuses the preview, and so does a `move` of an object that does
 * not belong to the root's organisation, because moving it would take it from
 * a third organisation. A walk that stopped early, or that reached an object
 * the caller may not read, refuses too: a partial graph would be a partial move
 * that reads as a whole one.
 *
 * The preview answers the plan and its digest. Approving the preview is
 * running the transfer with that digest: the plan is computed again, and when
 * it is not the same plan the transfer is refused and nothing is written. The
 * transfer runs in one transaction and writes one entry on each organisation's
 * audit trail, both carrying the same correlation.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) AffectedSets::deriver() is the affected-set factory the relations routes use.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The transfer composes the read, save, graph, audit and transaction ports.
 *
 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md#requirement-an-object-graph-moves-between-organisations-under-a-per-type-policy-req-sle-002
 */
class ObjectGraphTransfer {

	/**
	 * The object changes organisation.
	 *
	 * @var string
	 */
	public const POLICY_MOVE = 'move';

	/**
	 * The target organisation gets a copy; the original stays.
	 *
	 * @var string
	 */
	public const POLICY_COPY = 'copy';

	/**
	 * The object is not carried and stays in its organisation.
	 *
	 * @var string
	 */
	public const POLICY_DROP = 'drop';

	/**
	 * The policies an object type may carry.
	 *
	 * @var list<string>
	 */
	public const POLICIES = [self::POLICY_MOVE, self::POLICY_COPY, self::POLICY_DROP];

	/**
	 * The audit action in the organisation the graph leaves.
	 *
	 * @var string
	 */
	public const ACTION_OUT = 'organisation.transfer.out';

	/**
	 * The audit action in the organisation the graph arrives in.
	 *
	 * @var string
	 */
	public const ACTION_IN = 'organisation.transfer.in';

	/**
	 * The deepest relation walk a transfer may ask for.
	 *
	 * @var int
	 */
	public const MAX_DEPTH = 3;

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface     $objects       Reads and saves the objects.
	 * @param RelationGraphServiceInterface $graphs        The bounded relation walk.
	 * @param OrganisationMapperInterface   $organisations Resolves the target organisation.
	 * @param SchemaMapperInterface         $schemas       The schema a copy is built for.
	 * @param AuditEntryWriterInterface     $audit         Writes the entry on both trails.
	 * @param IDBConnection                 $db            Runs the transfer as one transaction.
	 * @param IUserSession                  $userSession   The acting administrator.
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md#requirement-an-object-graph-moves-between-organisations-under-a-per-type-policy-req-sle-002
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objects,
		private readonly RelationGraphServiceInterface $graphs,
		private readonly OrganisationMapperInterface $organisations,
		private readonly SchemaMapperInterface $schemas,
		private readonly AuditEntryWriterInterface $audit,
		private readonly IDBConnection $db,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * The plan: every object the transfer reaches with the policy that applies to it, and the plan's digest.
	 *
	 * @param ObjectEntity          $root     The root, already read by the caller.
	 * @param string                $target   The uuid of the organisation it moves to.
	 * @param array<string, string> $policies The policy per schema id.
	 * @param int                   $depth    How far the relations are followed (1 to MAX_DEPTH).
	 *
	 * @return array<string, mixed> `root`, `source`, `target`, `objects` (uuid, register, schema, organisation, policy each) and `digest`.
	 *
	 * @throws ObjectGraphTransferRefusal When the plan cannot be made, naming why.
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md#requirement-an-object-graph-moves-between-organisations-under-a-per-type-policy-req-sle-002
	 */
	public function preview(ObjectEntity $root, string $target, array $policies, int $depth = 1): array {
		$source = (string)$root->getOrganisation();
		$this->checkTarget(source: $source, target: $target);
		$this->checkPolicies(policies: $policies);

		$rows = [self::row(object: $root, policy: self::POLICY_MOVE)];
		$missing = [];
		$foreign = [];
		foreach ($this->reached(root: $root, depth: $depth) as $object) {
			$schema = (string)$object->getSchema();
			if (array_key_exists($schema, $policies) === false) {
				$missing[$schema] = $schema;
				continue;
			}

			if ($policies[$schema] === self::POLICY_MOVE && (string)$object->getOrganisation() !== $source) {
				$foreign[] = (string)$object->getUuid();
			}

			$rows[] = self::row(object: $object, policy: $policies[$schema]);
		}

		if ($missing !== []) {
			throw new ObjectGraphTransferRefusal(
				'Name a policy (move, copy or drop) for the schemas the transfer reaches: '.implode(', ', array_values($missing)),
				400
			);
		}

		if ($foreign !== []) {
			throw new ObjectGraphTransferRefusal(
				'These objects belong to another organisation than the one the transfer leaves and cannot be moved; copy or drop them: '
				.implode(', ', $foreign),
				400
			);
		}

		$plan = ['root' => (string)$root->getUuid(), 'source' => $source, 'target' => $target, 'objects' => $rows];

		return array_merge($plan, ['digest' => self::digest(plan: $plan)]);
	}//end preview()

	/**
	 * Run the approved plan: copy, then move, in one transaction, audited in both organisations.
	 *
	 * @param ObjectEntity          $root     The root, already read by the caller.
	 * @param string                $target   The uuid of the organisation it moves to.
	 * @param array<string, string> $policies The policy per schema id.
	 * @param string                $approval The digest of the preview the caller approved.
	 * @param int                   $depth    How far the relations are followed.
	 *
	 * @return array{correlation: string, moved: list<string>, copied: list<array{from: string, to: string}>, dropped: list<string>}
	 *
	 * @throws ObjectGraphTransferRefusal When the plan cannot be made or is not the approved one; nothing is written.
	 * @throws Throwable                  When a write fails; the transaction is rolled back.
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md#requirement-an-object-graph-moves-between-organisations-under-a-per-type-policy-req-sle-002
	 */
	public function transfer(ObjectEntity $root, string $target, array $policies, string $approval, int $depth = 1): array {
		if ($approval === '') {
			throw new ObjectGraphTransferRefusal('A transfer runs only on an approved preview: send the preview\'s digest as approval', 409);
		}

		$plan = $this->preview(root: $root, target: $target, policies: $policies, depth: $depth);
		if (hash_equals($plan['digest'], $approval) === false) {
			throw new ObjectGraphTransferRefusal('The graph or the policies changed since the approved preview: preview again and approve the new plan', 409);
		}

		$done = ['correlation' => Uuid::v4()->toRfc4122(), 'moved' => [], 'copied' => [], 'dropped' => []];
		$this->db->beginTransaction();
		try {
			$done = $this->apply(plan: $plan, done: $done);
			$this->record(plan: $plan, done: $done);
			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return $done;
	}//end transfer()

	/**
	 * Write the plan's copies first (they read the originals), then its moves.
	 *
	 * @param array<string, mixed> $plan The plan.
	 * @param array<string, mixed> $done The result so far.
	 *
	 * @return array{correlation: string, moved: list<string>, copied: list<array{from: string, to: string}>, dropped: list<string>}
	 */
	private function apply(array $plan, array $done): array {
		$entities = [];
		foreach ($plan['objects'] as $row) {
			$entities[$row['uuid']] = $this->read(uuid: $row['uuid'], register: $row['register'], schema: $row['schema']);
			if ($row['policy'] === self::POLICY_DROP) {
				$done['dropped'][] = $row['uuid'];
			}
		}

		foreach ($plan['objects'] as $row) {
			if ($row['policy'] === self::POLICY_COPY) {
				$copy = $this->copy(object: $entities[$row['uuid']], target: $plan['target']);
				$done['copied'][] = ['from' => $row['uuid'], 'to' => (string)$copy->getUuid()];
			}
		}

		foreach ($plan['objects'] as $row) {
			if ($row['policy'] === self::POLICY_MOVE) {
				$this->move(object: $entities[$row['uuid']], target: $plan['target']);
				$done['moved'][] = $row['uuid'];
			}
		}

		return $done;
	}//end apply()

	/**
	 * The objects the relations reach from the root, read by the caller; the root itself is not among them.
	 *
	 * @param ObjectEntity $root  The root.
	 * @param int          $depth How far the relations are followed.
	 *
	 * @return list<ObjectEntity>
	 *
	 * @throws ObjectGraphTransferRefusal When the walk stopped early or reached an object the caller may not read.
	 */
	private function reached(ObjectEntity $root, int $depth): array {
		$depth = max(1, min(self::MAX_DEPTH, $depth));
		$graph = $this->graphs->graph(rootUuid: (string)$root->getUuid(), options: new GraphExportOptions(depth: $depth));
		if (($graph['truncated'] ?? false) === true) {
			throw new ObjectGraphTransferRefusal('The relation walk stopped before it reached every object; a transfer of part of the graph is refused', 400);
		}

		foreach ((array)($graph['nodes'] ?? []) as $node) {
			if (is_array($node) === true && ($node['resolved'] ?? false) !== true && ($node['external'] ?? false) !== true) {
				throw new ObjectGraphTransferRefusal('The transfer reaches an object you may not read: '.(string)($node['uuid'] ?? ''), 403);
			}
		}

		$set = AffectedSets::deriver()->derive(graph: $graph, query: new AffectedSetQuery());
		$objects = [];
		foreach (array_merge((array)($set['objects'] ?? []), (array)($set['parties'] ?? [])) as $node) {
			if (($node['external'] ?? false) === true || isset($node['uuid'], $node['schema']) === false) {
				continue;
			}

			$objects[] = $this->read(uuid: (string)$node['uuid'], register: (string)($node['register'] ?? ''), schema: (string)$node['schema']);
		}

		return $objects;
	}//end reached()

	/**
	 * One object, read by the caller.
	 *
	 * @param string $uuid     The object.
	 * @param string $register Its register id.
	 * @param string $schema   Its schema id.
	 *
	 * @return ObjectEntity
	 *
	 * @throws ObjectGraphTransferRefusal When the caller may not read it.
	 */
	private function read(string $uuid, string $register, string $schema): ObjectEntity {
		try {
			$object = $this->objects->find(
				id: $uuid,
				options: new ObjectFindOptions(register: $register, schema: $schema, render: false, audit: false),
				_rbac: true,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$object = null;
		}

		if ($object === null) {
			throw new ObjectGraphTransferRefusal('The transfer reaches an object you may not read: '.$uuid, 403);
		}

		return $object;
	}//end read()

	/**
	 * Move one object: the same object, saved under the target organisation.
	 *
	 * @param ObjectEntity $object The object.
	 * @param string       $target The target organisation uuid.
	 *
	 * @return void
	 */
	private function move(ObjectEntity $object, string $target): void {
		$data = $object->getObject();
		$data['@self'] = ['organisation' => $target];
		$this->objects->saveObject(
			object: $data,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: (string)$object->getRegister(), schema: (string)$object->getSchema()),
				uuid: (string)$object->getUuid()
			),
			_rbac: true,
			_multitenancy: false
		);
	}//end move()

	/**
	 * Copy one object into the target organisation, through the copy payload a record copy uses.
	 *
	 * @param ObjectEntity $object The original.
	 * @param string       $target The target organisation uuid.
	 *
	 * @return ObjectEntity The copy.
	 */
	private function copy(ObjectEntity $object, string $target): ObjectEntity {
		$schema = $this->schemas->find((string)$object->getSchema(), _rbac: false, _multitenancy: false);
		$data = (new CopyPayload())->of(source: $object->getObject(), overrides: [], schema: $schema);
		$data['@self'] = ['organisation' => $target];

		return $this->objects->saveObject(
			object: $data,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: (string)$object->getRegister(), schema: (string)$object->getSchema())
			),
			_rbac: true,
			_multitenancy: false
		);
	}//end copy()

	/**
	 * Write the transfer on both organisations' audit trails under one correlation (D-4).
	 *
	 * @param array<string, mixed> $plan The plan.
	 * @param array<string, mixed> $done What was done.
	 *
	 * @return void
	 */
	private function record(array $plan, array $done): void {
		$user = $this->userSession->getUser();
		$changed = [
			'correlation' => $done['correlation'],
			'from' => $plan['source'],
			'to' => $plan['target'],
			'moved' => $done['moved'],
			'copied' => $done['copied'],
			'dropped' => $done['dropped'],
		];
		$entries = [];
		foreach ([self::ACTION_OUT => $plan['source'], self::ACTION_IN => $plan['target']] as $action => $organisation) {
			$entries[] = new AuditEntry(
				uuid: Uuid::v4()->toRfc4122(),
				action: $action,
				user: $user?->getUID() ?? 'system',
				userName: $user?->getDisplayName() ?? 'system',
				changed: $changed,
				created: new DateTimeImmutable(),
				objectUuid: $plan['root'],
				organisationId: $organisation,
			);
		}

		$this->audit->writeAuditEntries(entries: $entries);
	}//end record()

	/**
	 * Refuse a target that does not exist or is the organisation the root is already in.
	 *
	 * @param string $source The root's organisation.
	 * @param string $target The target organisation uuid.
	 *
	 * @return void
	 *
	 * @throws ObjectGraphTransferRefusal
	 */
	private function checkTarget(string $source, string $target): void {
		if ($target === '') {
			throw new ObjectGraphTransferRefusal('Name the organisation the object moves to (targetOrganisation)', 400);
		}

		if ($target === $source) {
			throw new ObjectGraphTransferRefusal('The object already belongs to that organisation', 400);
		}

		try {
			$this->organisations->findByUuid($target);
		} catch (Throwable $e) {
			throw new ObjectGraphTransferRefusal('Organisation not found: '.$target, 404);
		}
	}//end checkTarget()

	/**
	 * Refuse a policy that is not move, copy or drop, naming the schema.
	 *
	 * @param array<string, string> $policies The policy per schema id.
	 *
	 * @return void
	 *
	 * @throws ObjectGraphTransferRefusal
	 */
	private function checkPolicies(array $policies): void {
		foreach ($policies as $schema => $policy) {
			if (in_array($policy, self::POLICIES, true) === false) {
				throw new ObjectGraphTransferRefusal('The policy for schema '.$schema.' must be move, copy or drop', 400);
			}
		}
	}//end checkPolicies()

	/**
	 * One plan row.
	 *
	 * @param ObjectEntity $object The object.
	 * @param string       $policy Its policy.
	 *
	 * @return array{uuid: string, register: string, schema: string, organisation: string|null, policy: string}
	 */
	private static function row(ObjectEntity $object, string $policy): array {
		return [
			'uuid' => (string)$object->getUuid(),
			'register' => (string)$object->getRegister(),
			'schema' => (string)$object->getSchema(),
			'organisation' => $object->getOrganisation(),
			'policy' => $policy,
		];
	}//end row()

	/**
	 * The digest that names exactly this plan.
	 *
	 * @param array<string, mixed> $plan The plan without its digest.
	 *
	 * @return string
	 */
	private static function digest(array $plan): string {
		return hash('sha256', (string)json_encode($plan));
	}//end digest()
}//end class
