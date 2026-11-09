<?php

/**
 * Object ownership service — who owns a record, and how it changes hands.
 *
 * A record has one named owner and may have one owning GROUP. The owner is
 * derived from the acting user when the record is created and is never taken
 * from the request body ({@see \OCA\OpenRegister\Service\Object\SaveObject}),
 * so ownership cannot be claimed by a caller: it is granted by this service,
 * under a check, and recorded.
 *
 * WHY THIS IS A SERVICE OF ITS OWN. The owner column is not writable through an
 * object save, and that is deliberate — a caller who can set the owner can grant
 * itself edit rights on somebody else's record. So the three legitimate ways the
 * owner changes each need their own checked entry point:
 *
 *  1. A COLLEAGUE TAKES IT OVER themselves ({@see claim()}), with no
 *     administrator involved. Anybody the rules already admit to EDIT the record
 *     may take it: the handover gives them nothing they did not already have, it
 *     only says out loud who is now answerable for it.
 *  2. An ADMINISTRATOR REASSIGNS one record or many ({@see assign()},
 *     {@see reassignMany()}), which is what a departure looks like in practice.
 *  3. The owner (or an administrator) names the OWNING GROUP
 *     ({@see setOwnerGroup()}), after which every member of that group edits the
 *     record on the same terms as the named owner.
 *
 * THE PREVIOUS OWNER IS TOLD. GPP-Woo's own manual warns that its handover is
 * silent, and a record leaving your hands without a word is how a publication
 * stops being anybody's responsibility. Every transfer notifies the person who
 * held it, and the notification names who took it.
 *
 * EVERY TRANSFER IS RECORDED, as its own typed audit action rather than as an
 * ordinary update, so "who handed this over, and to whom" is a question the trail
 * answers directly. The entry goes through {@see AuditTrailMapper}, which seals
 * it into the hash chain like any other; there is no second log.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
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
 * @spec openspec/specs/object-ownership/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Checked writes of an object's owner, recorded and announced.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A checked write needs the
 * session, the groups, the users, the rules, the storage, the trail and the
 * notification; splitting it would scatter one decision over several classes.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The complexity is the guard
 * clauses: four entry points, each refusing before it writes. Splitting them into
 * separate classes would put the refusals further from the write they protect,
 * which is the arrangement that produces a guard nothing calls.
 */
class ObjectOwnershipService implements ObjectOwnershipServiceInterface {

	/**
	 * The typed audit action one handover writes.
	 *
	 * Its own action rather than `update`, because an auditor asking who handed a
	 * record over cannot find it among every edit the record ever had.
	 *
	 * @var string
	 */
	public const AUDIT_ACTION = 'ownership_handover';

	/**
	 * The notification subject the previous owner receives.
	 *
	 * @var string
	 */
	public const NOTIFICATION_SUBJECT = 'object_ownership_changed';

	/**
	 * The administrator group.
	 *
	 * @var string
	 */
	private const ADMIN_GROUP = 'admin';

	/**
	 * How many generations of children one transfer walks.
	 *
	 * A depth rather than an unbounded walk: a relation graph in live data is not
	 * reliably a tree, and a cycle in it must not turn a handover into a scan of
	 * the register. The visited set already stops a cycle from repeating a row;
	 * this stops a long chain from being followed for minutes.
	 *
	 * @var integer
	 */
	private const MAX_CASCADE_DEPTH = 5;

	/**
	 * The acting user, which is where an owner comes from.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Who may name a record's owning group: the owner, an administrator,
	 *
	 * @var GrantManageGuard
	 */
	private readonly GrantManageGuard $grantManage;

	/**
	 * The rules, for "may this caller edit this record".
	 *
	 * @var PermissionCheckInterface
	 */
	private readonly PermissionCheckInterface $permissions;

	/**
	 * Resolves a named new owner, and their display name.
	 *
	 * @var IUserManager
	 */
	private readonly IUserManager $userManager;

