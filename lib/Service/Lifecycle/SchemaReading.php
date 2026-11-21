<?php

/**
 * OpenRegister SchemaReading
 *
 * The schema mapper and logger of a class that reads schema annotations.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
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

namespace OCA\OpenRegister\Service\Lifecycle;

use OCA\OpenRegister\Db\SchemaMapperInterface;
use Psr\Log\LoggerInterface;

/**
 * Reading a schema's annotations on the write path: the mapper that loads the
 * schema row and the logger that reports a schema or object that could not be
 * read (ADR-011 Rule 4 capability aggregate).
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class SchemaReading {
	/**
	 * Bundle the services.
	 *
	 * @param SchemaMapperInterface $schemaMapper Loads the schema row.
	 * @param LoggerInterface       $logger       Reports a failed read.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
