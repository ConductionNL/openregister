<?php
/**
 * ObjectPresenceTracker: the presence register, answering the object API's port.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Kernel\Contracts\Core\ObjectPresenceInterface;

/**
 * Turns the object API's presence questions into heartbeats and lookups on the register.
 *
 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-an-object-knows-who-has-it-open
 */
final class ObjectPresenceTracker implements ObjectPresenceInterface {

	/**
	 * Constructor
	 *
	 * @param PresenceServiceInterface $presence The presence register.
	 */
	public function __construct(
		private readonly PresenceServiceInterface $presence,
	) {
	}//end __construct()

	/**
	 * Record a heartbeat now.
	 *
	 * @param string $userId     The reader.
	 * @param string $objectUuid The object, as the caller named it.
	 *
	 * @return bool True when this beat was an arrival.
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-an-object-knows-who-has-it-open
	 */
	public function beat(string $userId, string $objectUuid): bool {
		$beat = $this->presence->heartbeat(beat: new PresenceBeat(userId: $userId, objectUuid: $objectUuid, now: new DateTimeImmutable()));
		return $beat['arrived'] === true;
	}//end beat()

	/**
	 * Record a departure.
	 *
	 * @param string $userId     The reader.
	 * @param string $objectUuid The object, as the caller named it.
	 *
	 * @return bool True when they were present and are now not.
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-an-object-knows-who-has-it-open
	 */
	public function depart(string $userId, string $objectUuid): bool {
		return $this->presence->depart(userId: $userId, objectUuid: $objectUuid);
	}//end depart()

	/**
	 * The present readers.
	 *
	 * @param string $objectUuid The object, as the caller named it.
	 * @param string $exceptUser A reader to leave out.
	 *
	 * @return array<int, array<string, mixed>> The readers, oldest arrival first.
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-an-object-knows-who-has-it-open
	 */
	public function present(string $objectUuid, string $exceptUser = ''): array {
		return $this->presence->present(lookup: new PresenceLookup(objectUuid: $objectUuid, exceptUser: $exceptUser));
	}//end present()
}//end class
