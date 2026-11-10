<?php

/**
 * A page-level map overview to declare.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service;

/**
 * A map overview: its stable key, where its markers come from, its title and its base layer.
 *
 * @spec openspec/specs/integration-maps-overview/spec.md
 */
final class MapOverview {
	/**
	 * Constructor.
	 *
	 * @param string                   $key       Stable overview/widget key.
	 * @param MapMarkerSource          $source    The register, schema, geo property and filters the markers come from.
	 * @param string|null              $title     Display title for the map widget.
	 * @param array<string,mixed>|null $baseLayer Declarative base-layer override; null = the default.
	 */
	public function __construct(
		public readonly string $key,
		public readonly MapMarkerSource $source,
		public readonly ?string $title = null,
		public readonly ?array $baseLayer = null,
	) {
	}//end __construct()

	/**
	 * The page-widget id of this overview.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/integration-maps-overview/spec.md
	 */
	public function widgetId(): string {
		return 'maps-overview:' . $this->key;
	}//end widgetId()
}//end class
