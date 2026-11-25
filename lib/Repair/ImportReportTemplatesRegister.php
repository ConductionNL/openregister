<?php

/**
 * ImportReportTemplatesRegister: materialises the report-templates register.
 *
 * OpenRegister does not self-import its own register JSON at boot (ADR-037).
 * This repair step imports `lib/Settings/report_templates_register.json` so the
 * `report-templates` schema lands idempotently on `occ upgrade` (matched by
 * slug, `version_compare`-gated). Apps ship their templates as seed objects of
 * that schema and render them through `POST /api/reports/generate`. Never
 * throws: a failure logs a warning and leaves the instance otherwise healthy.
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
 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-configurable-report-templates
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

/**
 * Imports the report-templates register descriptor idempotently on upgrade/install.
 */
class ImportReportTemplatesRegister extends AbstractRegisterImportStep {
	/**
	 * App-relative path to the register descriptor imported by this step.
	 *
	 * @var string
	 */
	protected const REGISTER_PATH = '/lib/Settings/report_templates_register.json';

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
	protected const LABEL = 'Report-templates';

	/**
	 * Output line after a successful import.
	 *
	 * @var string
	 */
	protected const IMPORTED = 'Report-templates register imported (report-templates schema)';

	/**
	 * Get the name of this repair step.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-configurable-report-templates
	 */
	public function getName(): string {
		return 'Import OpenRegister report-templates register (report-templates schema)';
	}//end getName()
}//end class
