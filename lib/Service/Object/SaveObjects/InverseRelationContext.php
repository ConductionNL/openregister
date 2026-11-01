<?php

/**
 * OpenRegister InverseRelationContext
 *
 * What a post-save inverse-relation pass needs: the schemas, their analysis and the fetched targets.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObjects
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */

namespace OCA\OpenRegister\Service\Object\SaveObjects;

use Closure;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The schema cache, the schema analysis callback and the related objects found by uuid.
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */
final class InverseRelationContext {

	/**
	 * Constructor.
	 *
	 * @param array<string, \OCA\OpenRegister\Db\Schema> $schemaCache The schemas by id
	 * @param Closure                                     $analysisOf  The schema analysis callback
	 * @param array<string, ObjectEntity>                 $targets     The related objects by uuid
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function __construct(
		private readonly array $schemaCache,
		private readonly Closure $analysisOf,
		private readonly array $targets = [],
	) {
	}//end __construct()

	/**
	 * The same context with the related objects found by uuid.
	 *
	 * @param array<string, ObjectEntity> $targets The related objects by uuid
	 *
	 * @return self
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function withTargets(array $targets): self {
		return new self(schemaCache: $this->schemaCache, analysisOf: $this->analysisOf, targets: $targets);
	}//end withTargets()

	/**
	 * The schema analysis of a saved object, or null when its schema is not in the cache.
	 *
	 * @param ObjectEntity $savedObject The saved object
	 *
	 * @return array|null The analysis
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function analysisOf(ObjectEntity $savedObject): mixed {
		$schema = $this->schemaCache[$savedObject->getSchema()] ?? null;
		if ($schema === null) {
			return null;
		}

		return ($this->analysisOf)($schema);
	}//end analysisOf()

	/**
	 * The related object with this uuid, or null when it was not found.
	 *
	 * @param mixed $relatedId The related object's uuid
	 *
	 * @return ObjectEntity|null
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function target(mixed $relatedId): ?ObjectEntity {
		return $this->targets[$relatedId] ?? null;
	}//end target()
}//end class
