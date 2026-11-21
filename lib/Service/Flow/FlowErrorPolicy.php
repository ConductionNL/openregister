<?php

/**
 * OpenRegister FlowErrorPolicy
 *
 * The one reading of a step's `onError` policy (flow-error-branch-and-step-retry D-3), shared by both
 * walks of FlowEngine and by FlowRunWorker so the three places cannot drift apart.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-error-branch-and-step-retry/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * What a failed step does: stop the run, continue past the step, or dead-letter the run.
 *
 * A step that declares no policy, or one this engine does not know (a typo),
 * reads as `stop`, so a mistake fails safe instead of silently carrying on.
 *
 * @spec openspec/changes/flow-error-branch-and-step-retry/specs/flow-engine/spec.md
 */
final class FlowErrorPolicy {

	/**
	 * End the run as failed (the default).
	 */
	public const STOP = 'stop';

	/**
	 * Record the failure and advance past the step.
	 */
	public const CONTINUE = 'continue';

	/**
	 * End the run in its own dead-letter state.
	 */
	public const DEAD_LETTER = 'dead_letter';

	/**
	 * Every policy the engine applies.
	 *
	 * @var list<string>
	 */
	public const KNOWN = [self::STOP, self::CONTINUE, self::DEAD_LETTER];

	/**
	 * The policy a step's failure follows.
	 *
	 * @param array<string, mixed>|null $step The lowered step (null: an unknown step).
	 *
	 * @return string One of {@see self::KNOWN}.
	 *
	 * @spec openspec/changes/flow-error-branch-and-step-retry/specs/flow-engine/spec.md
	 */
	public static function for(?array $step): string {
		$declared = ($step['onError'] ?? self::STOP);
		if (is_string($declared) === true && in_array($declared, self::KNOWN, true) === true) {
			return $declared;
		}

		return self::STOP;
	}//end for()

	/**
	 * The policy exactly as the step wrote it, for a log line that should show a typo as typed.
	 *
	 * @param array<string, mixed>|null $step The lowered step.
	 *
	 * @return string The declared policy, or `stop` when none is declared.
	 *
	 * @spec openspec/changes/flow-error-branch-and-step-retry/specs/flow-engine/spec.md
	 */
	public static function declared(?array $step): string {
		$declared = ($step['onError'] ?? self::STOP);
		if (is_scalar($declared) === true) {
			return (string)$declared;
		}

		return self::STOP;
	}//end declared()

	/**
	 * Whether a step's failure lets the walk go on.
	 *
	 * @param array<string, mixed>|null $step The lowered step.
	 *
	 * @return bool True under `continue`.
	 *
	 * @spec openspec/changes/flow-error-branch-and-step-retry/specs/flow-engine/spec.md
	 */
	public static function continues(?array $step): bool {
		return self::for(step: $step) === self::CONTINUE;
	}//end continues()
}//end class