	/**
	 * The caller's groups, and whether an owning group exists.
	 *
	 * @var IGroupManager
	 */
	private readonly IGroupManager $groupManager;

	/**
	 * The targeted write of the owner column.
	 *
	 * @var ObjectOwnerWriter
	 */
	private readonly ObjectOwnerWriter $ownerWriter;

	/**
	 * The targeted write of the authorization block.
	 *
	 * @var ObjectAuthorizationWriter
	 */
	private readonly ObjectAuthorizationWriter $authorizationWriter;

	/**
	 * The hash-sealed trail.
	 *
	 * @var AuditTrailMapperInterface
	 */
	private readonly AuditTrailMapperInterface $auditTrailMapper;

	/**
	 * Tells the previous owner.
	 *
	 * @var INotificationManager
	 */
	private readonly INotificationManager $notificationManager;

	/**
	 * Resolves objects and their children.
	 *
	 * @var MagicMapperInterface
	 */
	private readonly MagicMapperInterface $magicMapper;

	/**
	 * Resolves a child's register.
	 *
	 * @var RegisterMapperInterface
	 */
	private readonly RegisterMapperInterface $registerMapper;

	/**
	 * Resolves a child's schema.
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Where a transfer is noted.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Writes an audit-trail entry for a change of the owning group. Null only in legacy fixtures.
	 *
	 * @var GrantChangeAuditor|null
	 */
	private readonly ?GrantChangeAuditor $grantAuditor;

	/**
	 * Constructor.
	 *
	 * @param OwnershipGuards  $guards  The acting user, who may name an owning group, and the permission rules.
	 * @param OwnershipSources $sources The principal directory and the object lookup.
	 * @param OwnershipWriters $writers The owner and authorization writes, the sealed trail and the notification.
	 * @param OwnershipLog     $log     Where a transfer is noted and a change of the owning group audited.
	 */
	public function __construct(
		OwnershipGuards $guards,
		OwnershipSources $sources,
		OwnershipWriters $writers,
		OwnershipLog $log,
	) {
		$this->userSession = $guards->userSession;
		$this->grantManage = $guards->grantManage;
		$this->permissions = $guards->permissions;
		$this->userManager = $sources->directory->userManager;
		$this->groupManager = $sources->directory->groupManager;
		$this->ownerWriter = $writers->ownerWriter;
		$this->authorizationWriter = $writers->authorizationWriter;
		$this->auditTrailMapper = $writers->auditTrailMapper;
		$this->notificationManager = $writers->notificationManager;
		$this->magicMapper = $sources->lookup->magicMapper;
		$this->registerMapper = $sources->lookup->registerMapper;
		$this->schemaMapper = $sources->lookup->schemaMapper;
		$this->logger = $log->logger;
		$this->grantAuditor = $log->grantAuditor;
	}//end __construct()

	/**
	 * Take ownership of one record, as the acting user.
	 *
	 * THE NEW OWNER IS THE ACTING USER AND NOTHING ELSE. There is no parameter
	 * for it, which is the point: a method that accepted one would be a way to
	 * hand a record to somebody who never asked for it, and a way for a caller to
	 * put its own name on a record it may not edit.
	 *
	 * @param Register $register The record's register.
	 * @param Schema $schema The record's schema.
	 * @param ObjectEntity $object The record.
	 * @param bool $cascade Whether the change carries to the record's children.
	 *
	 * @throws NotAuthorizedException When nobody is signed in, or the rules refuse this caller the edit.
	 *
	 * @return array The outcome, as {@see transfer()} describes it.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The cascade is a property of the
	 * request, not a second behaviour: one record or its family, same transfer.
	 *
	 * @spec openspec/specs/object-ownership/spec.md
	 */
	public function claim(
		Register $register,
		Schema $schema,
		ObjectEntity $object,
		bool $cascade = false,
	): array {
		$actor = $this->callerUid();
		if ($actor === null) {
			throw new NotAuthorizedException(message: 'Sign in to take ownership of a record');
		}

		// The rules decide, not this service. Somebody the rules admit to EDIT
		// the record may take it over, which is what lets a colleague pick up a
		// publication without an administrator. Somebody they refuse cannot,
		// because a handover they could perform would hand them the edit rights
		// the refusal just withheld.
		if ($this->mayEdit(schema: $schema, object: $object) === false) {
			throw new NotAuthorizedException(
				message: 'Only somebody who may edit this record can take ownership of it'
			);
		}

		return $this->transfer(
			record: new OwnedRecord(register: $register, schema: $schema, object: $object),
			handover: new OwnershipHandover(newOwner: $actor, cascade: $cascade)
		);
	}//end claim()

