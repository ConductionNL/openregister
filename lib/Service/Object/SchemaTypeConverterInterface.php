<?php

/**
 * SchemaTypeConverterInterface: what callers outside the Service layer use of SchemaTypeConverter.
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
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * Converts stored column values to the type their schema property declares,
 * and restores the stored form of `type: string` values a read decoded.
 */
interface SchemaTypeConverterInterface {

	/**
	 * Convert a database column value to the type declared in the JSON Schema.
	 *
	 * @param mixed  $value      The raw column value as returned by the DB driver.
	 * @param string $schemaType The declared JSON-Schema property type.
	 *
	 * @return mixed The schema-typed PHP value; null stays null.
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	public function convertValue(mixed $value, string $schemaType): mixed;

	/**
	 * Re-encode the `type: string` values a read decoded and the caller did not send.
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
