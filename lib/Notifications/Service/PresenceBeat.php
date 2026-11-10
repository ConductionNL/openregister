<?php

/**
 * PresenceBeat
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-an-object-knows-who-has-it-open
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service;

use DateTimeInterface;

/**
 * One heartbeat: a reader says, at a moment, that they still have an object open.
 *
 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-an-object-knows-who-has-it-open
 */
final class PresenceBeat {

	/**
	 * Constructor.
	 *
	 * @param string            $userId     The reader.
	 * @param string            $objectUuid The object.
	 * @param DateTimeInterface $now        The moment of the beat.
	 */
	public function __construct(
		public readonly string $userId,
		public readonly string $objectUuid,
		public readonly DateTimeInterface $now,
	) {
	}//end __construct()

	/**
	 * The same beat with the reader and object trimmed.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-an-object-knows-who-has-it-open
	 */
	public function trimmed(): self {
		return new self(userId: trim($this->userId), objectUuid: trim($this->objectUuid), now: $this->now);
	}//end trimmed()
}//end class
