<?php

/**
 * OpenRegister SFTP Transport
 *
 * Transmits SIP packages to e-Depot systems via SFTP.
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
 * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-34
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
class SftpTransport implements TransportInterface
{
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
     * @param string              $sipFilePath The local path to the SIP ZIP archive.
     * @param array<string,mixed> $config      SFTP configuration: host, port, username, password/keyPath, remotePath.
     *
     * @return TransportResult The result of the transport.
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-21
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-34
     */
    public function send(string $sipFilePath, array $config): TransportResult
    {
        $this->logger->info(
            message: '[SftpTransport] Starting SFTP transfer',
            context: ['host' => ($config['host'] ?? 'unknown')]
        );

        try {
            $this->validateConfig(config: $config);

            if (file_exists($sipFilePath) === false) {
                throw new RuntimeException("SIP file not found: {$sipFilePath}");
            }

            $localSize  = filesize($sipFilePath);
            $remotePath = rtrim(($config['remotePath'] ?? '/'), '/').'/'.basename($sipFilePath);

            // Use phpseclib for SFTP if available.
            if (class_exists('\phpseclib3\Net\SFTP') === true) {
                $sftp   = $this->createSftpConnection(config: $config);
                $result = $sftp->put($remotePath, $sipFilePath, \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE);

                if ($result === false) {
                    throw new RuntimeException('SFTP upload failed: '.$sftp->getLastSFTPError());
                }

                // Verify remote file size.
                $remoteSize = $sftp->size($remotePath);
                if ($remoteSize !== $localSize) {
                    throw new RuntimeException(
                        "Remote file size mismatch: expected {$localSize}, got {$remoteSize}"
                    );
                }

                $this->logger->info(
                    message: '[SftpTransport] SFTP transfer successful',
                    context: [
                        'remotePath' => $remotePath,
                        'size'       => $localSize,
                    ]
                );

                return new TransportResult(
                    success: true,
                    transferReference: $remotePath
                );
            }//end if

            throw new RuntimeException(
                'phpseclib3 is not installed. Install phpseclib/phpseclib to enable SFTP transport.'
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[SftpTransport] SFTP transfer failed',
                context: ['error' => $e->getMessage()]
            );

            return new TransportResult(
                success: false,
                errorMessage: $e->getMessage()
            );
        }//end try
    }//end send()

    /**
     * Test SFTP connection.
     *
     * @param array<string,mixed> $config SFTP configuration.
     *
     * @return bool True if connection test succeeds.
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-21
     */
    public function testConnection(array $config): bool
    {
        try {
            $this->validateConfig(config: $config);

            if (class_exists('\phpseclib3\Net\SFTP') === false) {
                $this->logger->warning(
                    message: '[SftpTransport] phpseclib3 not available for connection test'
                );
                return false;
            }

            $sftp = $this->createSftpConnection(config: $config);
            $sftp->pwd();
            return true;
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[SftpTransport] Connection test failed',
                context: ['error' => $e->getMessage()]
            );
            return false;
        }
    }//end testConnection()

    /**
     * Get transport name.
     *
     * @return string The transport name.
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-21
     */
    public function getName(): string
    {
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
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-21
     */
    private function validateConfig(array $config): void
    {
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
     * @psalm-suppress UndefinedClass
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-21
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    private function createSftpConnection(array $config): SFTP
    {
        $port = (int) ($config['port'] ?? 22);
        $sftp = new SFTP($config['host'], $port);

        if (empty($config['keyPath']) === false) {
            $key    = PublicKeyLoader::load(
                file_get_contents($config['keyPath'])
            );
            $logged = $sftp->login($config['username'], $key);
        }

        if (empty($config['keyPath']) === true) {
            $logged = $sftp->login($config['username'], ($config['password'] ?? ''));
        }

        if ($logged === false) {
            throw new RuntimeException('SFTP authentication failed');
        }

        return $sftp;
    }//end createSftpConnection()
}//end class
