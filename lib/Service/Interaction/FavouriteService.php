<?php

/**
 * FavouriteService: the star's deprecated verbs, answered by the follow.
 *
 * Since `merge-follow-and-favourites` a favourite is a follow with
 * notifications off, stored in `openregister_watchers`. This class stays for
 * one release so the callers of the old surface (the favourite routes, the
 * prune listener, a leaf app that resolved it from the container) keep
 * working, and every method answers through WatcherService so the two can
 * never disagree about what a user follows.
 *
 * Remove it, with ObjectFavouriteController and the favourite routes, in the
 * release after the one that ships this change (design D-3).
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
 * @spec openspec/changes/merge-follow-and-favourites/specs/object-interactions/spec.md#requirement-a-user-can-star-an-object-without-changing-it
 *
 * @deprecated Follow through WatcherService. Removed in the release after merge-follow-and-favourites.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Interaction;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Watcher;
use OCA\OpenRegister\Exception\NotAuthorizedException;

/**
 * Star, unstar and "is it starred", as a quiet follow.
 *
 * @spec openspec/changes/merge-follow-and-favourites/specs/object-interactions/spec.md#requirement-a-user-can-star-an-object-without-changing-it
 */
class FavouriteService {

	/**
	 * Constructor.
	 *
	 * @param WatcherService $watchers The follow primitive that now holds every star.
	 */
	public function __construct(
		private readonly WatcherService $watchers,
	) {
	}//end __construct()

	/**
	 * The calling user's uid.
	 *
	 * @return string|null The uid, or null when anonymous.
	 *
	 * @spec openspec/changes/merge-follow-and-favourites/specs/object-interactions/spec.md#requirement-a-user-can-star-an-object-without-changing-it
	 */
	public function callerUid(): ?string {
		return $this->watchers->callerUid();

	}//end callerUid()

	/**
	 * Star an object: follow it with notifications off, unless already followed.
	 *
	 * @param ObjectEntity $object The object, already resolved through RBAC.
	 * @param string|null $register The register as the caller addressed it.
	 * @param string|null $schema The schema as the caller addressed it.
	 *
	 * @throws NotAuthorizedException When the caller is anonymous.
	 *
	 * @return Watcher The follow as it now stands.
	 *
	 * @spec openspec/changes/merge-follow-and-favourites/specs/object-interactions/spec.md#requirement-a-user-can-star-an-object-without-changing-it
	 */
	public function star(
		ObjectEntity $object,
		?string $register = null,
		?string $schema = null
	): Watcher {
		return $this->watchers->followQuietly(object: $object, register: $register, schema: $schema);

	}//end star()

	/**
	 * Unstar an object: stop following it.
	 *
	 * @param ObjectEntity $object The object, already resolved through RBAC.
	 *
	 * @throws NotAuthorizedException When the caller is anonymous.
	 *
	 * @return boolean True when a follow was removed.
	 *
	 * @spec openspec/changes/merge-follow-and-favourites/specs/object-interactions/spec.md#requirement-a-user-can-star-an-object-without-changing-it
	 */
	public function unstar(ObjectEntity $object): bool {
		return $this->watchers->unwatch(object: $object);

	}//end unstar()

	/**
	 * Whether the calling user follows an object, which is what "starred" now means.
	 *
	 * @param string $objectUuid The object's uuid.
	 *
	 * @return boolean True when the caller follows the object.
	 *
	 * @spec openspec/changes/merge-follow-and-favourites/specs/object-interactions/spec.md#requirement-a-user-can-star-an-object-without-changing-it
	 */
	public function isStarredByCaller(string $objectUuid): bool {
		return $this->watchers->isWatchedByCaller(objectUuid: $objectUuid);

	}//end isStarredByCaller()
}//end class
