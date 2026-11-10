<?php

/**
 * ConceptShapeGuardInterface: the concept shape guard as the coded-value validation listener reaches it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vocabulary
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

namespace OCA\OpenRegister\Service\Vocabulary;

use OCA\OpenRegister\Db\Schema;

/**
 * The concept shape guard, typed for callers outside the Service component.
 *
 * Its only outside caller, the coded-value validation listener, calls violations(),
 * so that is the whole contract. Binding it in ContractBindings lets that caller
 * resolve the guard without naming the class.
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */
interface ConceptShapeGuardInterface {
	/**
	 * The fields a concept fails its scheme's declared shape on.
	 *
	 * @param array<string,mixed> $object The submitted concept data.
	 * @param Schema              $schema The schema being written against.
	 *
	 * @return array<string,string> The refusals keyed by field name, empty when the concept is valid.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function violations(array $object, Schema $schema): array;
}//end interface
