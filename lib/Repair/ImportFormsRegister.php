<?php

/**
 * ImportFormsRegister — materialises the forms data model.
 *
 * Imports `lib/Settings/forms_register.json` through
 * `ConfigurationService::importFromApp()` so the `forms` register and its
 * `form` and `journey` schemas land idempotently on install and on
 * `occ upgrade`, matched by slug and gated on the descriptor version, the
 * same convention as {@see ImportSurveyRegister}.
 *
 * Bump `components.registers.forms.version` when a schema changes, or an
 * upgraded instance keeps the old shape.
 *
 * Never throws. A failure logs a warning and leaves the instance otherwise
 * healthy.
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
 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-journey-must-declare-steps-branching-writes-and-access
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

/**
 * Repair step that imports the forms register and its form and journey schemas.
 *
 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-journey-must-declare-steps-branching-writes-and-access
 */
class ImportFormsRegister extends AbstractRegisterImportStep {

	/**
	 * The descriptor, relative to the app root.
	 *
	 * @var string
	 */
	protected const REGISTER_PATH = '/lib/Settings/forms_register.json';

	/**
	 * The descriptor version this step ships.
	 *
	 * @var string
	 */
	protected const REGISTER_VERSION = '1.0.0';

	/**
	 * The register's name in output.
	 *
	 * @var string
	 */
	protected const LABEL = 'Forms';

	/**
	 * What a successful import says.
	 *
	 * @var string
	 */
	protected const IMPORTED = 'Forms register imported (forms register + form and journey)';

	/**
	 * The step's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-journey-must-declare-steps-branching-writes-and-access
	 */
	public function getName(): string {
		return 'Import OpenRegister forms register (forms register + form and journey schemas)';
	}//end getName()
}//end class
