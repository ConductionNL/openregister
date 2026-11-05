<?php

/**
 * Each e-Depot transport's connection test refuses an incomplete config before connecting.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/edepot-transfer/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Edepot;

use GuzzleHttp\Client;
use OCA\OpenRegister\Service\Edepot\Transport\OpenConnectorTransport;
use OCA\OpenRegister\Service\Edepot\Transport\RestApiTransport;
use OCA\OpenRegister\Service\Edepot\Transport\SftpTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * testConnection() answers false, logs the config refusal and sends nothing.
 */
class TransportConnectionRefusalTest extends TestCase {

	/**
	 * Transport, incomplete config, and the logged refusal.
	 *
	 * @return array<string, array{string, array<string, mixed>, string, string}>
	 */
	public static function cases(): array {
		return [
			'rest api without endpoint' => ['rest', [], '[RestApiTransport] Connection test failed', 'Missing required REST API config: endpointUrl'],
			'rest api without credential' => [
				'rest',
				['endpointUrl' => 'https://e/x', 'authenticationType' => 'api_key'],
				'[RestApiTransport] Connection test failed',
				'Missing required REST API config: apiKey is empty while authenticationType is api_key',
			],
			'connector without source' => ['connector', [], '[OpenConnectorTransport] Connection test failed', 'Missing required OpenConnector config: sourceId'],
			'sftp without host' => ['sftp', ['username' => 'u', 'password' => 'p'], '[SftpTransport] Connection test failed', 'Missing required SFTP config: host'],
			'sftp without credentials' => ['sftp', ['host' => 'h', 'username' => 'u'], '[SftpTransport] Connection test failed', 'SFTP requires either password or keyPath for authentication'],
		];
	}//end cases()

	/**
	 * The refusal is logged and no request goes out.
	 *
	 * @param string               $kind    Which transport.
	 * @param array<string, mixed> $config  The incomplete config.
	 * @param string               $message The logged message.
	 * @param string               $error   The refusal it carries.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testIncompleteConfigIsRefusedBeforeConnecting(string $kind, array $config, string $message, string $error): void {
		$client = $this->createMock(Client::class);
		$client->expects($this->never())->method('get');
		$client->expects($this->never())->method('post');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($message, ['error' => $error]);

		$transport = match ($kind) {
			'rest' => new RestApiTransport($client, $logger),
			'connector' => new OpenConnectorTransport($client, $logger),
			'sftp' => new SftpTransport($logger),
		};

		$this->assertFalse($transport->testConnection($config));
	}//end testIncompleteConfigIsRefusedBeforeConnecting()
}//end class
