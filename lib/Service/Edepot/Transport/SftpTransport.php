<?php

/**
 * OpenRegister SFTP Transport
 *
 * Transmits SIP packages to e-Depot systems via SFTP.
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
 *
 * @spec openspec/specs/edepot-transfer/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot\Transport;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * SFTP transport for e-Depot SIP packages.
 *
 * Uses phpseclib for SFTP connections. Uploads the SIP ZIP file and verifies
 * the remote file size matches the local file.
 *
 * @psalm-suppress UnusedClass
 */
class SftpTransport implements TransportInterface {

	use SipTransportSteps;
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Send a SIP package via SFTP.
	 *
	 * @param string $sipFilePath The local path to the SIP ZIP archive.
	 * @param array<string,mixed> $config SFTP configuration: host, port, username, password/keyPath, remotePath, hostKeyFingerprint.
	 *
	 * @return TransportResult The result of the transport.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
	 * @spec openspec/specs/edepot-transfer/spec.md
	 */
	public function send(string $sipFilePath, array $config): TransportResult {
		$this->logger->info(
			message: '[SftpTransport] Starting SFTP transfer',
			context: ['host' => ($config['host'] ?? 'unknown')]
		);

		try {
			$this->assertSendable(sipFilePath: $sipFilePath, config: $config);

			return $this->upload(sipFilePath: $sipFilePath, config: $config);
		} catch (\Exception $e) {
			return $this->failedTransfer(cause: $e, message: '[SftpTransport] SFTP transfer failed');
		}//end try
	}//end send()

	/**
	 * Upload a SIP file and verify the remote size matches the local one.
	 *
	 * @param string $sipFilePath The local path to the SIP ZIP archive.
	 * @param array<string, mixed> $config SFTP configuration.
	 *
	 * @return TransportResult The result, referring to the remote path.
	 *
	 * @throws RuntimeException When the upload fails or the sizes differ.
	 */
	private function upload(string $sipFilePath, array $config): TransportResult {
		$localSize = filesize($sipFilePath);
		[$remotePath, $remoteSize] = $this->put(sipFilePath: $sipFilePath, config: $config);
		if ($remoteSize !== $localSize) {
			throw new RuntimeException("Remote file size mismatch: expected {$localSize}, got {$remoteSize}");
		}

		$this->logger->info(message: '[SftpTransport] SFTP transfer successful', context: ['remotePath' => $remotePath, 'size' => $localSize]);

		return new TransportResult(transferReference: $remotePath);
	}//end upload()

	/**
	 * Put a SIP file on the server.
	 *
	 * @param string $sipFilePath The local path to the SIP ZIP archive.
	 * @param array<string, mixed> $config SFTP configuration.
	 *
	 * @return array{0: string, 1: mixed} The remote path and the size the server reports.
	 *
	 * @throws RuntimeException When the upload fails.
	 */
	private function put(string $sipFilePath, array $config): array {
		$remotePath = rtrim(($config['remotePath'] ?? '/'), '/') . '/' . basename($sipFilePath);
		$sftp = $this->createSftpConnection(config: $config);
		if ($sftp->put($remotePath, $sipFilePath, SFTP::SOURCE_LOCAL_FILE) === false) {
			throw new RuntimeException('SFTP upload failed: ' . $sftp->getLastSFTPError());
		}

		return [$remotePath, $sftp->filesize($remotePath)];
	}//end put()

	/**
	 * Test SFTP connection.
	 *
	 * @param array<string,mixed> $config SFTP configuration.
	 *
	 * @return bool True if connection test succeeds.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
	 */
	public function testConnection(array $config): bool {
		try {
			$this->validateConfig(config: $config);

			$this->createSftpConnection(config: $config)->pwd();

			return true;
		} catch (\Exception $e) {
			return $this->failedConnectionTest(cause: $e, message: '[SftpTransport] Connection test failed');
		}
	}//end testConnection()

	/**
	 * Get transport name.
	 *
	 * @return string The transport name.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
	 */
	public function getName(): string {
		return 'sftp';
	}//end getName()

	/**
	 * Validate SFTP configuration.
	 *
	 * @param array<string,mixed> $config The configuration to validate.
	 *
	 * @return void
	 *
	 * @throws RuntimeException If required configuration is missing.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
	 */
	private function validateConfig(array $config): void {
		$required = ['host', 'username'];
		foreach ($required as $key) {
			if (empty($config[$key]) === true) {
				throw new RuntimeException("Missing required SFTP config: {$key}");
			}
		}

		if (empty($config['password']) === true && empty($config['keyPath']) === true) {
			throw new RuntimeException('SFTP requires either password or keyPath for authentication');
		}
	}//end validateConfig()

	/**
	 * Create an SFTP connection.
	 *
	 * @param array<string,mixed> $config SFTP configuration.
	 *
	 * @return \phpseclib3\Net\SFTP The SFTP connection.
	 *
	 * @throws RuntimeException If connection fails.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
	 */
	private function createSftpConnection(array $config): SFTP {
		$sftp = new SFTP($config['host'], (int)($config['port'] ?? 22));

		$this->assertHostKey(sftp: $sftp, config: $config);

		if ($sftp->login($config['username'], $this->loginCredential(config: $config)) === false) {
			throw new RuntimeException('SFTP authentication failed');
		}

		return $sftp;
	}//end createSftpConnection()

	/**
	 * Refuse a server whose host key differs from `hostKeyFingerprint`, before any
	 * credential is sent. Without a configured fingerprint the server is not verified.
	 *
	 * @param SFTP $sftp The connection.
	 * @param array<string,mixed> $config SFTP configuration.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the host key differs.
	 */
	private function assertHostKey(SFTP $sftp, array $config): void {
		$pinned = (string)($config['hostKeyFingerprint'] ?? '');
		if ($pinned === '') {
			$this->logger->warning(message: '[SftpTransport] No hostKeyFingerprint configured, server identity not verified');
			return;
		}

		if (hash_equals($pinned, $this->fingerprint(hostKey: $sftp->getServerPublicHostKey())) === false) {
			throw new RuntimeException('SFTP host key does not match hostKeyFingerprint');
		}
	}//end assertHostKey()

	/**
	 * The OpenSSH SHA256 fingerprint of a host key, as `ssh-keygen -lf` prints it.
	 *
	 * @param mixed $hostKey The key as `<type> <base64>`, or false when unavailable.
	 *
	 * @return string The fingerprint.
	 */
	private function fingerprint(mixed $hostKey): string {
		$blob = base64_decode(explode(' ', (string)$hostKey)[1] ?? '');

		return 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
	}//end fingerprint()

	/**
	 * The loaded key when a key path is configured, else the password.
	 *
	 * @param array<string,mixed> $config SFTP configuration.
	 *
	 * @return mixed The key object or password string.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private function loginCredential(array $config): mixed {
		if (empty($config['keyPath']) === true) {
			return ($config['password'] ?? '');
		}

		return PublicKeyLoader::load(file_get_contents($config['keyPath']));
	}//end loginCredential()
}//end class
