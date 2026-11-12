<?php

/**
 * StateHistoryMapperInterface: what callers outside the Db component use of StateHistoryMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

use DateTimeInterface;
use OCA\OpenRegister\Db\StateHistory\TrackedProperty;
use OCP\AppFramework\Db\Entity;

/**
 * The methods of StateHistoryMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to StateHistoryMapper. Guarded by C04InterfaceBindingsTest.
 */
interface StateHistoryMapperInterface {

	/**
	 * Close the interval an object is currently in, for one declared property.
	 *
	 * Idempotent: with no open interval it updates nothing, which is the
	 * correct answer for the first transition an object ever makes.
	 *
	 * @param TrackedProperty   $tracked The object and its declared lifecycle property.
	 * @param DateTimeInterface $leftAt  The moment it left.
	 *
	 * @return int Rows closed.
	 */
	public function closeOpenInterval(TrackedProperty $tracked, DateTimeInterface $leftAt): int;

	/**
	 * The objects whose declared property ever held this value.
	 *
	 * An open interval counts: "was ever in bezwaar" is true of a case sitting
	 * in bezwaar right now, and making the current state a separate case is how
	 * a filter comes to disagree with the list beside it.
	 *
	 * @param string $property The declared lifecycle property.
	 * @param string $value    The value it must have held.
	 *
	 * @return string[] Candidate object uuids.
	 *
	 * @psalm-return list<string>
	 */
	public function findObjectUuidsEverAt(string $property, string $value): array;

	/**
	 * The objects whose declared property changed inside a period.
	 *
	 * A change is an interval BEGINNING: the moment the property took a new
	 * value. Counting interval ends as well would answer every change twice,
	 * once at each side of the same moment.
	 *
	 * @param string            $property The declared lifecycle property.
	 * @param StateChangePeriod $period   The period, both ends inclusive.
	 *
	 * @return string[] Candidate object uuids.
	 *
	 * @psalm-return list<string>
	 */
	public function findObjectUuidsChangedBetween(string $property, StateChangePeriod $period): array;

	/**
	 * Drop every interval of one object.
	 *
	 * Used by the rebuild, which replaces an object's line rather than adding
	 * to it: a second pass that appended would double every interval and make
	 * "was ever" true twice.
	 *
	 * @param string $objectUuid The object.
	 *
	 * @return int Rows removed.
	 */
	public function deleteForObject(string $objectUuid): int;

	/**
	 * Drop the closed intervals of one object that ended at or before a moment.
	 *
	 * 🔴 ONLY CLOSED INTERVALS. The open one describes the state the object is
	 * in now, which the object itself still asserts; it is not derived from the
	 * purged payload and removing it would make a case sitting in bezwaar for
	 * ten years invisible to "was ever in bezwaar" the day its oldest audit row
	 * expired.
	 *
	 * @param string            $objectUuid The object.
	 * @param DateTimeInterface $horizon    The newest purged moment.
	 *
	 * @return int Rows removed.
	 */
	public function pruneClosedIntervalsBefore(string $objectUuid, DateTimeInterface $horizon): int;

	/**
	 * Insert one row.
	 *
	 * @param Entity $entity The row to insert.
	 *
	 * @return Entity The inserted row, with its id.
	 */
	public function insert(Entity $entity): Entity;
}//end interface
