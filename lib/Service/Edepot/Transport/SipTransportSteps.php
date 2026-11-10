<?php

/**
 * SipTransportSteps: the steps every e-Depot transport takes around its own transfer.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot\Transport
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/edepot-transfer/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot\Transport;

use RuntimeException;
use Throwable;

/**
 * The config and file check before a transfer, and the logged failure after one.
 *
 * The using transport provides `validateConfig(array $config)` and
 * `$this->logger` (LoggerInterface).
 */
trait SipTransportSteps {

	/**
	 * Refuse a transfer whose config is incomplete or whose SIP file is missing.
	 *
	 * @param string               $sipFilePath The SIP package path.
	 * @param array<string, mixed> $config      The transport config.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the config is incomplete or the file is missing.
	 */
	private function assertSendable(string $sipFilePath, array $config): void {
		$this->validateConfig(config: $config);

		if (file_exists($sipFilePath) === false) {
			throw new RuntimeException("SIP file not found: {$sipFilePath}");
		}
	}//end assertSendable()

	/**
	 * Log a failed transfer and return its result.
	 *
	 * @param Throwable $cause   Why the transfer failed.
	 * @param string    $message The log message.
	 *
	 * @return TransportResult The failed result, carrying the cause's message.
	 */
	private function failedTransfer(Throwable $cause, string $message): TransportResult {
		$this->logger->error(message: $message, context: ['error' => $cause->getMessage()]);

		return (new TransportResult())->failed(errorMessage: $cause->getMessage());
	}//end failedTransfer()

	/**
	 * Log a failed connection test.
	 *
	 * @param Throwable $cause   Why the test failed.
	 * @param string    $message The log message.
	 *
	 * @return bool Always false.
	 */
	private function failedConnectionTest(Throwable $cause, string $message): bool {
		$this->logger->warning(message: $message, context: ['error' => $cause->getMessage()]);

		return false;
	}//end failedConnectionTest()
}//end trait
