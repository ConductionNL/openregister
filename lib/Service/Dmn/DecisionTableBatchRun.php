<?php

/**
 * OpenRegister decision-table batch run.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Dmn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Dmn;

/**
 * The state of one DecisionTableBulkGuard::refusals() call (ADR-011 Rule 3).
 *
 * The batch's default register and schema, which register/schema pairs are
 * decision tables (asked once per pair), the keys claimed so far, and the
 * row being checked. It never leaves DecisionTableBulkGuard.
 *
 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
 */
final class DecisionTableBatchRun {

	/**
	 * The batch's default schema reference.
	 *
	 * @var string|null
	 */
	public ?string $defaultSchema = null;

	/**
	 * The batch's default register reference.
	 *
	 * @var string|null
	 */
	public ?string $defaultRegister = null;

	/**
	 * Whether a `schema|register` pair is a decision table, asked once per pair.
	 *
	 * @var array<string, bool|null>
	 */
	public array $isTable = [];

	/**
	 * The keys claimed so far, with the uuid of the claiming row.
	 *
	 * @var array<string, string>
	 */
	public array $claimed = [];

	/**
	 * The schema reference of the row being checked.
	 *
	 * @var string|null
	 */
	public ?string $schema = null;

	/**
	 * The register reference of the row being checked.
	 *
	 * @var string|null
	 */
	public ?string $register = null;

	/**
	 * The uuid of the row being checked, '' for a new row.
	 *
	 * @var string
	 */
	public string $uuid = '';
}//end class
