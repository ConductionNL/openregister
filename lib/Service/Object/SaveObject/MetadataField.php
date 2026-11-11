<?php

/**
 * OpenRegister MetadataField
 *
 * One metadata column (name, description or summary) to hydrate: the field
 * path the schema configures, and the common field names tried after it.
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

/**
 * The configured path and common fallback fields of one metadata column.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final readonly class MetadataField {

	/**
	 * Constructor.
	 *
	 * @param string $column The column: name, description or summary.
	 * @param string|null $path The configured field path, fallback chain or template.
	 * @param array<string> $commonFields The field names tried, in order, when the path yields nothing.
	 */
	public function __construct(
		public string $column,
		public ?string $path,
		public array $commonFields,
	) {
	}//end __construct()

	/**
	 * The schema properties this column resolves relation fields against: all of them for the name, none for
	 * the other columns.
	 *
	 * @param array $schemaProperties The schema properties.
	 *
	 * @return array
	 */
	public function relationProperties(array $schemaProperties): array {
		if ($this->column === 'name') {
			return $schemaProperties;
		}

		return [];
	}//end relationProperties()
}//end class
