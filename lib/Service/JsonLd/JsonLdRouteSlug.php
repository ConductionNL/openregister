<?php

/**
 * JsonLdRouteSlug
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\JsonLd;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The identifier a JSON-LD route uses for a register or schema.
 *
 * Shared by the context service and the serializer, which both build routes
 * from it.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/json-ld-output/spec.md
 */
final class JsonLdRouteSlug {

	/**
	 * Resolve the slug for an entity, falling back to its UUID then id so a
	 * route parameter is always available.
	 *
	 * @param Register|Schema $entity The entity.
	 *
	 * @return string The slug-like identifier.
	 *
	 * @spec openspec/specs/json-ld-output/spec.md
	 */
	public static function of(Register|Schema $entity): string {
		$slug = $entity->getSlug();
		if (is_string($slug) === true && $slug !== '') {
			return $slug;
		}

		$uuid = $entity->getUuid();
		if (is_string($uuid) === true && $uuid !== '') {
			return $uuid;
		}

		return (string)$entity->getId();
	}//end of()
}//end class
