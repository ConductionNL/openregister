<?php

/**
 * FlowTriggerSet
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\Flow;

/**
 * The object triggers one flow subscribes to, and whether the flow is enabled
 * (ADR-011 Rule 2): what FlowTriggerMapper::replaceFor() writes for the flow.
 *
 * @spec exclude ADR-011 parameter object of FlowTriggerMapper::replaceFor(), not a capability of its own
 */
final class FlowTriggerSet {

	/**
	 * Constructor
	 *
	 * @param string                           $flowUuid The flow's UUID.
	 * @param array<int, array<string, mixed>> $triggers The derived triggers, each event/register/schema.
	 * @param bool                             $enabled  Whether the flow is enabled.
	 */
	public function __construct(
		public readonly string $flowUuid,
		public readonly array $triggers,
		public readonly bool $enabled,
	) {
	}//end __construct()
}//end class
