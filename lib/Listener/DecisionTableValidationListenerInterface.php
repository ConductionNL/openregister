<?php

/**
 * DecisionTableValidationListenerInterface: what callers outside the Listener component use of DecisionTableValidationListener.
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

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;

/**
 * The single-save decision-table rule, for the bulk save that dispatches no save event.
 *
 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
 */
interface DecisionTableValidationListenerInterface {

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

	/**
	 * What stops a row from being stored as a decision table, the row named by its reference.
	 *
	 * @param ObjectRef            $row  The row's register and schema reference (id or slug, '' for none) and uuid ('' for a new row).
	 * @param array<string, mixed> $data The row's own properties.
	 *
	 * @return array<int, string>|null Null when the row is no decision table, else the problems (empty when it is valid).
	 *
	 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
	 */
	public function problemsOf(ObjectRef $row, array $data): ?array;
}//end interface
