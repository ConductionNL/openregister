<?php

/**
 * A validator of one schema-annotation vocabulary, as the schema save checks use it.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

/**
 * Validates one annotation block of a schema definition.
 *
 * The schema save checks in lib/Db/SchemaMapper reach every validator through
 * this one method, obtained from {@see SchemaAnnotationValidators}, so they do
 * not name the concrete validators. Each implementation keeps its own meaning
 * of the array it is handed (the schema shape, or its configuration block);
 * callers pass it positionally.
 */
interface SchemaAnnotationValidatorInterface {

	/**
	 * Validate the declaration.
	 *
	 * @param array<string, mixed> $declaration The shape the validator reads.
	 *
	 * @return array<int, mixed> The findings, empty when the declaration is sound.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function validate(array $declaration): array;
}//end interface
