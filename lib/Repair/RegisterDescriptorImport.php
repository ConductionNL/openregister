<?php

/**
 * RegisterDescriptorImport: what a repair step needs to import a register descriptor shipped with the app.
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
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Service\AppConfigurationImporterInterface;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * The importer, the app path on disk and the log of a register-descriptor import step.
 *
 * Shared by every repair step that imports a descriptor from lib/Settings:
 * AbstractRegisterImportStep and its subclasses, and ImportDsarRegisters.
 *
 * @spec openspec/specs/register-descriptor-admin/spec.md
 */
final class RegisterDescriptorImport {

	/**
	 * Constructor.
	 *
	 * @param AppConfigurationImporterInterface $configurationService The OR configuration importer.
	 * @param IAppManager                       $appManager           Resolves the openregister app path on disk.
	 * @param LoggerInterface                   $logger               Logger for import diagnostics.
	 */
	public function __construct(
		public readonly AppConfigurationImporterInterface $configurationService,
		public readonly IAppManager $appManager,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
