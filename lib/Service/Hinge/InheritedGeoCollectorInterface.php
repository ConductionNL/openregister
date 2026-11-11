<?php

/**
 * InheritedGeoCollectorInterface: what callers outside lib/Service use of InheritedGeoCollector.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hinge
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Hinge;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * The part of InheritedGeoCollector that code outside lib/Service uses.
 */
interface InheritedGeoCollectorInterface {

	/**
	 * Collect one record's map features, its own first and its inherited after.
	 *
	 * A schema declaring no inheritance gets its own features and nothing else,
	 * which is what it gets today.
	 *
	 * @param ObjectEntity $object The record whose features are read.
	 * @param Schema|null  $schema Its schema, holding the inheritance declaration.
	 * @param bool         $_rbac  Apply the caller's access to referenced records.
	 *
	 * @return array{type: string, features: array<int, array>} A GeoJSON FeatureCollection.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `_rbac` is the established
	 *   access-flag convention across the object layer (MagicSearchHandler,
	 *   PermissionHandlerInterface, ObjectService all take it); splitting this one method
	 *   in two would make it the exception rather than the rule.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function collect(ObjectEntity $object, ?Schema $schema, bool $_rbac = true): array;
}//end interface
