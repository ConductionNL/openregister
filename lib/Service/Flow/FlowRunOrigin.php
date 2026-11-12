<?php

/**
 * FlowRunOrigin: where a fresh run starts.
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
 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * The seed items and the start node of a fresh run; a resumed run ignores both.
 *
 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
 */
final class FlowRunOrigin {

	/**
	 * Constructor.
	 *
	 * @param array<int, mixed>|null $items   Items to start from.
	 * @param string|null            $startAt Node to start from (run-from-here).
	 */
	public function __construct(
		public readonly ?array $items = null,
		public readonly ?string $startAt = null,
	) {
	}//end __construct()
}//end class
