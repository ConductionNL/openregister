<?php
/**
 * ObjectWatchMarkersInterface: whether the reader follows an object, as a render tells it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The watch state a render puts on an object: `@self.watching`, `@self.watchNotify` and `@self.watcherCount`.
 *
 * The render (Core) asks; the watcher service, which keeps who follows what, answers
 * and is bound to this contract in its own area's wiring.
 *
 * @spec openspec/specs/object-interactions/spec.md#requirement-a-user-can-watch-an-object-they-may-read
 */
interface ObjectWatchMarkersInterface {

	/**
	 * The uid of the caller of this request.
	 *
	 * @return string|null The uid, or null when anonymous.
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function callerUid(): ?string;

	/**
	 * Whether the caller follows the object.
	 *
	 * @param string $objectUuid The object's uuid.
	 *
	 * @return bool True when the caller watches it.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-a-user-can-watch-an-object-they-may-read
	 */
	public function isWatchedByCaller(string $objectUuid): bool;

	/**
	 * Whether the caller is notified about the object they follow.
	 *
	 * @param string $objectUuid The object's uuid.
	 *
	 * @return bool|null The switch, or null when the caller does not follow it.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-a-user-can-watch-an-object-they-may-read
	 */
	public function notifyForCaller(string $objectUuid): ?bool;

	/**
	 * Whether the caller may be told who follows the object.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return bool True when the caller may see its watchers.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-watchers-are-a-lens-and-a-list
	 */
	public function maySeeWatchers(ObjectEntity $object): bool;

	/**
	 * How many users follow the object.
	 *
	 * @param string $objectUuid The object's uuid.
	 *
	 * @return int The watcher count.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-watchers-are-a-lens-and-a-list
	 */
	public function watcherCount(string $objectUuid): int;
}//end interface
