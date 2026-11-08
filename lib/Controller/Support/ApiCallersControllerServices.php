<?php

/**
 * OpenRegister ApiCallersController services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\ApiCallRecordMapperInterface;
use OCA\OpenRegister\Service\ApiVersion\ApiVersionCatalogue;
use OCP\IAppConfig;

/**
 * The collaborators of ApiCallersController, wired by DI autowiring.
 *
 * @spec openspec/specs/api-surface-governance/spec.md
 */
final class ApiCallersControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ApiCallRecordMapperInterface $records Reads the caller record.
	 * @param ApiVersionCatalogue $catalogue The declared contract versions.
	 * @param IAppConfig $appConfig Reads and writes the declaration.
	 * @param ControllerSession $session Session, group manager and logger.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md
	 */
	public function __construct(
		public readonly ApiCallRecordMapperInterface $records,
		public readonly ApiVersionCatalogue $catalogue,
		public readonly IAppConfig $appConfig,
		public readonly ControllerSession $session,
	) {
	}//end __construct()
}//end class
