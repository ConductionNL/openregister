<?php

declare(strict_types=1);

/**
 * Golden of how GenericStoreService reads a remote registry answer: which
 * HTTP statuses count as an answer and which body shapes yield results.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Service\GenericStoreService;
use OCA\OpenRegister\AppHost\Service\StoreDescriptor;
use OCA\OpenRegister\AppHost\Service\StoreRegistryAccess;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Pins the outcome and card slugs of a search for every status and body shape.
 */
class GenericStoreServiceFetchGoldenTest extends TestCase {

	/**
	 * Status, body → outcome and card slugs, and whether a warning was logged.
	 *
	 * @return array<string, array{0: int, 1: string, 2: string}>
	 */
	public static function answers(): array {
		$card = ['slug' => 'crm', 'title' => 'CRM'];
		return [
			'200 results list' => [200, json_encode(['results' => [$card]]), 'ok crm'],
			'299 still an answer' => [299, json_encode(['results' => [$card]]), 'ok crm'],
			'300 is not an answer' => [300, json_encode(['results' => [$card]]), 'store_unreachable - warned'],
			'199 is not an answer' => [199, json_encode(['results' => [$card]]), 'store_unreachable - warned'],
			'status 500' => [500, '{}', 'store_unreachable - warned'],
			'a bare list' => [200, json_encode([$card]), 'ok crm'],
			'an object without results' => [200, json_encode(['crm' => $card]), 'ok -'],
			'results not a list' => [200, json_encode(['results' => 'x', 'other' => 1]), 'ok -'],
			'not json' => [200, '{nope', 'store_invalid_response - warned'],
			'json scalar' => [200, '"text"', 'store_invalid_response - warned'],
		];
	}//end answers()

	/**
	 * @dataProvider answers
	 *
	 * @param int    $status   The registry's HTTP status.
	 * @param string $body     The registry's body.
	 * @param string $expected The outcome, card slugs and whether a warning was logged.
	 *
	 * @return void
	 */
	public function testTheRegistryAnswerIsRead(int $status, string $body, string $expected): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($response);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'registry_url' => 'https://93.184.216.34/',
				'registry_register' => 'openbuild',
				default => $default,
			}
		);

		$warnings = [];
		$logger   = new class($warnings) extends AbstractLogger {
			/**
			 * @param array<int, string> $warnings The shared log.
			 */
			public function __construct(private array &$warnings) {
			}

			public function log($level, string|Stringable $message, array $context = []): void {
				$this->warnings[] = $level . ' ' . $message;
			}
		};

		$service = new GenericStoreService(registry: new StoreRegistryAccess($clients, $config), logger: $logger);
		$result  = $service->search(descriptor: new StoreDescriptor(appId: 'openbuild', schema: 'application-template', defaultRegister: 'openbuild'));

		$slugs   = array_map(static fn (array $card): string => (string)$card['slug'], $result['cards']);
		$outcome = $result['outcome'] . ' ' . ($slugs === [] ? '-' : implode(',', $slugs)) . ($warnings === [] ? '' : ' warned');
		$this->assertSame($expected, $outcome);
	}//end testTheRegistryAnswerIsRead()
}//end class
