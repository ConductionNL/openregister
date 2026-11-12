<?php

/**
 * ImportFlowRegister — materialises the OpenRegister-native flow store.
 *
 * OpenRegister owns the flow engine but shipped no store to author a flow in:
 * only a consuming app could hold one. This repair step imports
 * `lib/Settings/flow_register.json` via `ConfigurationService::importFromApp()`
 * so the `flows` register and its `flow` schema land idempotently on install and
 * `occ upgrade` (matched by slug, version-gated) — the same register-import-via-
 * Repair convention as {@see ImportCredentialBrokerRegister}. With the store in
 * place the `OpenRegisterFlowResolver` (which reads this register/schema by
 * default) resolves flows authored here, so triggers, sub-flows and the /test
 * endpoint all work with an OpenRegister-native flow. Never throws — a failure
 * logs a warning and leaves the instance otherwise healthy.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
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
 * @spec openspec/specs/flow-store/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

/**
 * Imports the flow register descriptor idempotently on upgrade/install.
 */
class ImportFlowRegister extends AbstractRegisterImportStep {
	/**
	 * App-relative path to the register descriptor imported by this step.
	 *
	 * @var string
	 */
	protected const REGISTER_PATH = '/lib/Settings/flow_register.json';

	/**
	 * Descriptor version passed to the importer's version_compare gate.
	 *
	 * @var string
	 */
	protected const REGISTER_VERSION = '1.4.0';

	/**
	 * Name of the register in the step's output lines.
	 *
	 * @var string
	 */
	protected const LABEL = 'Flow';

	/**
	 * Output line after a successful import.
	 *
	 * @var string
	 */
	protected const IMPORTED = 'Flow register imported (flows register + flow schema)';

	/**
	 * Get the name of this repair step.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/specs/flow-store/spec.md
	 */
	public function getName(): string {
		return 'Import OpenRegister flow register (flows register + flow schema)';
	}//end getName()
}//end class
