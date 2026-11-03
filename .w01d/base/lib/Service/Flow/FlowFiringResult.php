<?php

/**
 * What FlowRunCommit committed: the marking and place items as written, the
 * streams now live, and the durable firing count.
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
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * The committed state after one firing.
 */
class FlowFiringResult {

	/**
	 * Constructor.
	 *
	 * @param array<string, int> $marking The committed marking.
	 * @param array<string, array> $placeItems The committed per-place items.
	 * @param array<int, \OCA\OpenRegister\Db\FlowStream> $streams The run's streams after the commit.
	 * @param int $firings The run's committed firing count.
	 */
	public function __construct(
		public readonly array $marking,
		public readonly array $placeItems,
		public readonly array $streams,
		public readonly int $firings,
	) {

	}//end __construct()
}//end class
