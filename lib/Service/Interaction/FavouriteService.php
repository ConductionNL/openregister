<?php

/**
 * FavouriteService: the one place that decides whose star it is.
 *
 * The permission rule is short, and it is the same one the read state has: your
 * own star and nobody else's, with no admin override, because a favourite is a
 * fact about a person rather than about the object. An administrator has no
 * business reading which cases a caseworker finds interesting.
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
use OCA\OpenRegister\Db\ObjectFavourite;
use OCA\OpenRegister\Db\ObjectFavouriteMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Star an object, unstar it, and answer whether it is starred.
 */
class FavouriteService {

	/**
	 * The uuids the current user has starred, loaded once per request.
	 *
	 * `@self.favourite` is rendered on every row of every list, so reading it
	 * per row would be an N+1 on the hot read path. Null until first read.
	 *
	 * @var array<string, bool>|null
	 */
	private ?array $starredByCallerMemo = null;

	/**
	 * Constructor.
	 *
	 * @param ObjectFavouriteMapper $mapper The favourite rows.
	 * @param IUserSession $userSession Resolves the calling user.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ObjectFavouriteMapper $mapper,
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
	 * Star an object for the calling user.
	 *
	 * Idempotent, and writes nothing on the object itself: no audit entry, no
	 * version. That is the requirement, not an optimisation.
	 *
	 * @param ObjectEntity $object The object, already resolved through RBAC.
	 * @param string|null $register The register as the caller addressed it.
	 * @param string|null $schema The schema as the caller addressed it.
	 *
	 * @throws NotAuthorizedException When the caller is anonymous.
	 *
	 * @return ObjectFavourite The stored star.
	 *
	 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md#requirement-a-user-can-star-an-object-without-changing-it
	 */
	public function star(
		ObjectEntity $object,
		?string $register = null,
		?string $schema = null
	): ObjectFavourite {
		$uid = $this->requireCaller();
		$uuid = $this->requireUuid(object: $object);

		$this->forgetMemo();

		return $this->mapper->star(
			userId: $uid,
			objectUuid: $uuid,
			register: $register,
			schema: $schema,
			at: new DateTime()
		);

	}//end star()

	/**
	 * Remove the calling user's star from an object.
	 *
	 * It stays starred for everybody else: the row this removes is nobody's but
	 * the caller's.
	 *
	 * @param ObjectEntity $object The object, already resolved through RBAC.
	 *
	 * @throws NotAuthorizedException When the caller is anonymous.
	 *
	 * @return boolean True when a star was removed.
	 *
	 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md#requirement-a-user-can-star-an-object-without-changing-it
	 */
	public function unstar(ObjectEntity $object): bool {
		$uid = $this->requireCaller();
		$uuid = $this->requireUuid(object: $object);

		$this->forgetMemo();

		return $this->mapper->unstar(userId: $uid, objectUuid: $uuid);

	}//end unstar()

	/**
	 * Whether an object is starred by the calling user.
	 *
	 * Answered from the per-request starred set so that rendering a page of
	 * objects costs one query rather than one per row.
	 *
	 * @param string $objectUuid The object's uuid.
	 *
	 * @return boolean True when the caller has starred the object.
	 *
	 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md#requirement-a-user-can-star-an-object-without-changing-it
	 */
	public function isStarredByCaller(string $objectUuid): bool {
		if ($objectUuid === '') {
			return false;
		}

		return isset($this->starredSetForCaller()[$objectUuid]);

	}//end isStarredByCaller()

	/**
	 * One user's star, and nobody else's.
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
	 * @return ObjectFavourite|null The row, or null when the object is not starred.
	 *
	 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md#requirement-a-user-can-star-an-object-without-changing-it
	 */
	public function favouriteFor(ObjectEntity $object, ?string $userId = null): ?ObjectFavourite {
		$uid = $this->requireCaller();
		if ($userId !== null && $userId !== '' && $userId !== $uid) {
			throw new NotAuthorizedException(
				message: 'A favourite is private to the user it belongs to'
			);
		}

		return $this->mapper->findOne(userId: $uid, objectUuid: $this->requireUuid(object: $object));

	}//end favouriteFor()

	/**
	 * Remove every star on an object that is gone.
	 *
	 * @param string $objectUuid The object's uuid.
	 *
	 * @return integer How many stars were removed.
	 *
	 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md#requirement-a-user-can-star-an-object-without-changing-it
	 */
	public function cleanupForObject(string $objectUuid): int {
		if ($objectUuid === '') {
			return 0;
		}

		$this->forgetMemo();

		return $this->mapper->deleteByObject(objectUuid: $objectUuid);

	}//end cleanupForObject()

	/**
	 * Drop the per-request starred set.
	 *
	 * Called after any write, because a memo that survives its own invalidation
	 * is how a star renders as unstarred immediately after being placed.
	 *
	 * Public because the write path calls it, and because a leaf app that
	 * stars through the service inside a request that already rendered a list
	 * needs the same escape.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md
	 */
	public function forgetMemo(): void {
		$this->starredByCallerMemo = null;

	}//end forgetMemo()

	/**
	 * The caller's starred uuids as a lookup map, loaded at most once.
	 *
	 * A failed lookup memoises EMPTY rather than staying null, so a database
	 * that is refusing connections costs one failed query per request instead
	 * of one per rendered row.
	 *
	 * @return array<string, bool> Starred uuid to true.
	 */
	private function starredSetForCaller(): array {
		if ($this->starredByCallerMemo !== null) {
			return $this->starredByCallerMemo;
		}

		$uid = $this->callerUid();
		if ($uid === null || $uid === '') {
			$this->starredByCallerMemo = [];
			return $this->starredByCallerMemo;
		}

		try {
			$uuids = $this->mapper->uuidsForUser(userId: $uid);
			$this->starredByCallerMemo = array_fill_keys($uuids, true);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[FavouriteService] starred set lookup failed',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			$this->starredByCallerMemo = [];
		}

		return $this->starredByCallerMemo;

	}//end starredSetForCaller()

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
			throw new NotAuthorizedException(
				message: 'A favourite belongs to a user, and there is no user on this request'
			);
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
			throw new NotAuthorizedException(message: 'The object carries no uuid to star');
		}

		return $uuid;

	}//end requireUuid()
}//end class
