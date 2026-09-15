<?php

/**
 * Watchers: following an object you do not own.
 *
 * A watcher is a per-user, per-object subscription stored outside the object.
 * It is the one place that decides who may subscribe, who may read the
 * subscriber list, and who may change somebody else's subscription, so the
 * controller, the render layer, the query lens and the notification dispatcher
 * cannot drift on those three answers.
 *
 * THE THREE PERMISSION POSTURES, and why each is what it is (ADR-010):
 *
 *  - WATCHING needs `read`. The caller has already proven it: every entry point
 *    resolves the object through ObjectService, which applies register RBAC and
 *    multitenancy, and an object the caller cannot read never resolves. So this
 *    service is handed an ObjectEntity the caller may read, and does not
 *    re-derive that.
 *  - LISTING the watchers needs `update`, checked here against the object's
 *    schema. Watching is a fact about the object's audience, so an editor may
 *    see it and an ordinary reader may not.
 *  - CHANGING somebody else's subscription needs `manage`. ADR-010 keeps the
 *    RBAC vocabulary to core's five verbs, so `manage` is not an RBAC verb and
 *    is enforced HERE, at the endpoint that performs the action, per ADR-010
 *    Rule 4. Its meaning is the one this codebase already uses for per-object
 *    administration: the object's owner, or an administrator, resolved through
 *    the same ObjectScopeResolver the read side uses so the two cannot drift.
 *
 * A watcher may always remove themselves, whatever the posture above says.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Interaction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-watchers/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Interaction;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Watcher;
use OCA\OpenRegister\Db\WatcherMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The one subscription primitive: watch, unwatch, list, and heal.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Twelve, and each one has a caller that
 * no other method can serve: five are the API verbs, three are read by the render layer
 * (the marker, the count, and who may see the count), two are the notification
 * dispatcher's (resolve the audience, drop a watcher who lost read), one is the deletion
 * cleanup and one is the caller's uid. Splitting them would put the three permission
 * postures in more than one class, which is the drift this class exists to prevent.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The count is 51 against a threshold of
 * 50, and almost all of it is guard clauses: every public method is wrapped so a
 * subscription lookup can never take out an object read or a dispatch. Removing a branch
 * here means removing a fail-safe.
 */
class WatcherService {

	/**
	 * The uuids the current user watches, loaded once per request.
	 *
	 * `@self.watching` is rendered on every row of every list, so reading it
	 * per row would be an N+1 on the hot read path. Null until first read.
	 *
	 * @var array<string, bool>|null
	 */
	private ?array $watchedByCallerMemo = null;

	/**
	 * Watcher counts per object uuid, loaded once per request.
	 *
	 * @var array<string, int>|null
	 */
	private ?array $countsMemo = null;

	/**
	 * Whether the grouped count map is complete, or hit its cap.
	 *
	 * @var boolean
	 */
	private bool $countsComplete = true;

	/**
	 * Constructor.
	 *
	 * @param WatcherMapper $mapper The watcher rows.
	 * @param IUserSession $userSession Resolves the calling user.
	 * @param IGroupManager $groupManager Resolves the caller's groups for the `manage` posture.
	 * @param SchemaMapper $schemaMapper Resolves the object's schema for the `update` posture.
	 * @param PermissionHandler $permissions The one RBAC evaluator.
	 * @param ObjectScopeResolver $scopeResolver The one definition of "admitted unconditionally".
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly WatcherMapper $mapper,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly SchemaMapper $schemaMapper,
		private readonly PermissionHandler $permissions,
		private readonly ObjectScopeResolver $scopeResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The calling user's uid.
	 *
	 * @return string|null The uid, or null when anonymous.
	 *
	 * @spec openspec/changes/object-watchers/specs/object-interactions/spec.md
	 */
	public function callerUid(): ?string {
		return $this->userSession->getUser()?->getUID();
	}//end callerUid()

