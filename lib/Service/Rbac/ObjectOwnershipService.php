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
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Object\PermissionHandler;
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
class ObjectOwnershipService {

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
	 * Constructor.
	 *
	 * @param IUserSession $userSession The acting user, which is where an owner comes from.
	 * @param IUserManager $userManager Resolves a named new owner, and their display name.
	 * @param IGroupManager $groupManager The caller's groups, and whether an owning group exists.
	 * @param ObjectScopeResolver $scopeResolver The one definition of who is admitted unconditionally.
	 * @param PermissionHandler $permissions The rules, for "may this caller edit this record".
	 * @param ObjectOwnerWriter $ownerWriter The targeted write of the owner column.
	 * @param ObjectAuthorizationWriter $authorizationWriter The targeted write of the authorization block.
	 * @param AuditTrailMapper $auditTrailMapper The hash-sealed trail.
	 * @param MagicMapper $magicMapper Resolves objects and their children.
	 * @param RegisterMapper $registerMapper Resolves a child's register.
	 * @param SchemaMapper $schemaMapper Resolves a child's schema.
	 * @param INotificationManager $notificationManager Tells the previous owner.
	 * @param LoggerInterface $logger Where a transfer is noted.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor injection; see the
	 * coupling note on the class.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly ObjectScopeResolver $scopeResolver,
		private readonly PermissionHandler $permissions,
		private readonly ObjectOwnerWriter $ownerWriter,
		private readonly ObjectAuthorizationWriter $authorizationWriter,
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly MagicMapper $magicMapper,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly INotificationManager $notificationManager,
		private readonly LoggerInterface $logger,
	) {
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
			register: $register,
			schema: $schema,
			object: $object,
			newOwner: $actor,
			cascade: $cascade
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
			register: $register,
			schema: $schema,
			object: $object,
			newOwner: $newOwner,
			cascade: $cascade
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

		$transferred = [];
		$unchanged = [];
		$failed = [];

		foreach ($identifiers as $identifier) {
			if (is_string($identifier) === false || $identifier === '') {
				$failed[] = ['identifier' => '', 'reason' => 'An empty identifier cannot be resolved'];
				continue;
			}

			try {
				$context = $this->magicMapper->findAcrossAllSources(identifier: $identifier);
				$register = $context['register'];
				$schema = $context['schema'];
				if (($register instanceof Register) === false || ($schema instanceof Schema) === false) {
					$failed[] = ['identifier' => $identifier, 'reason' => 'The record has no register or schema'];
					continue;
				}

				$outcome = $this->transfer(
					register: $register,
					schema: $schema,
					object: $context['object'],
					newOwner: $newOwner,
					cascade: $cascade
				);

				if ($outcome['changed'] === false) {
					$unchanged[] = $identifier;
					continue;
				}

				$transferred[] = $outcome;
			} catch (Throwable $e) {
				$failed[] = ['identifier' => $identifier, 'reason' => $e->getMessage()];
			}//end try
		}//end foreach

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
		$this->requireOwnerOrAdmin(object: $object);

		if ($group !== null && $group !== '' && $this->groupManager->groupExists($group) === false) {
			throw new InvalidArgumentException('No such group: ' . $group);
		}

		// Read-modify-write ONE key, exactly as the scope is written: an action
		// override or a scope in the same block must survive this.
		$block = ($object->getAuthorization() ?? []);

		if ($group === null || $group === '') {
			unset($block[ObjectScopeResolver::OWNER_GROUP_KEY]);
		}

		if ($group !== null && $group !== '') {
			$block[ObjectScopeResolver::OWNER_GROUP_KEY] = $group;
		}

		$this->authorizationWriter->writeAuthorizationBlock(
			register: $register,
			schema: $schema,
			objectUuid: (string)$object->getUuid(),
			block: $block
		);

		$object->setAuthorization($block);

		return $block;
	}//end setOwnerGroup()

	/**
	 * Move one record to a new owner, record it, announce it, and carry it down.
	 *
	 * The ONE place the owner changes. Every public method here funnels through
	 * it, so a transfer that is not recorded or not announced is not reachable.
	 *
	 * @param Register $register The record's register.
	 * @param Schema $schema The record's schema.
	 * @param ObjectEntity $object The record.
	 * @param string $newOwner The uid of the new owner.
	 * @param bool $cascade Whether the change carries to the record's children.
	 * @param int $depth How many generations down this call already is.
	 * @param array<string, true> $visited Records already moved by this transfer, by uuid.
	 *
	 * @throws InvalidArgumentException When the record carries no uuid to key the write on.
	 *
	 * @return array{uuid: string, previousOwner: string|null, newOwner: string, changed: bool, children: array}
	 */
	private function transfer(
		Register $register,
		Schema $schema,
		ObjectEntity $object,
		string $newOwner,
		bool $cascade,
		int $depth = 0,
		array &$visited = [],
	): array {
		$uuid = $object->getUuid();
		$previousOwner = $object->getOwner();

		// A record with no uuid cannot be addressed. Refused rather than cast,
		// because the write is keyed on the uuid: `(string)null` is the empty
		// string, which is a WHERE clause that matches whatever rows happen to
		// carry an empty uuid instead of failing.
		if ($uuid === null || $uuid === '') {
			throw new InvalidArgumentException('A record with no uuid cannot change hands');
		}

		$visited[$uuid] = true;

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

		// The state the audit entry compares against. A clone, because the entity
		// is mutated below and the trail would otherwise diff the row against
		// itself and record a handover that changed nothing.
		$before = clone $object;

		$this->ownerWriter->writeOwner(
			register: $register,
			schema: $schema,
			objectUuid: $uuid,
			owner: $newOwner
		);
		$object->setOwner($newOwner);

		$this->auditTrailMapper->createAuditTrail(
			old: $before,
			new: $object,
			action: self::AUDIT_ACTION
		);

		$this->notifyPreviousOwner(
			object: $object,
			previousOwner: $previousOwner,
			newOwner: $newOwner
		);

		$children = [];
		if ($cascade === true && $depth < self::MAX_CASCADE_DEPTH) {
			$children = $this->cascadeToChildren(
				object: $object,
				previousOwner: $previousOwner,
				newOwner: $newOwner,
				depth: $depth,
				visited: $visited
			);
		}

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
	 * @param ObjectEntity $object The record that just changed hands.
	 * @param string|null $previousOwner Who held it, and whose children move with it.
	 * @param string $newOwner Who holds it now.
	 * @param int $depth How many generations down we are.
	 * @param array<string, true> $visited Records already moved, by uuid.
	 *
	 * @return array The per-child outcomes.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Every branch is a child this walk
	 * must NOT move: no uuid, already visited, a different owner, or unresolvable.
	 */
	private function cascadeToChildren(
		ObjectEntity $object,
		?string $previousOwner,
		string $newOwner,
		int $depth,
		array &$visited,
	): array {
		$uuid = $object->getUuid();
		if ($uuid === null || $previousOwner === null || $previousOwner === '') {
			return [];
		}

		try {
			$candidates = $this->magicMapper->findByRelationUsingRelationsColumn($uuid);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ObjectOwnershipService] Could not read the children of a record',
				context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $uuid, 'error' => $e->getMessage()]
			);
			return [];
		}

		$outcomes = [];
		foreach ($candidates as $child) {
			$childUuid = $child->getUuid();
			if ($childUuid === null || isset($visited[$childUuid]) === true) {
				continue;
			}

			if ($child->getOwner() !== $previousOwner) {
				continue;
			}

			try {
				$childRegister = $this->registerMapper->find((int)$child->getRegister());
				$childSchema = $this->schemaMapper->find((int)$child->getSchema());

				$outcomes[] = $this->transfer(
					register: $childRegister,
					schema: $childSchema,
					object: $child,
					newOwner: $newOwner,
					cascade: true,
					depth: ($depth + 1),
					visited: $visited
				);
			} catch (Throwable $e) {
				$this->logger->warning(
					message: '[ObjectOwnershipService] Could not carry a handover to a child record',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'uuid' => $childUuid,
						'error' => $e->getMessage(),
					]
				);
			}//end try
		}//end foreach

		return $outcomes;
	}//end cascadeToChildren()

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
	 * @param ObjectEntity $object The record.
	 * @param string|null $previousOwner Who held it.
	 * @param string $newOwner Who holds it now.
	 *
	 * @return void
	 */
	private function notifyPreviousOwner(ObjectEntity $object, ?string $previousOwner, string $newOwner): void {
		if ($previousOwner === null || $previousOwner === '' || $previousOwner === $newOwner) {
			return;
		}

		if ($this->userManager->userExists($previousOwner) === false) {
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
	 * Whether the rules admit this caller to edit this record.
	 *
	 * Asked of {@see PermissionHandler} rather than answered here, so a takeover
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
	 * Require that the caller owns the record, or administers the instance.
	 *
	 * @param ObjectEntity $object The record.
	 *
	 * @throws NotAuthorizedException When the caller is neither.
	 *
	 * @return void
	 */
	private function requireOwnerOrAdmin(ObjectEntity $object): void {
		if ($this->scopeResolver->admitsUnconditionally(
			userId: $this->callerUid(),
			userGroups: $this->callerGroups(),
			objectOwner: $object->getOwner(),
			authorization: $object->getAuthorization()
		) === false
		) {
			throw new NotAuthorizedException(
				message: 'Only the owner or an administrator may change who owns this record'
			);
		}
	}//end requireOwnerOrAdmin()

	/**
	 * Require that the caller administers the instance.
	 *
	 * @throws NotAuthorizedException When they do not.
	 *
	 * @return void
	 */
	private function requireAdmin(): void {
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
