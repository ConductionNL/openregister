<?php

/**
 * OpenRegister transfer execution transports
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob\Support
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

namespace OCA\OpenRegister\BackgroundJob\Support;

use OCA\OpenRegister\Service\Edepot\Transport\OpenConnectorTransportInterface;
use OCA\OpenRegister\Service\Edepot\Transport\RestApiTransportInterface;
use OCA\OpenRegister\Service\Edepot\Transport\SftpTransportInterface;

/**
 * The transports a transfer can be sent over; the configured one is picked per attempt.
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
 */
final class TransferExecutionTransports {
	/**
	 * Bundle the services.
	 *
	 * @param SftpTransportInterface          $sftpTransport SFTP transport.
	 * @param RestApiTransportInterface       $restTransport REST API transport (the default).
	 * @param OpenConnectorTransportInterface $ocTransport   OpenConnector transport.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
	 */
	public function __construct(
		public readonly SftpTransportInterface $sftpTransport,
		public readonly RestApiTransportInterface $restTransport,
		public readonly OpenConnectorTransportInterface $ocTransport,
	) {
	}//end __construct()
}//end class
