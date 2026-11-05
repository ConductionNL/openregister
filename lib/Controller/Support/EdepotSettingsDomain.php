<?php

/**
 * OpenRegister edepot settings domain
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

use OCA\OpenRegister\Service\Edepot\EdepotTransferService;
use OCA\OpenRegister\Service\Edepot\Transport\OpenConnectorTransport;
use OCA\OpenRegister\Service\Edepot\Transport\RestApiTransport;
use OCA\OpenRegister\Service\Edepot\Transport\SftpTransport;

/**
 * The domain services of the edepot settings API.
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-configurable-e-depot-endpoint-settings
 */
final class EdepotSettingsDomain {
	/**
	 * Bundle the services.
	 *
	 * @param EdepotTransferService  $transferService The transfer service.
	 * @param SftpTransport          $sftpTransport   SFTP transport.
	 * @param RestApiTransport       $restTransport   REST API transport.
	 * @param OpenConnectorTransport $ocTransport     OpenConnector transport.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-configurable-e-depot-endpoint-settings
	 */
	public function __construct(
		public readonly EdepotTransferService $transferService,
		public readonly SftpTransport $sftpTransport,
		public readonly RestApiTransport $restTransport,
		public readonly OpenConnectorTransport $ocTransport,
	) {
	}//end __construct()
}//end class
