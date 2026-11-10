<?php

/**
 * OpenRegister deferred object event lookup
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob\Support
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

namespace OCA\OpenRegister\BackgroundJob\Support;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * The mappers that re-read a deferred object, scoped to its register and schema.
 *
 * @spec openspec/changes/object-write-sub-500ms/specs/object-write-performance/spec.md
 */
final class DeferredObjectEventLookup {
	/**
	 * Bundle the services.
	 *
	 * @param MagicMapperInterface    $objectMapper   Re-reads the object at run time.
	 * @param RegisterMapperInterface $registerMapper Resolves the register to scope the read.
	 * @param SchemaMapperInterface   $schemaMapper   Resolves the schema to scope the read.
	 *
	 * @spec openspec/changes/object-write-sub-500ms/specs/object-write-performance/spec.md
	 */
	public function __construct(
		public readonly MagicMapperInterface $objectMapper,
		public readonly RegisterMapperInterface $registerMapper,
		public readonly SchemaMapperInterface $schemaMapper,
	) {
	}//end __construct()
}//end class
