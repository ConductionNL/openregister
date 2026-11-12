<?php

/**
 * SchemaTypeConverterInterface: what callers outside the Service component ask of SchemaTypeConverter.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

/**
 * Schema-typed reads of a raw column value, and the PATCH-side restore of
 * untouched string-typed JSON values.
 *
 * @spec openspec/specs/schema-driven-read-coercion/spec.md
 */
interface SchemaTypeConverterInterface {

	/**
	 * Convert a raw column value to the PHP type its schema property declares.
	 *
	 * @param mixed  $value      The raw column value as returned by the DB driver.
	 * @param string $schemaType The declared JSON-Schema property type.
	 *
	 * @return mixed The schema-typed PHP value.
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	public function convertValue(mixed $value, string $schemaType): mixed;

	/**
	 * Restore untouched string-typed properties that the read decoded into arrays.
	 *
	 * @param array $data         The object data about to be saved.
	 * @param array $properties   The schema's property definitions, keyed by property name.
	 * @param array $suppliedKeys Keys the caller actually sent; these are left exactly as merged.
	 *
	 * @return array The data with untouched string-typed JSON values restored to their stored form.
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	public function restoreStringTypedValues(array $data, array $properties, array $suppliedKeys=[]): array;
}//end interface
