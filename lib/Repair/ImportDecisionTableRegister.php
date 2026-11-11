<?php

/**
 * ImportDecisionTableRegister: materialises the register named decision tables live in.
 *
 * `lib/Settings/decision_table_register.json` declares the `decision-tables`
 * register and its `decision-table` schema. Shipping the JSON alone does
 * nothing at runtime (ADR-005 Rule 1): without this step the register never
 * exists, every `tableRef` on a flow step names nothing, and the step is
 * refused at save with "no decision table has the key".
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Service\Configuration\DescriptorConfigIdsInterface;

/**
 * Imports the decision-table register descriptor idempotently.
 *
 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
 *
 * @psalm-suppress UnusedClass Instantiated by the NC repair framework (appinfo/info.xml).
 */
class ImportDecisionTableRegister extends AbstractRegisterImportStep {
	/**
	 * App-relative descriptor path.
	 *
	 * @var string
	 */
	protected const REGISTER_PATH = '/lib/Settings/decision_table_register.json';

	/**
	 * Descriptor version passed to the importer's version_compare gate.
	 *
	 * @var string
	 */
	protected const REGISTER_VERSION = '1.0.0';

	/**
	 * Name of the register in the step's output lines.
	 *
	 * @var string
	 */
	protected const LABEL = 'Decision-table';

	/**
	 * Output line after a successful import.
	 *
	 * @var string
	 */
	protected const IMPORTED = 'Decision-table register imported (decision-tables + decision-table)';

	/**
	 * Configuration identity for this descriptor.
	 *
	 * Its own app id, so the `importFromApp` version gate moves independently of
	 * the other system registers — a bump here must not be masked by one of
	 * theirs, and vice versa.
	 *
	 * @var string
	 */
	protected const CONFIG_APP_ID = DescriptorConfigIdsInterface::DECISION_TABLES;

	/**
	 * Get the name of this repair step.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
	 */
	public function getName(): string {
		return 'Import OpenRegister decision-table register (named decision tables flows reference)';
	}//end getName()
}//end class
