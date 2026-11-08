<?php

/**
 * What a relation type's declared `exposes` allows, as the schema save check uses it.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Relation
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
 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Relation;

/**
 * Only the methods the schema save check uses.
 */
interface LinkExposureInterface {

	/**
	 * Whether the relation type declares an `exposes` list at all.
	 *
	 * @param array<string, mixed> $relationType The relation type descriptor.
	 *
	 * @return bool True when it declares one.
	 *
	 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
	 */
	public function declaresExposure(array $relationType): bool;

	/**
	 * Why a declared `exposes` may not be saved, or null when it may.
	 *
	 * @param array<string, mixed> $relationType  The relation type descriptor.
	 * @param array<int, string>   $farProperties The far schema's declared properties.
	 * @param string               $typeName      The type, for the message.
	 *
	 * @return string|null The reason.
	 *
	 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
	 */
	public function refusalFor(array $relationType, array $farProperties, string $typeName): ?string;
}//end interface
