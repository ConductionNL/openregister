<?php

/**
 * Read state: what you have already seen, and what has moved since.
 *
 * The one place that decides who may read and write a read state, what opening
 * an object clears, and what a write invalidates. The controller, the render
 * layer, the query lens and the invalidation listener all come through here, so
 * they cannot drift on those answers.
 *
 * THE PERMISSION POSTURE, and why it is the short one (ADR-005). A read state
 * is private to one user and is never shared, so there is exactly one rule:
 * **a caller may read and write their OWN read state and nobody else's.** There
 * is no `manage` escape and no admin override, because a read state is not a
 * fact about the object, it is a fact about the person. An administrator who
 * could mark an object read for somebody else could make a badge lie to them.
 *
 * READING THE OBJECT IS STILL GATED, and not here. Every entry point is handed
 * an ObjectEntity that the caller already resolved through ObjectService, which
 * applies register RBAC and multitenancy. An object the caller cannot read never
 * resolves, so this service is never asked about one.
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
 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Interaction;

use DateTime;
use DateTimeInterface;
use OCA\OpenRegister\Db\FileMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectReadState;
use OCA\OpenRegister\Db\ObjectReadStateMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Mark seen, mark unread, invalidate, and count what is unread on the tabs.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Nine, and each has a caller no other
 * method can serve: three are the API verbs, three are read by the render layer (the
 * marker, the counts, the caller's uid), two are the write path's (invalidate, prune)
 * and one is the memo reset the write path needs. Splitting them would put the single
 * permission rule in more than one class, which is the drift this class prevents.
 */
class ReadStateService {

	/**
	 * The uuids the current user has already seen, loaded once per request.
	 *
	 * `@self.unread` is rendered on every row of every list, so reading it per
	 * row would be an N+1 on the hot read path. Null until first read.
	 *
	 * @var array<string, bool>|null
	 */
	private ?array $seenByCallerMemo = null;

	/**
	 * Constructor.
	 *
	 * @param ObjectReadStateMapper $mapper The read-state rows.
	 * @param SubstantiveChangeEvaluator $evaluator Decides what counts as news.
	 * @param IUserSession $userSession Resolves the calling user.
	 * @param FileMapper $fileMapper Counts an object's files for the files badge.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ObjectReadStateMapper $mapper,
		private readonly SubstantiveChangeEvaluator $evaluator,
		private readonly IUserSession $userSession,
		private readonly FileMapper $fileMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The calling user's uid.
	 *
	 * @return string|null The uid, or null when anonymous.
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md
	 */
	public function callerUid(): ?string {
		return $this->userSession->getUser()?->getUID();

	}//end callerUid()

	/**
	 * Record that the calling user has now seen an object.
	 *
	 * Idempotent, and writes nothing on the object itself: no audit entry, no
	 * version.
	 *
	 * @param ObjectEntity $object The object, already resolved through RBAC.
	 * @param string|null $register The register as the caller addressed it.
	 * @param string|null $schema The schema as the caller addressed it.
	 * @param string|null $subResource A sub-resource that was opened, when one was.
	 *
	 * @throws NotAuthorizedException When the caller is anonymous.
	 *
	 * @return ObjectReadState The stored read state.
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
	 */
	public function markRead(
		ObjectEntity $object,
		?string $register = null,
		?string $schema = null,
		?string $subResource = null
	): ObjectReadState {
		$uid = $this->requireCaller();
		$uuid = $this->requireUuid(object: $object);
		$now = new DateTime();

		$subSeen = null;
		if ($subResource !== null && $subResource !== '') {
			$subSeen = [$subResource => $now->format(DateTimeInterface::ATOM)];
		}

		$this->forgetMemo();

		return $this->mapper->markSeen(
			userId: $uid,
			objectUuid: $uuid,
			register: $register,
			schema: $schema,
			subSeen: $subSeen,
			seenAt: $now
		);

	}//end markRead()

	/**
	 * Put an object back to unread for the calling user.
	 *
	 * It stays read for everybody else: the row this removes is nobody's but
	 * the caller's.
	 *
	 * @param ObjectEntity $object The object, already resolved through RBAC.
	 *
	 * @throws NotAuthorizedException When the caller is anonymous.
	 *
	 * @return boolean True when a read state was removed.
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
	 */
	public function markUnread(ObjectEntity $object): bool {
		$uid = $this->requireCaller();
		$uuid = $this->requireUuid(object: $object);

		$this->forgetMemo();

		return $this->mapper->markUnread(userId: $uid, objectUuid: $uuid);

	}//end markUnread()

