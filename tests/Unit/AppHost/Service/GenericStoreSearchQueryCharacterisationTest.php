<?php

/**
 * Characterisation of the query GenericStoreService::search() sends.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Service;

use OCA\OpenRegister\AppHost\Service\GenericStoreService;
use OCA\OpenRegister\AppHost\Service\StoreDescriptor;
use OCA\OpenRegister\AppHost\Service\StoreRegistryAccess;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the registry query of a search: the cap, then the trimmed free-text
 * term and `kind`, each only when non-empty after trimming.
 */
final class GenericStoreSearchQueryCharacterisationTest extends TestCase {

	/**
	 * Search terms and the query they produce.
	 *
	 * @return array<string, array{0: ?string, 1: ?string, 2: array<string, mixed>}>
	 */
	public static function terms(): array {
		return [
			'no terms'           => [null, null, ['_limit' => 50]],
			'blank terms'        => ['   ', '', ['_limit' => 50]],
			'query trimmed'      => ['  jan ', null, ['_limit' => 50, '_search' => 'jan']],
			'kind trimmed'       => [null, ' pipeline ', ['_limit' => 50, 'kind' => 'pipeline']],
			'both, in key order' => ['x', 'y', ['_limit' => 50, '_search' => 'x', 'kind' => 'y']],
		];
	}//end terms()

	/**
	 * The query sent for each combination of terms.
	 *
	 * @param string|null          $query    The free-text term.
	 * @param string|null          $kind     The kind filter.
	 * @param array<string, mixed> $expected The registry query.
	 *
	 * @return void
	 */
	#[DataProvider('terms')]
	public function testSearchSendsTheTrimmedNonEmptyTermsAfterTheCap(?string $query, ?string $kind, array $expected): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'registry_url' => 'https://93.184.216.34/',
				default => $default,
			}
		);

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn('{"results":[]}');

		$sent = null;
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			static function (string $url, array $options) use ($response, &$sent): IResponse {
				$sent = $options['query'];
				return $response;
			}
		);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$service = new GenericStoreService(
			registry: new StoreRegistryAccess($clientService, $appConfig),
			logger: $this->createMock(LoggerInterface::class)
		);

		$result = $service->search(new StoreDescriptor(appId: 'openbuild', schema: 'application-template', defaultRegister: 'openbuild'), $query, $kind);

		$this->assertSame('ok', $result['outcome']);
		$this->assertSame($expected, $sent);
	}//end testSearchSendsTheTrimmedNonEmptyTermsAfterTheCap()
}//end class
