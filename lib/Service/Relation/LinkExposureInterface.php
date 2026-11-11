<?php

/**
 * LinkExposureInterface: what callers outside the component use of LinkExposure.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Relation;

/**
 * Declares only what callers outside the component use of LinkExposure; the rest
 * of the class stays its own.
 */
interface LinkExposureInterface {

	/**
	 * Whether a relation type declares a field set at all.
	 *
	 * 🔴 AN UNDECLARED `exposes` MEANS THE LINK NARROWS NOTHING — the behaviour
	 * every relation type has today, and the one every existing schema must
	 * keep. A PRESENT-BUT-EMPTY `exposes` means it exposes NOTHING, which is a
	 * different statement and a legitimate one: a link that says "this record
	 * is related, and you may see none of it". Reading the two the same way is
	 * how a list that filters nothing becomes a list that grants everything.
	 *
	 * @param array<string, mixed> $relationType The relation type descriptor.
	 *
	 * @return bool True when the type declares a set.
	 *
	 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
	 */
	public function declaresExposure(array $relationType): bool;

	/**
	 * Why a declared `exposes` may not be saved, or null when it may.
	 *
	 * @param array<string, mixed> $relationType    The relation type descriptor.
	 * @param array<int, string>   $farProperties   The far schema's declared properties.
	 * @param string               $typeName        The type, for the message.
	 *
	 * @return string|null The reason.
	 *
	 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
	 */
	public function refusalFor(array $relationType, array $farProperties, string $typeName): ?string;
}//end interface
