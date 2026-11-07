<?php

/**
 * Answers whether a code-list value may be deleted, as the concept delete listener asks it.
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

use OCA\OpenRegister\Db\Schema;

/**
 * Answers whether a code-list value may be deleted, as the concept delete listener asks it.
 *
 * Declares only what callers outside lib/Service use; the container binds it
 * to {@see ConceptDeleteGuard} in ContractBindings.
 */
interface ConceptDeleteGuardInterface {

	/**
	 * Whether this object is a concept.
	 *
	 * @param Schema $schema The schema of the object being deleted.
	 *
	 * @return boolean True when the object is a concept.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function isConcept(Schema $schema): bool;

	/**
	 * Whether the value is one the product defines and therefore may not be deleted.
	 *
	 * @param array<string,mixed> $concept The concept's decoded object data.
	 *
	 * @return boolean True when the delete must be refused as system-defined.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function isSystemDefined(array $concept): bool;

	/**
	 * Count the objects that hold this concept, across every schema bound to
	 * its scheme.
	 *
	 * Only schemas that DECLARE a coded property on the concept's scheme are
	 * counted, which is what keeps this a bounded question rather than a scan
	 * of every table on the instance.
	 *
	 * @param array<string,mixed> $concept The concept's decoded object data.
	 * @param string $schemeUri The uri of the scheme the concept belongs to.
	 *
	 * @return array{count:int,holders:array<int,array{schema:string,property:string,count:int}>}
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function usage(array $concept, string $schemeUri): array;

	/**
	 * The refusal, in words, naming the count.
	 *
	 * @param array<string,mixed> $concept The concept's decoded object data.
	 * @param array{count:int,holders:array<int,array{schema:string,property:string,count:int}>} $usage The usage figures.
	 *
	 * @return string The message.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function inUseMessage(array $concept, array $usage): string;

	/**
	 * The refusal, in words, for a value the product defines.
	 *
	 * @param array<string,mixed> $concept The concept's decoded object data.
	 *
	 * @return string The message.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function systemDefinedMessage(array $concept): string;
}//end interface
