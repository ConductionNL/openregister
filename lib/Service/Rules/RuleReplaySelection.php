<?php

/**
 * OpenRegister RuleReplaySelection
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\BulkJob\BulkJobSelection;

/**
 * The stored objects a rule replay runs over: the caller's selection, and the
 * schema and register it is scoped to.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final readonly class RuleReplaySelection {

	/**
	 * One selection.
	 *
	 * @param Schema               $schema     The schema the rule is declared on.
	 * @param integer|null         $registerId The register the selection lives in.
	 * @param array<string, mixed> $criteria   Either `{"ids": [...]}` or `{"query": {...}}`.
	 */
	public function __construct(
		public Schema $schema,
		public ?int $registerId,
		public array $criteria,
	) {
	}//end __construct()

	/**
	 * The register and schema the selection is scoped to, as query `@self` keys.
	 *
	 * @return array{register: int|null, schema: int|null} The scope.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function scope(): array {
		return ['register' => $this->registerId, 'schema' => $this->schema->getId()];
	}//end scope()

	/**
	 * The same selection, as the bulk job engine resolves it.
	 *
	 * @return BulkJobSelection The bulk job's selection.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function forBulkJob(): BulkJobSelection {
		return new BulkJobSelection(criteria: $this->criteria, registerId: $this->registerId, schemaId: $this->schema->getId());
	}//end forBulkJob()
}//end class
