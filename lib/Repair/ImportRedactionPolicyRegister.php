<?php

/**
 * OpenRegister Repair — import the redaction-policy register.
 *
 * Imports the `redaction-policy` register (`termRule`, `detectionPattern`,
 * `redactionProfile`) and its base layer: the objects the descriptor lists,
 * each with a stable `@self.slug` and `layer: base`, of which the shipped Woo
 * profile is one. The importer finds a listed object again by register,
 * schema and slug and updates it only when its shipped version rises; an
 * object the descriptor does not list, which is every `local` object an
 * organisation adds, is never read, written or deleted. Version-gated
 * (`force: false`); re-runs are no-ops.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#REQ-RPD-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Service\Configuration\DescriptorConfigIdsInterface;

/**
 * Imports the redaction-policy register descriptor and its base layer idempotently.
 *
 * @psalm-suppress UnusedClass Instantiated by the NC repair framework (appinfo/info.xml).
 *
 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#REQ-RPD-003
 */
class ImportRedactionPolicyRegister extends AbstractRegisterImportStep {

	/**
	 * App-relative descriptor path.
	 *
	 * @var string
	 */
	protected const REGISTER_PATH = '/lib/Settings/redaction_policy_register.json';

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
	protected const LABEL = 'Redaction-policy';

	/**
	 * Output line after a successful import.
	 *
	 * @var string
	 */
	protected const IMPORTED = 'Redaction-policy register imported (redaction-policy + termRule, detectionPattern, redactionProfile)';

	/**
	 * Configuration identity for this descriptor, so its version gate moves on its own.
	 *
	 * @var string
	 */
	protected const CONFIG_APP_ID = DescriptorConfigIdsInterface::REDACTION_POLICY;

	/**
	 * Get the name of this repair step.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#REQ-RPD-003
	 */
	public function getName(): string {
		return 'Import OpenRegister redaction-policy register (term rules, detection patterns, redaction profiles)';
	}//end getName()
}//end class