	/**
	 * Assign one record to a named owner.
	 *
	 * Administrators only. An ordinary caller may take a record
	 * ({@see claim()}) and may not give one away: handing a colleague a record
	 * they have not accepted makes them answerable for something they may never
	 * see, and it would let anybody park a record on somebody else's name.
	 *
	 * @param Register $register The record's register.
	 * @param Schema $schema The record's schema.
	 * @param ObjectEntity $object The record.
	 * @param string $newOwner The uid of the new owner.
	 * @param bool $cascade Whether the change carries to the record's children.
	 *
	 * @throws NotAuthorizedException When the caller is not an administrator.
	 * @throws InvalidArgumentException When no such user exists.
	 *
	 * @return array The outcome, as {@see transfer()} describes it.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) As on claim().
	 *
	 * @spec openspec/specs/object-ownership/spec.md
	 */
	public function assign(
		Register $register,
		Schema $schema,
		ObjectEntity $object,
		string $newOwner,
		bool $cascade = false,
	): array {
		$this->requireAdmin();
		$this->requireExistingUser(uid: $newOwner);

		return $this->transfer(
			record: new OwnedRecord(register: $register, schema: $schema, object: $object),
			handover: new OwnershipHandover(newOwner: $newOwner, cascade: $cascade)
		);
	}//end assign()

	/**
	 * Reassign many records to one owner, in one action.
	 *
	 * ONE AUDIT ENTRY PER RECORD, never one for the batch. A single entry for
	 * fifty records cannot be found from any of them: an auditor reading one
	 * record's history would see it change hands with nothing saying so.
	 *
	 * ONE REFUSAL DOES NOT LOSE THE REST. A record that cannot be resolved, or
	 * whose write fails, is reported in `failed` and the remaining records are
	 * still moved — a bulk reassignment that stops halfway leaves an
	 * administrator with no way to know how far it got.
	 *
	 * @param string[] $identifiers The records, by uuid, id, slug or uri.
	 * @param string $newOwner The uid of the new owner.
	 * @param bool $cascade Whether the change carries to each record's children.
	 *
	 * @throws NotAuthorizedException When the caller is not an administrator.
	 * @throws InvalidArgumentException When no such user exists, or no record was named.
	 *
	 * @return array{newOwner: string, transferred: array, failed: array, unchanged: array}
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) As on claim().
	 *
	 * @spec openspec/specs/object-ownership/spec.md
	 */
	public function reassignMany(array $identifiers, string $newOwner, bool $cascade = false): array {
		$this->requireAdmin();
		$this->requireExistingUser(uid: $newOwner);

		if ($identifiers === []) {
			throw new InvalidArgumentException('Name at least one record to reassign');
		}

		$buckets = ['transferred' => [], 'unchanged' => [], 'failed' => []];

		foreach ($identifiers as $identifier) {
			$result = $this->reassignOne(identifier: $identifier, newOwner: $newOwner, cascade: $cascade);
			$buckets[$result['bucket']][] = $result['entry'];
		}

		$transferred = $buckets['transferred'];
		$unchanged = $buckets['unchanged'];
		$failed = $buckets['failed'];

		$this->logger->info(
			message: '[ObjectOwnershipService] Reassigned records in bulk',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'newOwner' => $newOwner,
				'transferred' => count($transferred),
				'unchanged' => count($unchanged),
				'failed' => count($failed),
			]
		);