	/**
	 * Subscribe the calling user to an object they may read.
	 *
	 * Idempotent: subscribing twice leaves one row, and writes nothing on the
	 * object itself — no audit entry, no version.
	 *
	 * @param ObjectEntity $object The object to follow, already resolved through RBAC.
	 * @param string|null $register The register as the caller addressed it.
	 * @param string|null $schema The schema as the caller addressed it.
	 *
	 * @throws NotAuthorizedException When the caller is anonymous.
	 *
	 * @return Watcher The subscription.
	 *
	 * @spec openspec/changes/object-watchers/specs/object-interactions/spec.md#requirement-a-user-can-watch-an-object-they-may-read
	 */
	public function watch(ObjectEntity $object, ?string $register = null, ?string $schema = null): Watcher {
		$uid = $this->requireCaller();
		$uuid = $this->requireUuid(object: $object);

		$this->forgetMemos();

		return $this->mapper->subscribe(
			userId: $uid,
			objectUuid: $uuid,
			register: $register,
			schema: $schema
		);
	}//end watch()

	/**
	 * Remove the calling user's own subscription.
	 *
	 * @param ObjectEntity $object The object to stop following.
	 *
	 * @throws NotAuthorizedException When the caller is anonymous.
	 *
	 * @return boolean True when a subscription was removed.
	 *
	 * @spec openspec/changes/object-watchers/specs/object-interactions/spec.md#requirement-a-user-can-watch-an-object-they-may-read
	 */
	public function unwatch(ObjectEntity $object): bool {
		$uid = $this->requireCaller();
		$uuid = $this->requireUuid(object: $object);

		$this->forgetMemos();

		return $this->mapper->unsubscribe(userId: $uid, objectUuid: $uuid);
	}//end unwatch()

	/**
	 * The watchers of one object, for a caller with `update`.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @throws NotAuthorizedException When the caller may not update the object.
	 *
	 * @return array<int, Watcher> The subscriptions, oldest first.
	 *
	 * @spec openspec/changes/object-watchers/specs/object-interactions/spec.md#requirement-watchers-are-a-lens-and-a-list
	 */
	public function listWatchers(ObjectEntity $object): array {
		$this->requireUpdate(object: $object);

		return $this->mapper->findByObject(objectUuid: $this->requireUuid(object: $object));
	}//end listWatchers()

	/**
	 * Subscribe another user, for a caller with `manage`.
	 *
	 * @param ObjectEntity $object The object.
	 * @param string $userId The user to subscribe.
	 * @param string|null $register The register as the caller addressed it.
	 * @param string|null $schema The schema as the caller addressed it.
	 *
	 * @throws NotAuthorizedException When the caller may not manage the object.
	 *
	 * @return Watcher The subscription.
	 *
	 * @spec openspec/changes/object-watchers/specs/object-interactions/spec.md#requirement-watchers-are-a-lens-and-a-list
	 */
	public function addWatcher(ObjectEntity $object, string $userId, ?string $register = null, ?string $schema = null): Watcher {
		$this->requireManage(object: $object);

		$this->forgetMemos();

		return $this->mapper->subscribe(
			userId: $userId,
			objectUuid: $this->requireUuid(object: $object),
			register: $register,
			schema: $schema
		);
	}//end addWatcher()

