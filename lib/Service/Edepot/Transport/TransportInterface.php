<?php

/**
 * OpenRegister e-Depot Transport Interface
 *
 * Defines the contract for SIP package transport implementations.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot\Transport
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot\Transport;

/**
 * Interface for e-Depot SIP package transport implementations.
 *
 * Implementations handle the actual transmission of SIP packages to
 * external e-Depot systems via different protocols (SFTP, REST, OpenConnector).
 */
interface TransportInterface {
	/**
	 * Send a SIP package to the e-Depot.
	 *
	 * @param string $sipFilePath The local path to the SIP ZIP archive.
	 * @param array<string,mixed> $config Transport configuration (endpoint, auth, etc.).
	 *
	 * @return TransportResult The result of the transport operation.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
	 */
	public function send(string $sipFilePath, array $config): TransportResult;

	/**
	 * Test the connection to the e-Depot endpoint.
	 *
	 * @param array<string,mixed> $config Transport configuration.
	 *
	 * @return bool True if connection test succeeds.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
	 */
	public function testConnection(array $config): bool;

	/**
	 * Get the transport name.
	 *
	 * @return string The transport protocol name (e.g., 'sftp', 'rest_api', 'openconnector').
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
	 */
	public function getName(): string;
}//end interface
