<?php
/**
 * ObjectPresenceInterface: who has an object open, as the object API asks it.
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

/**
 * The presence register behind the object API's presence endpoints.
 *
 * The object API (Core) asks after it has read the object under the object's own
 * RBAC; Notifications keeps the register and binds its answer in its own wiring.
 *
 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-an-object-knows-who-has-it-open
 */
interface ObjectPresenceInterface {

	/**
	 * How often a client is expected to say it is still there, in seconds.
	 *
	 * @var int
	 */
	public const BEAT_SECONDS = 30;

	/**
	 * Say that a reader is still looking at an object.
	 *
	 * @param string $userId     The reader.
	 * @param string $objectUuid The object, as the caller named it.
	 *
	 * @return bool True when this beat was an arrival, false for a renewal.
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-an-object-knows-who-has-it-open
	 */
	public function beat(string $userId, string $objectUuid): bool;

	/**
	 * Say that a reader has closed an object.
	 *
	 * @param string $userId     The reader.
	 * @param string $objectUuid The object, as the caller named it.
	 *
	 * @return bool True when they were present and are now not.
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-an-object-knows-who-has-it-open
	 */
	public function depart(string $userId, string $objectUuid): bool;

	/**
	 * The readers who have an object open, oldest arrival first.
	 *
	 * @param string $objectUuid The object, as the caller named it.
	 * @param string $exceptUser A reader to leave out, usually the caller; empty leaves none out.
	 *
	 * @return array<int, array<string, mixed>> The readers.
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-an-object-knows-who-has-it-open
	 */
	public function present(string $objectUuid, string $exceptUser = ''): array;
}//end interface
