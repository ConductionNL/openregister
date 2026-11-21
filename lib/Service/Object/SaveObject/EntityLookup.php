<?php

/**
 * OpenRegister entity lookup
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

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

use Closure;

/**
 * Where a schema or register reference is looked up: by id, by listing every candidate, and the kind named in a log line.
 *
 * Built by RelationCascadeHandler for one reference and never leaves RelationCascadeHandler.
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */
final class EntityLookup {
	/**
	 * Constructor.
	 *
	 * @param string  $kind    What is looked up ("schema" or "register"), for the log line.
	 * @param Closure $find    Finds the entity by id; throws DoesNotExistException when there is none.
	 * @param Closure $findAll Lists the candidate entities.
	 */
	public function __construct(
		public readonly string $kind,
		public readonly Closure $find,
		public readonly Closure $findAll,
	) {
	}//end __construct()
}//end class
