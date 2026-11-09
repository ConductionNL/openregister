<?php

/**
 * What a repair step needs to import one of OpenRegister's own register descriptors
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Service\AppConfigurationImporterInterface;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * Locates a bundled register descriptor on disk, imports it, and logs a failed import.
 *
 * Shared by the repair steps that seed a register from lib/Settings/*.json.
 *
 * @spec exclude Constructor aggregate for the register-seeding repair steps; no behaviour of its own.
 */
final class RegisterDescriptorImport {
	/**
	 * Bundle the collaborators.
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