	/**
	 * One user's own read state, and nobody else's.
	 *
	 * The `$userId` argument exists so the refusal is explicit at the one place
	 * that could otherwise leak: a caller naming somebody else is refused rather
	 * than silently answered about themselves, which would be a lie.
	 *
	 * @param ObjectEntity $object The object, already resolved through RBAC.
	 * @param string|null $userId The user asked about, or null for the caller.
	 *
	 * @throws NotAuthorizedException When the caller asks about another user.
	 *
	 * @return ObjectReadState|null The row, or null when the object is unread.
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
	 */
	public function readStateFor(ObjectEntity $object, ?string $userId = null): ?ObjectReadState {
		$uid = $this->requireCaller();
		if ($userId !== null && $userId !== '' && $userId !== $uid) {
			throw new NotAuthorizedException(
				message: 'A read state is private to the user it belongs to'
			);
		}

		return $this->mapper->findOne(userId: $uid, objectUuid: $this->requireUuid(object: $object));

	}//end readStateFor()

	/**
	 * Whether an object is unread for the calling user.
	 *
	 * Unread is the ABSENCE of a row, answered from the per-request seen set so
	 * that rendering a page of objects costs one query rather than one per row.
	 *
	 * @param string $objectUuid The object's uuid.
	 *
	 * @return boolean True when the caller has not seen the object since its last change.
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md#requirement-unread-is-a-filter-and-a-badge-resolved-in-the-query-req-ors-002
	 */
	public function isUnreadForCaller(string $objectUuid): bool {
		if ($objectUuid === '') {
			return false;
		}

		return isset($this->seenSetForCaller()[$objectUuid]) === false;

	}//end isUnreadForCaller()

	/**
	 * How many entries of each sub-resource are unread for the calling user.
	 *
	 * One map, from one read state row plus the object's own body, so a page
	 * renders every tab badge without a call per tab. A sub-resource the schema
	 * does not declare is absent from the map rather than zero, because "no
	 * badge" and "a badge reading nought" are different claims.
	 *
	 * @param ObjectEntity $object The object being rendered.
	 *
	 * @return array<string, int> Sub-resource name to its unread count.
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md#requirement-unread-is-a-filter-and-a-badge-resolved-in-the-query-req-ors-002
	 */
	public function unreadCounts(ObjectEntity $object): array {
		$uid = $this->callerUid();
		$uuid = (string)$object->getUuid();
		if ($uid === null || $uid === '' || $uuid === '') {
			return [];
		}

		$state = $this->mapper->findOne(userId: $uid, objectUuid: $uuid);
		$subSeen = ($state?->getSubSeen() ?? []);

		$counts = [];
		foreach ($this->evaluator->subResources(object: $object) as $name => $descriptor) {
			$since = $this->seenMoment(name: $name, subSeen: $subSeen, state: $state);
			$counts[$name] = $this->countSince(object: $object, descriptor: $descriptor, since: $since);
		}

		return $counts;

	}//end unreadCounts()

