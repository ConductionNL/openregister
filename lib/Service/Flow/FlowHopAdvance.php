<?php

/**
 * Where a fired hop left its token and its items.
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
 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * The items, the items per place and the exits taken after a hop fired.
 *
 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
 */
final class FlowHopAdvance {
	/**
	 * Set up the outcome.
	 *
	 * @param array<int, mixed>    $items      The hop's items, exit-id tags resolved to places.
	 * @param array<string, array> $placeItems The items per place after the firing.
	 * @param array<string>        $taken      The output places the exit claimed.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	public function __construct(
		public readonly array $items,
		public readonly array $placeItems,
		public readonly array $taken,
	) {
	}//end __construct()
}//end class
