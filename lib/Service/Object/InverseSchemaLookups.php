<?php

/**
 * OpenRegister InverseSchemaLookups
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
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
namespace OCA\OpenRegister\Service\Object;

use Closure;
use OCA\OpenRegister\Db\Schema;

/**
 * How inverse relation rendering finds schemas, through the renderer's cache.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class InverseSchemaLookups {

	/**
	 * Constructor.
	 *
	 * @param Closure(int|string): (Schema|null) $find      Finds a schema by id.
	 * @param Closure(string): string            $resolveId Resolves a schema reference to a schema id.
	 */
	public function __construct(
		public readonly Closure $find,
		public readonly Closure $resolveId,
	) {
	}//end __construct()
}//end class
