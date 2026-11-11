<?php

/**
 * OpenRegister ObjectSlugWrite
 *
 * One entity whose slug a save, seed import, restore or move is deciding (#4037).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The entity, where its slug must be unique, what the client sent and the write path.
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
 */
final class ObjectSlugWrite {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity    $entity     The entity being saved (uuid already set).
	 * @param ObjectSlugScope $scope      Its register (null when it did not resolve) and schema.
	 * @param string|null     $clientSlug `@self.slug` or top-level `slug` as sent.
	 * @param string          $path       single | seed | restore | move (log context).
	 */
	public function __construct(
		public readonly ObjectEntity $entity,
		public readonly ObjectSlugScope $scope,
		public readonly string|null $clientSlug,
		public readonly string $path,
	) {
	}//end __construct()

	/**
	 * The entity's uuid as the log lines name it.
	 *
	 * @return string The uuid.
	 */
	public function objectUuid(): string {
		return (string)$this->entity->getUuid();
	}//end objectUuid()

	/**
	 * The question this write asks for a slug.
	 *
	 * @param string $slug The slug wanted (or, for a frozen object, the slug that stays).
	 *
	 * @return ObjectSlugWant The want.
	 */
	public function want(string $slug): ObjectSlugWant {
		return new ObjectSlugWant(slug: $slug, scope: $this->scope, objectUuid: $this->objectUuid(), path: $this->path);
	}//end want()
}//end class
