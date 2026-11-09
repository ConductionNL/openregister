<?php

/**
 * GeneratedIdentifierDeclarationReader: reads a property's generated-identifier declaration.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
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

namespace OCA\OpenRegister\Service\Schemas;

/**
 * The named constructor of GeneratedIdentifierDeclaration, for callers outside the Service component.
 *
 * GeneratedIdentifierDeclaration owns the annotation and its parsing; this class
 * hands its result back typed as GeneratedIdentifierDeclarationInterface, so the
 * generated identifier listener reads declarations without naming the value class.
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-property-declares-a-generated-identifier-from-a-sequence-and-a-format
 */
final class GeneratedIdentifierDeclarationReader {
	/**
	 * Read the declaration off a property, or answer null when it carries none.
	 *
	 * @param array<string, mixed> $property The property definition.
	 * @param string               $path     The property's path, for the error message.
	 *
	 * @throws GeneratedIdentifierException When the declaration is malformed.
	 *
	 * @return GeneratedIdentifierDeclarationInterface|null The declaration, or null when the property has none.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-property-declares-a-generated-identifier-from-a-sequence-and-a-format
	 */
	public static function fromProperty(array $property, string $path=''): ?GeneratedIdentifierDeclarationInterface {
		return GeneratedIdentifierDeclaration::fromProperty(property: $property, path: $path);
	}//end fromProperty()
}//end class
