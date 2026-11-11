<?php

/**
 * Reads a concept scheme out of the vocabulary register, as the concept delete guard asks for it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vocabulary
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Vocabulary;

/**
 * Reads a concept scheme out of the vocabulary register, as the concept delete guard asks for it.
 *
 * Declares only what callers outside lib/Service use; the container binds it
 * to {@see ConceptRepository} in ContractBindings.
 */
interface ConceptRepositoryInterface {

	/**
	 * The scheme a uuid or slug reference points at, or null.
	 *
	 * `inScheme` on a concept stores the scheme object's uuid, not its uri, so
	 * a guard that has a concept in hand and needs the scheme's uri has to
	 * come back through the register to get it.
	 *
	 * @param string $reference The scheme object's uuid, id or slug.
	 *
	 * @return array<string,mixed>|null The scheme's decoded object data.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function schemeByReference(string $reference): ?array;
}//end interface
