<?php

/**
 * The optional parts of one flow item: its attachments, the input item it
 * came from and the output branch it routes to.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Options for building one flow item ({@see FlowItemShape::item()}).
 *
 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
 */
final class FlowItemOptions {
	/**
	 * Constructor.
	 *
	 * @param array       $binary        File attachments, keyed by name.
	 * @param int|null    $fromItemIndex Input item this came from, when known.
	 * @param string|null $output        The output branch this item routes to, when set.
	 */
	public function __construct(
		public readonly array $binary = [],
		public readonly ?int $fromItemIndex = null,
		public readonly ?string $output = null,
	) {
	}//end __construct()
}//end class
