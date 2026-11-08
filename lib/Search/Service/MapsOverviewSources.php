<?php

/**
 * OpenRegister maps overview sources
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service;

use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Search\Service\Geo\GeoFeatureCollectionBuilder;
use OCA\OpenRegister\Service\Integration\PageWidgetRegistryInterface;

/**
 * Where the map overview reads its objects and geometry, and where it declares its widget.
 *
 * @spec openspec/specs/integration-maps-overview/spec.md
 */
final class MapsOverviewSources {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectOperationsInterface   $objectService Canonical RBAC-scoped object read facade.
	 * @param GeoFeatureCollectionBuilder $geoBuilder    Extracts geometry from object rows.
	 * @param PageWidgetRegistryInterface $registry      Registry for the page-widget surface.
	 *
	 * @spec openspec/specs/integration-maps-overview/spec.md
	 */
	public function __construct(
		public readonly ObjectOperationsInterface $objectService,
		public readonly GeoFeatureCollectionBuilder $geoBuilder,
		public readonly PageWidgetRegistryInterface $registry,
	) {
	}//end __construct()
}//end class
