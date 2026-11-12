<?php

/**
 * One source's claim on one attribute: the attribute and the source that supplies it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Survivorship
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Survivorship;

/**
 * One attribute as one source record supplies it. The tier it competes at
 * depends on both, so they travel as one.
 *
 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#2.1
 */
final readonly class AttributeClaim {

	/**
	 * Bundle the claim.
	 *
	 * @param string             $attribute The attribute the source supplies a value for.
	 * @param SurvivorshipSource $source    The record's source system and freshness anchor.
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#2.1
	 */
	public function __construct(
		public string $attribute,
		public SurvivorshipSource $source,
	) {
	}//end __construct()
}//end class
