<?php

/**
 * A server-side signal was refused before anything was delivered.
 *
 * The guarded signal seam ({@see \OCA\OpenRegister\Service\Flow\FlowRunSignalService})
 * refuses with THIS exception rather than a nullable return, deliberately: the
 * unguarded primitive already uses `null` to mean "not suspended", and a guard
 * whose refusal can be ignored by ignoring a return value is not a guard. A
 * caller that catches this has been told exactly why — the reason constant —
 * without matching on message strings.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-engine-consumer-seams/specs/flow-engine-consumer-seams/spec.md#requirement-a-server-side-signal-passes-the-same-guard-as-the-http-resume
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use RuntimeException;

/**
 * Thrown by the guarded signal seam when a signal may not be delivered.
 */
class FlowSignalRefused extends RuntimeException {
	/**
	 * No run carries the given uuid.
	 *
	 * @var string
	 */
	public const RUN_NOT_FOUND = 'run-not-found';

	/**
	 * The awaiting step is assigned, and the actor is not its assignee.
	 *
	 * @var string
	 */
	public const NOT_ASSIGNEE = 'not-assignee';

	/**
	 * The run is not suspended, so there is nothing to answer.
	 *
	 * @var string
	 */
	public const NOT_SUSPENDED = 'not-suspended';

	/**
	 * Constructor.
	 *
	 * @param string $reason One of the reason constants.
	 * @param string $message What went wrong, for a human.
	 * @param string $runUuid The run the signal addressed.
	 * @param string|null $actorUid The refused actor, or null when anonymous.
	 */
	public function __construct(
		private readonly string $reason,
		string $message,
		private readonly string $runUuid = '',
		private readonly ?string $actorUid = null,
	) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * Why the signal was refused — one of the reason constants.
	 *
	 * @return string The reason.
	 */
	public function getReason(): string {
		return $this->reason;
	}//end getReason()

	/**
	 * The run the refused signal addressed.
	 *
	 * @return string The run uuid, or '' when unknown.
	 */
	public function getRunUuid(): string {
		return $this->runUuid;
	}//end getRunUuid()

	/**
	 * The refused actor.
	 *
	 * @return string|null The actor uid, or null when the caller was anonymous.
	 */
	public function getActorUid(): ?string {
		return $this->actorUid;
	}//end getActorUid()
}//end class
