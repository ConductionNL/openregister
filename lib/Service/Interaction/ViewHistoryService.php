<?php

/**
 * ViewHistoryService: what this person has looked at lately.
 *
 * The throttle lives here rather than in the mapper, because "at most one
 * record per user, object and minute" is a policy and the uniqueness of a row
 * is not. It is the reason a recorded moment reads as the FIRST open of a
 * burst: a refresh inside the window is skipped entirely, so four reads in ten
 * seconds leave the time the first one wrote.
 *
 * Recording is best-effort by construction. Opening an object must not fail
 * because the history could not be written, so every write is wrapped and a
 * failure is logged and swallowed.
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
 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Interaction;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectView;
use OCA\OpenRegister\Db\ObjectViewMapper;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Record and read one user's view history.
 */
class ViewHistoryService {

	/**
	 * How long a recorded view suppresses the next one, in seconds.
	 *
	 * A detail page can read its object several times over while it renders —
	 * the page itself, a tab, a refresh — and each of those is the same act of
	 * opening it. One minute is the window the spec names.
	 *
	 * @var integer
	 */
	public const THROTTLE_SECONDS = 60;

	/**
	 * Constructor.
	 *
	 * @param ObjectViewMapper $mapper The view rows.
	 * @param IUserSession $userSession Resolves the calling user.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ObjectViewMapper $mapper,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The calling user's uid.
	 *
	 * @return string|null The uid, or null when anonymous.
	 *
	 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md
	 */
	public function callerUid(): ?string {
		return $this->userSession->getUser()?->getUID();

	}//end callerUid()

	/**
	 * Record that the calling user has opened an object.
	 *
	 * Returns null in three different situations on purpose — anonymous, inside
	 * the throttle window, and a failed write — because the caller treats all
	 * three the same way: nothing more to do, and never an error on the read
	 * that triggered it.
	 *
	 * @param ObjectEntity $object The object, already resolved through RBAC.
	 * @param string|null $register The register as the caller addressed it.
	 * @param string|null $schema The schema as the caller addressed it.
	 * @param DateTime|null $now The moment, injectable so the throttle is testable.
	 *
	 * @return ObjectView|null The stored row, or null when nothing was written.
	 *
	 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md#requirement-opening-an-object-records-a-per-user-view
	 */
	public function recordView(
		ObjectEntity $object,
		?string $register = null,
		?string $schema = null,
		?DateTime $now = null
	): ?ObjectView {
		$uid = $this->callerUid();
		$uuid = (string)$object->getUuid();
		if ($uid === null || $uid === '' || $uuid === '') {
			return null;
		}

		$at = ($now ?? new DateTime());

		try {
			if ($this->isThrottled(userId: $uid, objectUuid: $uuid, now: $at) === true) {
				return null;
			}

			return $this->mapper->record(
				userId: $uid,
				objectUuid: $uuid,
				register: $register,
				schema: $schema,
				at: $at
			);
		} catch (\Throwable $e) {
			// Recording that you looked at something must never take out the
			// read of that thing.
			$this->logger->debug(
				sprintf('[ViewHistoryService] view not recorded for %s: %s', $uuid, $e->getMessage())
			);
			return null;
		}//end try

	}//end recordView()

	/**
	 * The objects the calling user has opened, most recently first.
	 *
	 * @param int|null $limit How many to return, defaulting to the whole history.
	 *
	 * @return array<int, string> The viewed object uuids, newest first.
	 *
	 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md#requirement-favourites-and-recent-are-lenses-on-the-object-query
	 */
	public function recentUuidsForCaller(?int $limit = null): array {
		$uid = $this->callerUid();
		if ($uid === null || $uid === '') {
			return [];
		}

		try {
			return $this->mapper->uuidsForUser(
				userId: $uid,
				limit: ($limit ?? ObjectViewMapper::HISTORY_LIMIT)
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[ViewHistoryService] recent lookup failed',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return [];
		}

	}//end recentUuidsForCaller()

	/**
	 * Remove every view of an object that is gone.
	 *
	 * @param string $objectUuid The object's uuid.
	 *
	 * @return integer How many views were removed.
	 *
	 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md#requirement-opening-an-object-records-a-per-user-view
	 */
	public function cleanupForObject(string $objectUuid): int {
		if ($objectUuid === '') {
			return 0;
		}

		return $this->mapper->deleteByObject(objectUuid: $objectUuid);

	}//end cleanupForObject()

	/**
	 * Whether a view was already recorded inside the throttle window.
	 *
	 * @param string $userId The viewing user's uid.
	 * @param string $objectUuid The object's uuid.
	 * @param DateTime $now The moment being considered.
	 *
	 * @return boolean True when the last recorded view is younger than the window.
	 *
	 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md#requirement-opening-an-object-records-a-per-user-view
	 */
	private function isThrottled(string $userId, string $objectUuid, DateTime $now): bool {
		$existing = $this->mapper->findOne(userId: $userId, objectUuid: $objectUuid);
		$last = $existing?->getViewedAt();
		if ($last === null) {
			return false;
		}

		return ($now->getTimestamp() - $last->getTimestamp()) < self::THROTTLE_SECONDS;

	}//end isThrottled()
}//end class
