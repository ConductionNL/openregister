<?php

/**
 * OpenRegister PropertyDiscoveryRun
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/schema-property-exploration/spec.md
 */

namespace OCA\OpenRegister\Service;

/**
 * One pass of SchemaService over a sample of objects: the properties discovered so far,
 * their usage counts, and the property being recorded.
 *
 * Never leaves SchemaService.
 *
 * @spec openspec/specs/schema-property-exploration/spec.md
 */
final class PropertyDiscoveryRun {

	/**
	 * Discovered properties by name, extended as values are recorded.
	 *
	 * @var array
	 */
	public array $discovered = [];

	/**
	 * Usage statistics: `counts` per property name, later `percentages`.
	 *
	 * @var array
	 */
	public array $usageStats = [];

	/**
	 * The name of the property being recorded.
	 *
	 * @var string|integer
	 */
	public string|int $property = '';
}//end class
