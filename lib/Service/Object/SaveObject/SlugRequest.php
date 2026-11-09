<?php

/**
 * OpenRegister slug request
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * One entity asking for its slug on a single save or a seed import: the entity, its schema, and the slug the client sent.
 *
 * Built by ObjectSlugHandler and never leaves ObjectSlugHandler.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class SlugRequest {
	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $entity     The entity being saved or seeded (uuid already set).
	 * @param Schema       $schema     Its schema.
	 * @param string|null  $clientSlug `@self.slug` or top-level `slug` as sent.
	 */
	public function __construct(
		public readonly ObjectEntity $entity,
		public readonly Schema $schema,
		public readonly string|null $clientSlug,
	) {
	}//end __construct()
}//end class