		return [
			'newOwner' => $newOwner,
			'transferred' => $transferred,
			'unchanged' => $unchanged,
			'failed' => $failed,
		];
	}//end reassignMany()

	/**
	 * Reassign one record of a bulk run, never throwing.
	 *
	 * @param mixed $identifier The record's identifier, as the caller passed it.
	 * @param string $newOwner The new owner.
	 * @param bool $cascade Whether to carry the change to children.
	 *
	 * @return array{bucket: string, entry: mixed} Which result list the record lands in, and its entry.
	 */
	private function reassignOne(mixed $identifier, string $newOwner, bool $cascade): array {
		if (is_string($identifier) === false || $identifier === '') {
			return [
				'bucket' => 'failed',
				'entry' => ['identifier' => '', 'reason' => 'An empty identifier cannot be resolved'],
			];
		}

		try {
			return $this->reassignFound(identifier: $identifier, newOwner: $newOwner, cascade: $cascade);
		} catch (Throwable $e) {
			return ['bucket' => 'failed', 'entry' => ['identifier' => $identifier, 'reason' => $e->getMessage()]];
		}
	}//end reassignOne()

	/**
	 * Resolve one record by identifier and transfer it.
	 *
	 * @param string $identifier The record's identifier.
	 * @param string $newOwner The new owner.
	 * @param bool $cascade Whether to carry the change to children.
	 *
	 * @return array{bucket: string, entry: mixed} Which result list the record lands in, and its entry.
	 *
	 * @throws Throwable When the record cannot be found or transferred.
	 */
	private function reassignFound(string $identifier, string $newOwner, bool $cascade): array {
		$context = $this->magicMapper->findAcrossAllSources(identifier: $identifier);
		$register = $context['register'];
		$schema = $context['schema'];
		if (($register instanceof Register) === false || ($schema instanceof Schema) === false) {
			return [
				'bucket' => 'failed',
				'entry' => ['identifier' => $identifier, 'reason' => 'The record has no register or schema'],
			];
		}

		$outcome = $this->transfer(
			record: new OwnedRecord(register: $register, schema: $schema, object: $context['object']),
			handover: new OwnershipHandover(newOwner: $newOwner, cascade: $cascade)
		);

		if ($outcome['changed'] === false) {
			return ['bucket' => 'unchanged', 'entry' => $identifier];
		}

		return ['bucket' => 'transferred', 'entry' => $outcome];
	}//end reassignFound()

	/**
	 * Name the group that owns one record, or take the owning group away.
	 *
	 * Owner or administrator, through the same resolver the read side uses, so
	 * "who may name the owning group" and "who is admitted unconditionally"
	 * cannot drift apart.
	 *
	 * @param Register $register The record's register.
	 * @param Schema $schema The record's schema.
	 * @param ObjectEntity $object The record.
	 * @param string|null $group The group id, or null to remove the owning group.
	 *
	 * @throws NotAuthorizedException When the caller is neither owner nor administrator.
	 * @throws InvalidArgumentException When no such group exists.
	 *
	 * @return array The stored authorization block.
	 *
	 * @spec openspec/specs/object-ownership/spec.md
	 */
	public function setOwnerGroup(
		Register $register,
		Schema $schema,
		ObjectEntity $object,
		?string $group,
	): array {
		$this->requireMayNameOwnerGroup(object: $object, group: $group);

		$this->requireKnownGroup(group: $group);

		// Read-modify-write ONE key, exactly as the scope is written: an action
		// override or a scope in the same block must survive this.
		$block = ($object->getAuthorization() ?? []);
		$before = $this->groupNameOf(value: ($block[ObjectScopeResolver::OWNER_GROUP_KEY] ?? null));

		$block = $this->withOwnerGroup(block: $block, group: $group);

		$this->authorizationWriter->writeAuthorizationBlock(
			register: $register,
			schema: $schema,
			objectUuid: (string)$object->getUuid(),
			block: $block
		);

		$object->setAuthorization($block);

		$this->grantAuditor?->recordOwnerGroup(
			object: $object,
			before: $before,
			after: $this->groupNameOf(value: ($block[ObjectScopeResolver::OWNER_GROUP_KEY] ?? null))
		);

		return $block;
	}//end setOwnerGroup()

	/**
	 * Refuse an owning group that does not exist; null or '' clears and passes.
	 *
	 * @param string|null $group The group, or null/'' to clear.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a named group does not exist.
	 */
	private function requireKnownGroup(?string $group): void {
		if ($this->namesAGroup(group: $group) === true && $this->groupManager->groupExists($group) === false) {
			throw new InvalidArgumentException('No such group: ' . $group);
		}
	}//end requireKnownGroup()

	/**
	 * Whether an owning-group argument names a group (rather than clearing it).
	 *
	 * @param string|null $group The argument.
	 *
	 * @return bool True for a non-empty string.
	 */
	private function namesAGroup(?string $group): bool {
		return ($group !== null && $group !== '');
	}//end namesAGroup()

	/**
	 * The authorization block with the owning group set, or removed for null/''.
	 *
	 * @param array<string, mixed> $block The block.
	 * @param string|null $group The group, or null/'' to clear.
	 *
	 * @return array<string, mixed> The block.
	 */
	private function withOwnerGroup(array $block, ?string $group): array {
		if ($this->namesAGroup(group: $group) === false) {
			unset($block[ObjectScopeResolver::OWNER_GROUP_KEY]);
			return $block;
		}

		$block[ObjectScopeResolver::OWNER_GROUP_KEY] = $group;
		return $block;
	}//end withOwnerGroup()

	/**
	 * The group name an authorization block holds, or null for none.
	 *
	 * @param mixed $value The stored `ownerGroup` value.
	 *
	 * @return string|null The group id.
	 */
	private function groupNameOf(mixed $value): ?string {
		if (is_string($value) === true && trim($value) !== '') {
			return trim($value);
		}

		return null;
	}//end groupNameOf()

	/**
	 * Move one record to a new owner, record it, announce it, and carry it down.
	 *
	 * The ONE place the owner changes. Every public method here funnels through
	 * it, so a transfer that is not recorded or not announced is not reachable.
	 *
	 * @param OwnedRecord $record The record, its register and schema, and its depth.
	 * @param OwnershipHandover $handover The new owner, the cascade flag and the records moved so far.
	 *
	 * @throws InvalidArgumentException When the record carries no uuid to key the write on.
	 *
	 * @return array{uuid: string, previousOwner: string|null, newOwner: string, changed: bool, children: array}
	 */
	private function transfer(OwnedRecord $record, OwnershipHandover $handover): array {
		$uuid = $record->object->getUuid();
		$previousOwner = $record->previousOwner;
		$newOwner = $handover->newOwner;

		// A record with no uuid cannot be addressed. Refused rather than cast,
		// because the write is keyed on the uuid: `(string)null` is the empty
		// string, which is a WHERE clause that matches whatever rows happen to
		// carry an empty uuid instead of failing.
		if ($uuid === null || $uuid === '') {
			throw new InvalidArgumentException('A record with no uuid cannot change hands');
		}

		$handover->visit(uuid: $uuid);

		// Already theirs. Reported rather than written, so a repeated call is not
		// a second audit entry and not a second notification.
		if ($previousOwner === $newOwner) {
			return [
				'uuid' => $uuid,
				'previousOwner' => $previousOwner,
				'newOwner' => $newOwner,
				'changed' => false,
				'children' => [],
			];
		}

		$children = $this->handOver(record: $record, handover: $handover);

		$this->logger->info(
			message: '[ObjectOwnershipService] A record changed hands',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'uuid' => $uuid,
				'previousOwner' => $previousOwner,
				'newOwner' => $newOwner,
				'children' => count($children),
			]
		);

		return [
			'uuid' => $uuid,
			'previousOwner' => $previousOwner,
			'newOwner' => $newOwner,
			'changed' => true,
			'children' => $children,
		];
	}//end transfer()

	/**
	 * Write the new owner, record the change, tell the previous owner, and cascade.
	 *
	 * @param OwnedRecord $record The record changing hands; its uuid is known to be set.
	 * @param OwnershipHandover $handover The transfer run.
	 *
	 * @return array The per-child outcomes.
	 */
	private function handOver(OwnedRecord $record, OwnershipHandover $handover): array {
		$object = $record->object;

		// The state the audit entry compares against. A clone, because the entity
		// is mutated below and the trail would otherwise diff the row against
		// itself and record a handover that changed nothing.
		$before = clone $object;

		$this->ownerWriter->writeOwner(
			register: $record->register,
			schema: $record->schema,
			objectUuid: (string)$object->getUuid(),
			owner: $handover->newOwner
		);
		$object->setOwner($handover->newOwner);

		$this->auditTrailMapper->createAuditTrail(
			old: $before,
			new: $object,
			action: self::AUDIT_ACTION
		);

		$this->notifyPreviousOwner(record: $record, newOwner: $handover->newOwner);

		if ($handover->cascade === true && $record->depth < self::MAX_CASCADE_DEPTH) {
			return $this->cascadeToChildren(parent: $record, handover: $handover);
		}

		return [];
	}//end handOver()

	/**
	 * Carry one transfer down to the record's children.
	 *
	 * A CHILD HERE IS A RECORD THAT REFERENCES THIS ONE AND SHARES ITS OWNER.
	 * Both halves are needed. The reference is what makes it a child rather than
	 * an unrelated record; the shared owner is what makes moving it right — a
	 * child somebody else owns is their record, and a handover must not quietly
	 * take it from them.
	 *
	 * Fail-soft per child, and that is deliberate: the parent has already changed
	 * hands and is recorded. A child that cannot be moved is logged and the walk
	 * continues, because the alternative is a half-done cascade that reports a
	 * failure for the whole handover that did in fact happen.
	 *
	 * @param OwnedRecord $parent The record that just changed hands, with its previous owner.
	 * @param OwnershipHandover $handover The transfer run.
	 *
	 * @return array The per-child outcomes.
	 */
	private function cascadeToChildren(OwnedRecord $parent, OwnershipHandover $handover): array {
		$uuid = $parent->object->getUuid();
		$previousOwner = $parent->previousOwner;
		if ($this->isCascadable(uuid: $uuid, previousOwner: $previousOwner) === false) {
			return [];
		}

		$outcomes = [];
		foreach ($this->childCandidates(uuid: $uuid) as $child) {
			if ($handover->carries(child: $child, previousOwner: $previousOwner) === false) {
				continue;
			}

			$outcome = $this->carryToChild(child: $child, parent: $parent, handover: $handover);
			if ($outcome !== null) {
				$outcomes[] = $outcome;
			}
		}

		return $outcomes;
	}//end cascadeToChildren()

	/**
	 * Whether a parent can seed a cascade: it has a uuid and had an owner.
	 *
	 * @param string|null $uuid The parent's uuid.
	 * @param string|null $previousOwner The parent's owner before the handover.
	 *
	 * @return bool True when its children may be looked up.
	 */
	private function isCascadable(?string $uuid, ?string $previousOwner): bool {
		return ($uuid !== null && $previousOwner !== null && $previousOwner !== '');
	}//end isCascadable()

	/**
	 * The records that relate to a parent, or none when they cannot be read.
	 *
	 * @param string $uuid The parent's uuid.
	 *
	 * @return ObjectEntity[] The candidates.
	 */
	private function childCandidates(string $uuid): array {
		try {
			return $this->magicMapper->findByRelationUsingRelationsColumn($uuid);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ObjectOwnershipService] Could not read the children of a record',
				context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $uuid, 'error' => $e->getMessage()]
			);
			return [];
		}
	}//end childCandidates()

	/**
	 * Carry a handover to one child, or null when that failed (logged).
	 *
	 * @param ObjectEntity $child The child.
	 * @param OwnedRecord $parent The parent being handed over.
	 * @param OwnershipHandover $handover The run.
	 *
	 * @return array<string, mixed>|null The child's outcome, or null.
	 */
	private function carryToChild(ObjectEntity $child, OwnedRecord $parent, OwnershipHandover $handover): ?array {
		try {
			return $this->transfer(
				record: $this->childRecord(child: $child, depth: ($parent->depth + 1)),
				handover: $handover
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ObjectOwnershipService] Could not carry a handover to a child record',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'uuid' => $child->getUuid(),
					'error' => $e->getMessage(),
				]
			);
			return null;
		}//end try
	}//end carryToChild()

	/**
	 * A child record with its own register and schema resolved.
	 *
	 * @param ObjectEntity $child The child record.
	 * @param int $depth Its generation below the transferred record.
	 *
	 * @return OwnedRecord The child, ready to change hands.
	 *
	 * @throws Throwable When its register or schema cannot be found.
	 */
	private function childRecord(ObjectEntity $child, int $depth): OwnedRecord {
		$childRegister = $this->registerMapper->find((int)$child->getRegister());
		$childSchema = $this->schemaMapper->find((int)$child->getSchema());

		return new OwnedRecord(register: $childRegister, schema: $childSchema, object: $child, depth: $depth);
	}//end childRecord()

	/**
	 * Tell the previous owner that their record changed hands.
	 *
	 * Fail-soft. A notification that could not be delivered must not undo a
	 * transfer that is already written and recorded; the audit entry is the
	 * durable record, the notification is the courtesy.
	 *
	 * Nobody is told when there was no previous owner, when the record was held
	 * by the system, or when the previous owner is the one who took it.
	 *
	 * @param OwnedRecord $record The record, with who held it.
	 * @param string $newOwner Who holds it now.
	 *
	 * @return void
	 */
	private function notifyPreviousOwner(OwnedRecord $record, string $newOwner): void {
		$object = $record->object;
		$previousOwner = $record->previousOwner;
		if ($this->shouldNotify(previousOwner: $previousOwner, newOwner: $newOwner) === false) {
			return;
		}

		try {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp('openregister');
			$notification->setUser($previousOwner);
			$notification->setDateTime(new DateTime());
			$notification->setObject('object', (string)$object->getUuid());
			$notification->setSubject(
				self::NOTIFICATION_SUBJECT,
				[
					'objectTitle' => ($object->getName() ?? (string)$object->getUuid()),
					'objectUuid' => (string)$object->getUuid(),
					'previousOwner' => $previousOwner,
					'newOwner' => $newOwner,
					'actor' => ($this->callerUid() ?? $newOwner),
				]
			);
			$this->notificationManager->notify($notification);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ObjectOwnershipService] Could not tell the previous owner about a handover',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'uuid' => $object->getUuid(),
					'error' => $e->getMessage(),
				]
			);
		}//end try
	}//end notifyPreviousOwner()

	/**
	 * Whether a previous owner exists, differs from the new one, and is still a user.
	 *
	 * @param string|null $previousOwner The previous owner.
	 * @param string $newOwner The new owner.
	 *
	 * @return bool True when the previous owner should be told.
	 */
	private function shouldNotify(?string $previousOwner, string $newOwner): bool {
		if ($previousOwner === null || $previousOwner === '' || $previousOwner === $newOwner) {
			return false;
		}

		return $this->userManager->userExists($previousOwner);
	}//end shouldNotify()

	/**
	 * Whether the rules admit this caller to edit this record.
	 *
	 * Asked of {@see PermissionHandlerInterface} rather than answered here, so a takeover
	 * is governed by the same verdict an ordinary edit is — including the private
	 * scope, the owning group and any deny rule.
	 *
	 * @param Schema $schema The record's schema.
	 * @param ObjectEntity $object The record.
	 *
	 * @return bool True when the caller may edit it.
	 */
	private function mayEdit(Schema $schema, ObjectEntity $object): bool {
		try {
			return $this->permissions->hasPermission(
				schema: $schema,
				action: 'update',
				objectOwner: $object->getOwner(),
				object: $object
			);
		} catch (Throwable $e) {
			// Fail closed: a verdict that could not be reached is not a yes.
			$this->logger->warning(
				message: '[ObjectOwnershipService] Could not decide whether the caller may edit; refusing',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return false;
		}
	}//end mayEdit()

	/**
	 * Require that the caller may name the group that owns the record.
	 *
	 * The owner, the owning group and an administrator, as before. Beside them a
	 * holder of the organisation's `grant_manage` special right on this record
	 * ({@see GrantManageGuard}). The owning group reads and changes the
	 * record, so a holder admitted by the special right alone may not name a
	 * group it is in: the right decides who else is let in, never the holder.
	 *
	 * @param ObjectEntity $object The record.
	 * @param string|null $group The group being named, or null to take it away.
	 *
	 * @throws NotAuthorizedException When the caller may not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
	 */
	private function requireMayNameOwnerGroup(ObjectEntity $object, ?string $group): void {
		$uid = $this->callerUid();
		$groups = $this->callerGroups();

		$admittedAs = $this->grantManage->admittedAs(userId: $uid, userGroups: $groups, object: $object);
		if ($admittedAs === null) {
			throw new NotAuthorizedException(
				message: 'Only the owner or an administrator may change who owns this record'
			);
		}

		if ($this->grantManage->letsItselfIn(
			admittedAs: $admittedAs,
			userId: $uid,
			userGroups: $groups,
			principal: new GrantedPrincipal(group: $group)
		) === true
		) {
			throw new NotAuthorizedException(
				message: 'The right to manage grants does not let its holder name its own group as the owner'
			);
		}
	}//end requireMayNameOwnerGroup()

	/**
	 * Require that the caller administers the instance, or that a trusted system operation runs.
	 *
	 * @throws NotAuthorizedException When they do not.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SystemOperationContext::isActive is the static scope check every system guard reads.
	 */
	private function requireAdmin(): void {
		// A trusted system operation (an install, a repair, an example set's
		// seeding) has no session user and acts for the application itself.
		if (SystemOperationContext::isActive() === true) {
			return;
		}

		if (in_array(needle: self::ADMIN_GROUP, haystack: $this->callerGroups(), strict: true) === false) {
			throw new NotAuthorizedException(
				message: 'Only an administrator may assign a record to somebody else'
			);
		}
	}//end requireAdmin()

	/**
	 * Require that a named new owner is a real user.
	 *
	 * A uid nobody answers to is worse than no owner at all: the record looks
	 * accounted for and reaches nobody.
	 *
	 * @param string $uid The named new owner.
	 *
	 * @throws InvalidArgumentException When no such user exists.
	 *
	 * @return void
	 */
	private function requireExistingUser(string $uid): void {
		if ($uid === '' || $this->userManager->userExists($uid) === false) {
			throw new InvalidArgumentException('No such user: ' . $uid);
		}
	}//end requireExistingUser()

	/**
	 * The caller's uid.
	 *
	 * @return string|null The uid, or null when anonymous.
	 */
	private function callerUid(): ?string {
		return $this->userSession->getUser()?->getUID();
	}//end callerUid()

	/**
	 * The caller's group ids.
	 *
	 * @return string[] The groups, empty when anonymous.
	 */
	private function callerGroups(): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return [];
		}

		return $this->groupManager->getUserGroupIds($user);
	}//end callerGroups()

}//end class
