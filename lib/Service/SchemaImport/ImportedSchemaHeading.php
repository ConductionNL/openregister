<?php

/**
 * OpenRegister Schema Import — ImportedSchemaHeading value object.
 *
 * The title and description an importer gives an ImportedSchema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category ValueObject
 * @package  OCA\OpenRegister\Service\SchemaImport
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

namespace OCA\OpenRegister\Service\SchemaImport;

/**
 * The title and description of an imported schema.
 *
 * @spec openspec/specs/schema-import/spec.md
 */
final readonly class ImportedSchemaHeading {
	/**
	 * Constructor.
	 *
	 * @param string $title The schema title.
	 * @param string $description The schema description.
	 */
	public function __construct(
		public string $title,
		public string $description,
	) {
	}//end __construct()
}//end class
