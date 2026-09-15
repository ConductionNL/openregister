<?php

/**
 * OpenRegister AutoTransitionCandidate
 *
 * The one automatic transition a stored object is eligible for, as decided by
 * {@see AutoTransitionSelector}.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
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

namespace OCA\OpenRegister\Service\Lifecycle;

use OCA\OpenRegister\Db\Flow;

/**
 * An automatic transition that is ready to be fired.
 *
 * A value object rather than an array, because the pass, the job and the log
 * lines all read the same four fields and a typo in an array key is a silent
 * null in every one of them.
 */
final class AutoTransitionCandidate {

	/**
	 * The transition's declared name, as it would be posted to /transition.
	 *
	 * @var string
	 */
	public readonly string $action;

	/**
	 * The lifecycle value the object holds now.
	 *
	 * @var string
	 */
	public readonly string $from;

	/**
	 * The lifecycle value the move would reach.
	 *
	 * @var string
	 */
	public readonly string $to;

	/**
	 * `Flow::MODE_SYNC` or `Flow::MODE_ASYNC`, never any other spelling.
	 *
	 * @var string
	 */
	public readonly string $mode;

	/**
	 * Capture the decided move.
	 *
	 * @param string $action The transition's declared name.
	 * @param string $from The lifecycle value the object holds now.
	 * @param string $to The lifecycle value the move would reach.
	 * @param string $mode The execution mode, one of the flow engine's two values.
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	public function __construct(string $action, string $from, string $to, string $mode) {
		$this->action = $action;
		$this->from = $from;
		$this->to = $to;
		$this->mode = $mode;
	}//end __construct()

	/**
	 * Whether this move is applied in the triggering request.
	 *
	 * The comparison lives here rather than at the call site so that only one
	 * class needs to know the flow engine's spelling of the two modes. A caller
	 * asks what kind of move this is; it does not need `Flow` to find out.
	 *
	 * @return boolean True for a sync move, false for a queued one.
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	public function isSync(): bool {
		return $this->mode === Flow::MODE_SYNC;
	}//end isSync()
}//end class
