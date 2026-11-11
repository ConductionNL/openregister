<?php

/**
 * PresenceServiceInterface: the public surface of PresenceService, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notifications
 * @package  OCA\OpenRegister\Notifications\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service;

use DateTimeInterface;
use OCA\OpenRegister\Notifications\Db\ObjectPresence;

/**
 * Every public method of PresenceService. Callers depend on this interface, not on the class.
 */
interface PresenceServiceInterface {

	/**
	 * How often a client is expected to say it is still there, in seconds.
	 *
	 * @var int
	 */
	public const BEAT_SECONDS = 30;

	/**
	 * Say that a reader is still looking at an object.
	 *
	 * @param PresenceBeat $beat The reader, the object and the moment.
	 *
	 * @return array{arrived: bool, presence: ObjectPresence|null} Whether this was an ARRIVAL.
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-an-object-knows-who-has-it-open
	 */
	public function heartbeat(PresenceBeat $beat): array;

	/**
	 * Say that a reader has closed the object.
	 *
	 * @param string $userId     The reader.
	 * @param string $objectUuid The object.
	 *
	 * @return boolean True when they were present and are now not.
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-an-object-knows-who-has-it-open
	 */
	public function depart(string $userId, string $objectUuid): bool;

	/**
	 * Who is present on an object right now.
	 *
	 * @param PresenceLookup $lookup The object, a reader to leave out, and the clock for tests.
	 *
	 * @return array<int, array<string, mixed>> The readers, oldest arrival first.
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-an-object-knows-who-has-it-open
	 */
	public function present(PresenceLookup $lookup): array;
}//end interface
