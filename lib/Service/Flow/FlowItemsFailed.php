<?php

/**
 * A per-item step that failed for some of its items and not for others.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-error-branch-and-step-retry/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use RuntimeException;
use Throwable;

/**
 * Thrown by a node that isolates item failures: it carries the items that
 * succeeded and, per failed item, the item and its error.
 *
 * Under `onError: "branch"` the engine sends only the failed items down the
 * `error` output and the succeeded ones on along the normal edge. Under every
 * other policy it is an ordinary step failure: the message names how many
 * items failed and the first error.
 *
 * @spec openspec/changes/flow-error-branch-and-step-retry/specs/flow-engine/spec.md
 */
final class FlowItemsFailed extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param list<mixed>                                $succeeded The items the step produced for the inputs that worked.
	 * @param list<array{item: mixed, error: Throwable}> $failed    Each input that failed, with its error.
	 */
	public function __construct(
		public readonly array $succeeded,
		public readonly array $failed,
	) {
		$first = ($failed[0]['error'] ?? null);
		$message = sprintf('%d item(s) failed', count($failed));
		if ($first instanceof Throwable) {
			$message .= ': '.$first->getMessage();
		}

		parent::__construct(message: $message);
	}//end __construct()
}//end class
