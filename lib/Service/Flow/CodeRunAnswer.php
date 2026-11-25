<?php

/**
 * CodeRunAnswer: what the code runner returned for one call.
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
 * @spec openspec/changes/flow-code-step-in-a-sidecar/specs/flow-engine/spec.md#requirement-a-code-step-runs-authored-javascript-outside-nextcloud
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * The items the code returned, its log lines and how long it ran.
 *
 * @spec openspec/changes/flow-code-step-in-a-sidecar/specs/flow-engine/spec.md#requirement-a-code-step-runs-authored-javascript-outside-nextcloud
 */
final class CodeRunAnswer {
	/**
	 * Constructor.
	 *
	 * @param array<int, array>  $items      The output items, each `{json: {...}}`.
	 * @param array<int, string> $logs       The runner's log lines for the call.
	 * @param int                $durationMs How long the code ran.
	 */
	public function __construct(
		public readonly array $items,
		public readonly array $logs=[],
		public readonly int $durationMs=0,
	) {
	}//end __construct()
}//end class