	/**
	 * Subscribe a principal who was NAMED in a timeline entry.
	 *
	 * WHY THIS IS NOT `addWatcher()`. That method is an administrative act:
	 * one person deciding that another will follow an object, which is why it
	 * asks for `manage`. A mention is not that. The person doing it is a
	 * handler who just wrote an entry on this object, and the authorisation
	 * that matters is the MENTIONED principal's own: they are subscribed only
	 * when they may already read the object, and that is decided by the caller
	 * before this method runs (see EntryMentionService). Requiring `manage`
	 * here would mean only an owner could ever name a colleague in a note,
	 * which is the opposite of what a mention is for.
	 *
	 * Idempotent, like every other subscribe: naming somebody twice leaves one
	 * row and writes nothing on the object.
	 *
	 * @param ObjectEntity $object The object the entry hangs on.
	 * @param string $userId The principal who was named, already checked for read access.
	 * @param string|null $register The register as the caller addressed it.
	 * @param string|null $schema The schema as the caller addressed it.
	 *
	 * @return Watcher The subscription.
	 *
	 * @throws NotAuthorizedException When the object carries no uuid to hang the row on.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function subscribeMentioned(
		ObjectEntity $object,
		string $userId,
		?string $register = null,
		?string $schema = null,
	): Watcher {
		$this->forgetMemos();

		return $this->mapper->subscribe(
			userId: $userId,
			objectUuid: $this->requireUuid(object: $object),
			register: $register,
			schema: $schema
		);
	}//end subscribeMentioned()

	/**
	 * Remove another user's subscription.
	 *
	 * A watcher removing THEMSELVES needs nothing beyond being that watcher;
	 * removing anybody else needs `manage`.
	 *
	 * @param ObjectEntity $object The object.
	 * @param string $userId The user whose subscription is removed.
	 *
	 * @throws NotAuthorizedException When the caller is neither that user nor a manager.
	 *
	 * @return boolean True when a subscription was removed.
	 *
	 * @spec openspec/changes/object-watchers/specs/object-interactions/spec.md#requirement-watchers-are-a-lens-and-a-list
	 */
	public function removeWatcher(ObjectEntity $object, string $userId): bool {
		if ($this->callerUid() !== $userId) {
			$this->requireManage(object: $object);
		}

		$this->forgetMemos();

		return $this->mapper->unsubscribe(
			userId: $userId,
			objectUuid: $this->requireUuid(object: $object)
		);
	}//end removeWatcher()

	/**
	 * Whether the calling user watches one object.
	 *
	 * Backed by the per-request set, so rendering a page of objects costs one
	 * query rather than one per row.
	 *
	 * @param string $objectUuid The object's uuid.
	 *
	 * @return boolean True when the caller watches it.
	 *
	 * @spec openspec/changes/object-watchers/specs/object-interactions/spec.md#requirement-a-user-can-watch-an-object-they-may-read
	 */
	public function isWatchedByCaller(string $objectUuid): bool {
		if ($objectUuid === '') {
			return false;
		}

		return isset($this->watchedSetForCaller()[$objectUuid]);
	}//end isWatchedByCaller()

	/**
	 * The uuids one user watches, behind the follow marker.
	 *
	 * Private: the `_watching=true` lens reads WatcherMapper::uuidsForUser()
	 * directly (SearchQueryHandler explains why), so the only caller of this is
	 * the per-request memo below.
	 *
	 * @param string|null $userId The user, or null for the caller.
	 * @param string|null $register Narrow to one register.
	 * @param string|null $schema Narrow to one schema.
	 *
	 * @return array<int, string> The watched uuids; empty when anonymous.
	 *
	 * @spec openspec/changes/object-watchers/specs/object-interactions/spec.md#requirement-watchers-are-a-lens-and-a-list
	 */
	private function watchedUuids(?string $userId = null, ?string $register = null, ?string $schema = null): array {
		$uid = ($userId ?? $this->callerUid());
		if ($uid === null || $uid === '') {
			return [];
		}

		try {
			return $this->mapper->uuidsForUser(userId: $uid, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf('[WatcherService] watched uuid lookup failed for "%s": %s', $uid, $e->getMessage())
			);
			return [];
		}
	}//end watchedUuids()

