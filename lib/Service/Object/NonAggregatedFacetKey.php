<?php

/**
 * OpenRegister NonAggregatedFacetKey
 *
 * The response key of a facet computed for one schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/faceting-configuration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * Names a non-aggregated facet: its sanitised title, otherwise `field_schema_id`.
 *
 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
 */
final class NonAggregatedFacetKey {

	/**
	 * Constructor.
	 *
	 * @param string $fieldName The property name.
	 * @param int    $schemaId  The schema the facet belongs to.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function __construct(
		private readonly string $fieldName,
		private readonly int $schemaId,
	) {
	}//end __construct()

	/**
	 * The key under a facet configuration.
	 *
	 * @param array<string, mixed> $facetConfig The facet configuration.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function under(array $facetConfig): string {
		if (empty($facetConfig['title']) === false) {
			$key = preg_replace('/([a-z])([A-Z])/', '$1_$2', $facetConfig['title']);
			$key = strtolower(str_replace(' ', '_', $key));
			$key = preg_replace('/[^a-z0-9_]/', '', $key);
			return $key;
		}

		return $this->fieldName . '_schema_' . $this->schemaId;
	}//end under()
}//end class