	/**
	 * A write happened: everybody but the actor has something new to see.
	 *
	 * Called by the invalidation listener, which has already asked the evaluator
	 * whether the change was substantive. Kept separate from that question so
	 * the listener stays a wire and this stays the one write.
	 *
	 * @param string $objectUuid The changed object's uuid.
	 * @param string|null $actorUid The user who made the change, whose own read
	 *                              state survives. Null for a system write.
	 *
	 * @return integer How many read states were invalidated.
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
	 */
	public function invalidate(string $objectUuid, ?string $actorUid = null): int {
		if ($objectUuid === '') {
			return 0;
		}

		$this->forgetMemo();

		try {
			return $this->mapper->invalidate(objectUuid: $objectUuid, exceptUserId: $actorUid);
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf('[ReadStateService] invalidation failed for %s: %s', $objectUuid, $e->getMessage())
			);
			return 0;
		}

	}//end invalidate()

	/**
	 * The object is gone, so nothing about it is unread.
	 *
	 * @param string $objectUuid The deleted object's uuid.
	 *
	 * @return integer How many read states were removed.
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md
	 */
	public function cleanupForObject(string $objectUuid): int {
		if ($objectUuid === '') {
			return 0;
		}

		$this->forgetMemo();

		try {
			return $this->mapper->deleteByObject(objectUuid: $objectUuid);
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf('[ReadStateService] cleanup failed for %s: %s', $objectUuid, $e->getMessage())
			);
			return 0;
		}

	}//end cleanupForObject()

	/**
	 * Drop the per-request seen set.
	 *
	 * Public because the write path calls it: a save inside the same request as
	 * a read must not answer the marker from a set taken before the write.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md
	 */
	public function forgetMemo(): void {
		$this->seenByCallerMemo = null;

	}//end forgetMemo()

	/**
	 * The calling user's seen set, loaded once per request.
	 *
	 * @return array<string, bool> Seen uuid to true.
	 */
	private function seenSetForCaller(): array {
		if ($this->seenByCallerMemo !== null) {
			return $this->seenByCallerMemo;
		}

		$uid = $this->callerUid();
		if ($uid === null || $uid === '') {
			$this->seenByCallerMemo = [];
			return $this->seenByCallerMemo;
		}

		try {
			$this->seenByCallerMemo = array_fill_keys($this->mapper->uuidsForUser(userId: $uid), true);
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf('[ReadStateService] seen set lookup failed for "%s": %s', $uid, $e->getMessage())
			);
			$this->seenByCallerMemo = [];
		}

		return $this->seenByCallerMemo;

	}//end seenSetForCaller()

	/**
	 * The moment one sub-resource was last seen.
	 *
	 * Falls back to the object's own seen moment, so opening a case before its
	 * messages tab existed does not badge every historical message.
	 *
	 * @param string $name The sub-resource name.
	 * @param array<string, string> $subSeen The stored per-sub-resource moments.
	 * @param ObjectReadState|null $state The read state row, when there is one.
	 *
	 * @return DateTime|null The moment, or null when the object was never seen.
	 */
	private function seenMoment(string $name, array $subSeen, ?ObjectReadState $state): ?DateTime {
		$stamp = ($subSeen[$name] ?? null);
		if (is_string($stamp) === true && $stamp !== '') {
			try {
				return new DateTime($stamp);
			} catch (\Throwable $e) {
				// A stored stamp that will not parse is treated as never seen,
				// which badges rather than hides. The alternative is a silently
				// empty badge on a row nobody can explain.
				return null;
			}
		}

		return $state?->getLastSeenAt();

	}//end seenMoment()

	/**
	 * How many entries of one sub-resource arrived after a moment.
	 *
	 * @param ObjectEntity $object The object.
	 * @param array<string, string> $descriptor The sub-resource descriptor.
	 * @param DateTime|null $since The seen moment, or null when never seen.
	 *
	 * @return integer The unread count.
	 */
	private function countSince(ObjectEntity $object, array $descriptor, ?DateTime $since): int {
		if (($descriptor['kind'] ?? '') === SubstantiveChangeEvaluator::FILES) {
			return $this->countFilesSince(object: $object, since: $since);
		}

		$body = $object->getObject();
		if (is_array($body) === false) {
			return 0;
		}

		$entries = ($body[$descriptor['property'] ?? ''] ?? null);
		if (is_array($entries) === false) {
			return 0;
		}

		$field = (string)($descriptor['dateField'] ?? '');
		$count = 0;
		foreach ($entries as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			if ($this->isAfter(value: ($entry[$field] ?? null), since: $since) === true) {
				$count++;
			}
		}

		return $count;

	}//end countSince()

	/**
	 * How many of the object's files changed after a moment.
	 *
	 * @param ObjectEntity $object The object.
	 * @param DateTime|null $since The seen moment, or null when never seen.
	 *
	 * @return integer The unread file count.
	 */
	private function countFilesSince(ObjectEntity $object, ?DateTime $since): int {
		try {
			$files = $this->fileMapper->getFilesForObject(object: $object);
		} catch (\Throwable $e) {
			// A folder lookup must never take out an object read. An absent
			// count reads as nought, which under-badges rather than failing.
			$this->logger->debug(
				sprintf('[ReadStateService] file count skipped for %s: %s', (string)$object->getUuid(), $e->getMessage())
			);
			return 0;
		}

		$count = 0;
		foreach ($files as $file) {
			if (is_array($file) === false) {
				continue;
			}

			if ($this->isAfter(value: ($file['mtime'] ?? null), since: $since) === true) {
				$count++;
			}
		}

		return $count;

	}//end countFilesSince()

	/**
	 * Whether a stored moment is later than the seen moment.
	 *
	 * Accepts the two shapes the sources actually carry: an ISO string on an
	 * object property, and a unix timestamp on a filecache row.
	 *
	 * @param mixed $value The entry's moment.
	 * @param DateTime|null $since The seen moment, or null when never seen.
	 *
	 * @return boolean True when the entry is newer than the seen moment.
	 */
	private function isAfter(mixed $value, ?DateTime $since): bool {
		if ($value === null || $value === '') {
			return false;
		}

		$moment = null;
		if (is_int($value) === true || (is_string($value) === true && ctype_digit($value) === true)) {
			$moment = (new DateTime())->setTimestamp((int)$value);
		}

		if ($moment === null && is_string($value) === true) {
			try {
				$moment = new DateTime($value);
			} catch (\Throwable $e) {
				return false;
			}
		}

		if ($moment === null) {
			return false;
		}

		if ($since === null) {
			// Never seen: every entry that carries a moment at all is new.
			return true;
		}

		return $moment > $since;

	}//end isAfter()

	/**
	 * The calling user's uid, or a refusal.
	 *
	 * @throws NotAuthorizedException When the caller is anonymous.
	 *
	 * @return string The uid.
	 */
	private function requireCaller(): string {
		$uid = $this->callerUid();
		if ($uid === null || $uid === '') {
			throw new NotAuthorizedException(message: 'A read state needs a signed-in user');
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
			throw new NotAuthorizedException(message: 'This object cannot carry a read state');
		}

		return $uuid;

	}//end requireUuid()
}//end class