	/**
	 * How many users watch one object.
	 *
	 * @param string $objectUuid The object's uuid.
	 *
	 * @return integer The watcher count.
	 *
	 * @spec openspec/changes/object-watchers/specs/object-interactions/spec.md#requirement-watchers-are-a-lens-and-a-list
	 */
	public function watcherCount(string $objectUuid): int {
		if ($objectUuid === '') {
			return 0;
		}

		$counts = $this->countMap();
		if (array_key_exists($objectUuid, $counts) === true) {
			return $counts[$objectUuid];
		}

		// Absent from a COMPLETE map means "nobody watches it", which is a real
		// zero. Absent from a map that hit its cap means "not loaded", and the
		// only honest answer is to go and count that one object.
		if ($this->countsComplete === true) {
			return 0;
		}

		try {
			return $this->mapper->countForObject(objectUuid: $objectUuid);
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf('[WatcherService] watcher count failed for "%s": %s', $objectUuid, $e->getMessage())
			);
			return 0;
		}
	}//end watcherCount()

	/**
	 * Whether the caller may see an object's watcher list and its count.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return boolean True when the caller has `update` on the object's schema.
	 *
	 * @spec openspec/changes/object-watchers/specs/object-interactions/spec.md#requirement-watchers-are-a-lens-and-a-list
	 */
	public function maySeeWatchers(ObjectEntity $object): bool {
		try {
			$schema = $this->schemaMapper->find(id: $object->getSchema(), _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			// No schema, no verdict, and the fail-closed answer hides the count.
			return false;
		}

		try {
			return $this->permissions->hasPermission(
				schema: $schema,
				action: 'update',
				objectOwner: $object->getOwner(),
				object: $object
			);
		} catch (\Throwable $e) {
			return false;
		}
	}//end maySeeWatchers()

	/**
	 * The uids watching one object, for the notification dispatcher.
	 *
	 * @param string $objectUuid The object's uuid.
	 *
	 * @return array<int, string> The watching uids.
	 *
	 * @spec openspec/changes/object-watchers/specs/notificatie-engine/spec.md#requirement-a-notification-rule-may-address-the-objects-watchers
	 */
	public function watcherUids(string $objectUuid): array {
		if ($objectUuid === '') {
			return [];
		}

		try {
			$rows = $this->mapper->findByObject(objectUuid: $objectUuid);
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf('[WatcherService] watcher lookup failed for "%s": %s', $objectUuid, $e->getMessage())
			);
			return [];
		}

		$uids = [];
		foreach ($rows as $row) {
			$uid = (string)$row->getUserId();
			if ($uid !== '') {
				$uids[] = $uid;
			}
		}

		return array_values(array_unique($uids));
	}//end watcherUids()

	/**
	 * Drop a watcher who may no longer read the object.
	 *
	 * Called by the recipient resolver at dispatch time. Checking there, and
	 * not only at subscribe time, is what keeps a sensitive case from reaching
	 * a user whose group membership changed after they subscribed. Best-effort:
	 * a failed prune must never take out a dispatch, and the user still
	 * receives nothing either way because the caller drops them from the
	 * recipient list first.
	 *
	 * @param string $objectUuid The object's uuid.
	 * @param array<int, string> $userIds The users to unsubscribe.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-watchers/specs/notificatie-engine/spec.md#requirement-a-notification-rule-may-address-the-objects-watchers
	 */
	public function dropWatchers(string $objectUuid, array $userIds): void {
		foreach ($userIds as $userId) {
			if (is_string($userId) === false || $userId === '') {
				continue;
			}

			try {
				$this->mapper->unsubscribe(userId: $userId, objectUuid: $objectUuid);
			} catch (\Throwable $e) {
				$this->logger->warning(
					sprintf('[WatcherService] could not drop watcher "%s": %s', $userId, $e->getMessage())
				);
			}
		}

		$this->forgetMemos();
	}//end dropWatchers()

	/**
	 * Remove every subscription on a deleted object.
	 *
	 * @param string $objectUuid The deleted object's uuid.
	 *
	 * @return integer How many subscriptions were removed.
	 *
	 * @spec openspec/changes/object-watchers/specs/object-interactions/spec.md#requirement-deleting-an-object-removes-its-watchers
	 */
	public function cleanupForObject(string $objectUuid): int {
		if ($objectUuid === '') {
			return 0;
		}

		$this->forgetMemos();

		return $this->mapper->deleteByObject(objectUuid: $objectUuid);
	}//end cleanupForObject()

	/**
	 * Drop the per-request memos after a write.
	 *
	 * Both memos answer "as of the start of this request". A write during the
	 * request makes both of them wrong, and a stale follow marker on the very
	 * response that confirms the follow is the one bug a reader would report.
	 *
	 * @return void
	 */
	private function forgetMemos(): void {
		$this->watchedByCallerMemo = null;
		$this->countsMemo = null;
	}//end forgetMemos()

	/**
	 * The per-request set of uuids the caller watches.
	 *
	 * @return array<string, bool> Uuid to true.
	 */
	private function watchedSetForCaller(): array {
		if ($this->watchedByCallerMemo !== null) {
			return $this->watchedByCallerMemo;
		}

		$set = [];
		foreach ($this->watchedUuids() as $uuid) {
			$set[$uuid] = true;
		}

		$this->watchedByCallerMemo = $set;

		return $set;
	}//end watchedSetForCaller()

	/**
	 * The per-request map of watcher counts.
	 *
	 * @return array<string, int> Uuid to count.
	 */
	private function countMap(): array {
		if ($this->countsMemo !== null) {
			return $this->countsMemo;
		}

		try {
			$counts = $this->mapper->countsByObject();
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf('[WatcherService] watcher count map failed: %s', $e->getMessage())
			);
			$counts = [];
		}

		$this->countsComplete = (count($counts) < WatcherMapper::COUNT_MAP_LIMIT);
		$this->countsMemo = $counts;

		return $counts;
	}//end countMap()

	/**
	 * The calling uid, or a refusal.
	 *
	 * @throws NotAuthorizedException When there is no session user.
	 *
	 * @return string The uid.
	 */
	private function requireCaller(): string {
		$uid = $this->callerUid();
		if ($uid === null || $uid === '') {
			throw new NotAuthorizedException(message: 'Watching an object requires a signed-in user');
		}

		return $uid;
	}//end requireCaller()

	/**
	 * The object's uuid, or a refusal.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @throws NotAuthorizedException When the object carries no uuid.
	 *
	 * @return string The uuid.
	 */
	private function requireUuid(ObjectEntity $object): string {
		$uuid = (string)$object->getUuid();
		if ($uuid === '') {
			throw new NotAuthorizedException(message: 'This object cannot be watched');
		}

		return $uuid;
	}//end requireUuid()

	/**
	 * Require `update` on the object's schema.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @throws NotAuthorizedException When the caller may not update it.
	 *
	 * @return void
	 */
	private function requireUpdate(ObjectEntity $object): void {
		if ($this->maySeeWatchers(object: $object) === false) {
			throw new NotAuthorizedException(
				message: 'Only a user who may edit this object can see who follows it'
			);
		}
	}//end requireUpdate()

	/**
	 * Require `manage`: the object's owner, or an administrator.
	 *
	 * ADR-010 Rule 4 — `manage` is not one of core's five verbs, so RBAC cannot
	 * decide it and the acting endpoint must. This is that decision, taken
	 * through the same resolver the read side uses.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @throws NotAuthorizedException When the caller is neither owner nor admin.
	 *
	 * @return void
	 */
	private function requireManage(ObjectEntity $object): void {
		$uid = $this->callerUid();
		$groups = [];
		$user = $this->userSession->getUser();
		if ($user !== null) {
			$groups = $this->groupManager->getUserGroupIds($user);
		}

		if ($this->scopeResolver->admitsUnconditionally(
			userId: $uid,
			userGroups: $groups,
			objectOwner: $object->getOwner()
		) === false
		) {
			throw new NotAuthorizedException(
				message: 'Only the owner or an administrator may change who follows this object'
			);
		}
	}//end requireManage()
}//end class
