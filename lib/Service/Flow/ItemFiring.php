<?php

/**
 * What one fired transition hands to item placement: the transition, the items
 * its step produced and the output places its exit claimed.
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
 * @spec openspec/changes/or-flow-per-item-routing/specs/flow-per-item-routing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * A fired transition's items and the exits they may travel.
 *
 * @spec openspec/changes/or-flow-per-item-routing/specs/flow-per-item-routing/spec.md
 */
final class ItemFiring {
	/**
	 * Describe the firing.
	 *
	 * @param object        $transition The fired transition.
	 * @param array         $items      What the step produced.
	 * @param array<string> $taken      The output places the exit claimed.
	 *
	 * @spec openspec/changes/or-flow-per-item-routing/specs/flow-per-item-routing/spec.md
	 */
	public function __construct(
		public readonly object $transition,
		public readonly array $items,
		public readonly array $taken,
	) {
	}//end __construct()
}//end class
