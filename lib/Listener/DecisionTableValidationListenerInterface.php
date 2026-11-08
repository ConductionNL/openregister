<?php

/**
 * DecisionTableValidationListenerInterface: what the bulk-save guard asks of the decision-table save rule.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
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

namespace OCA\OpenRegister\Listener;

/**
 * The single-save decision-table rule, as the bulk path asks it directly.
 * Only the methods DecisionTableBulkGuard calls.
 *
 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
 */
interface DecisionTableValidationListenerInterface {

	/**
	 * What stops a row from being stored as a decision table, for the single save and for the bulk path.
	 *
	 * @param string|null          $schema   The row's schema reference (id or slug).
	 * @param string|null          $register The row's register reference (id or slug).
	 * @param array<string, mixed> $data     The row's own properties.
	 * @param string               $uuid     The row's uuid, '' for a new row.
	 *
	 * @return array<int, string>|null Null when the row is no decision table, else the problems (empty when it is valid).
	 *
	 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
	 */
	public function problemsFor(?string $schema, ?string $register, array $data, string $uuid): ?array;

	/**
	 * Whether the object is a row of the decision-table schema.
	 *
	 * @param string|null $schema   The schema reference.
	 * @param string|null $register The register reference.
	 *
	 * @return bool|null True for a decision table, false for any other object, null when it cannot be told.
	 *
	 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
	 */
	public function isDecisionTableRef(?string $schema, ?string $register): ?bool;
}//end interface
