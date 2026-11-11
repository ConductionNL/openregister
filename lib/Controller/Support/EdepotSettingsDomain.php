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

use OCA\OpenRegister\Service\Edepot\EdepotTransferServiceInterface;
use OCA\OpenRegister\Service\Edepot\Transport\OpenConnectorTransportInterface;
use OCA\OpenRegister\Service\Edepot\Transport\RestApiTransportInterface;
use OCA\OpenRegister\Service\Edepot\Transport\SftpTransportInterface;

/**
 * The domain services of the edepot settings API.
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-configurable-e-depot-endpoint-settings
 */
final class EdepotSettingsDomain {
	/**
	 * Bundle the services.
	 *
	 * @param EdepotTransferServiceInterface  $transferService The transfer service.
	 * @param SftpTransportInterface          $sftpTransport   SFTP transport.
	 * @param RestApiTransportInterface       $restTransport   REST API transport.
	 * @param OpenConnectorTransportInterface $ocTransport     OpenConnector transport.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-configurable-e-depot-endpoint-settings
	 */
	public function __construct(
		public readonly EdepotTransferServiceInterface $transferService,
		public readonly SftpTransportInterface $sftpTransport,
		public readonly RestApiTransportInterface $restTransport,
		public readonly OpenConnectorTransportInterface $ocTransport,
	) {
	}//end __construct()
}//end class
