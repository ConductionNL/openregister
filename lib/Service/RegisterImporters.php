<?php

/**
 * The importers a register descriptor install runs: the configuration importer, the counted import and the app import runner.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * The importers a register descriptor install runs: the configuration importer, the counted import and the app import runner.
 *
 * @spec openspec/specs/register-descriptor-admin/spec.md
 */
final class RegisterImporters {
	/**
	 * Bundle the collaborators.
	 *
	 * @param AppConfigurationImporterInterface $configurationService Imports a configuration file.
	 * @param CountedRegisterImport             $counted              Counts what an import created.
	 * @param AppRegisterImportRunner           $importRunner         Runs an app register import.
	 *
	 * @spec openspec/specs/register-descriptor-admin/spec.md
	 */
	public function __construct(
		public readonly AppConfigurationImporterInterface $configurationService,
		public readonly CountedRegisterImport $counted,
		public readonly AppRegisterImportRunner $importRunner,
	) {
	}//end __construct()
}//end class
