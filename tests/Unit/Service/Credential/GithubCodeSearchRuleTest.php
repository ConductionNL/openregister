<?php

/**
 * GithubCodeSearchRuleTest — pins the `github` provider's `GET /search/code`
 * allow-rule through the broker's real rule matcher (github-provider-code-search).
 *
 * Every case goes through CredentialBrokerService::request() with the real
 * shipped catalogue, so the assertion is on the matcher and the file together,
 * not on a hand-built rule list. Allowed calls must reach the HTTP client with a
 * URL host-locked to api.github.com; denied calls must never reach it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/github-provider-code-search/specs/credential-broker/spec.md#requirement-github-code-search-allow-rule
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Credential\ProviderCatalogue;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class GithubCodeSearchRuleTest extends TestCase {
	private const PLACEHOLDER_TOKEN = 'YOUR_TOKEN_HERE';

	private const CREDENTIAL_UUID = '00000000-0000-0000-0000-000000000001';

	private ProviderCatalogue $catalogue;

	protected function setUp(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')
			->with('openregister')
			->willReturn(dirname(__DIR__, 4));

		$this->catalogue = new ProviderCatalogue(
			$appManager,
			$this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * The catalogue carries exactly one /search/code rule, and it is GET.
	 */
	public function testCatalogueHasOneReadOnlyCodeSearchRule(): void {
		$github = $this->catalogue->get('github');
		$this->assertIsArray($github);

		$codeSearch = array_values(
			array_filter(
				$github['allowRules'],
				static fn (array $rule): bool => str_starts_with((string)($rule['pathPattern'] ?? ''), '/search/code')
			)
		);
		$this->assertCount(1, $codeSearch);
		$this->assertSame('GET', $codeSearch[0]['method']);
		$this->assertSame('/search/code', $codeSearch[0]['pathPattern']);
	}

	/**
	 * The harvest's two calls are allowed and host-locked to api.github.com.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function allowedHarvestCalls(): array {
		return [
			'code search with query and paging' => [
				'/search/code?q=filename%3Apubliccode.yml+path%3A%2F&per_page=100&page=3',
				'https://api.github.com/search/code?q=filename%3Apubliccode.yml+path%3A%2F&per_page=100&page=3',
			],
			'code search without query' => [
				'/search/code',
				'https://api.github.com/search/code',
			],
			'per-file contents read (existing GET /repos/* rule)' => [
				'/repos/ConductionNL/opencatalogi/contents/publiccode.yml?ref=main',
				'https://api.github.com/repos/ConductionNL/opencatalogi/contents/publiccode.yml?ref=main',
			],
		];
	}

	/**
	 * @dataProvider allowedHarvestCalls
	 */
	public function testHarvestCallIsAllowedAndHostLocked(string $path, string $expectedUrl): void {
		$captured = [];

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getHeaders')->willReturn([]);
		$response->method('getBody')->willReturn('{"total_count":0,"items":[]}');

		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('request')
			->willReturnCallback(
				function (string $method, string $url, array $options) use (&$captured, $response) {
					$captured = ['method' => $method, 'url' => $url, 'options' => $options];
					return $response;
				}
			);

		$result = $this->makeBroker(client: $client)->request(
			credentialId: self::CREDENTIAL_UUID,
			appId: 'openconnector',
			method: 'GET',
			path: $path
		);

		$this->assertSame(200, $result['status']);
		$this->assertSame('GET', $captured['method']);
		$this->assertSame($expectedUrl, $captured['url']);
		$this->assertSame('token ' . self::PLACEHOLDER_TOKEN, $captured['options']['headers']['Authorization']);
	}

	/**
	 * Anything that is not a GET on exactly /search/code stays refused.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function refusedCalls(): array {
		return [
			'POST on code search'          => ['POST', '/search/code'],
			'PUT on code search'           => ['PUT', '/search/code'],
			'DELETE on code search'        => ['DELETE', '/search/code'],
			'commit search not granted'    => ['GET', '/search/commits?q=x'],
			'user search not granted'      => ['GET', '/search/users?q=x'],
			'sub-path of code search'      => ['GET', '/search/code/extra'],
			'longer name, same prefix'     => ['GET', '/search/codes'],
		];
	}

	/**
	 * @dataProvider refusedCalls
	 */
	public function testOtherSearchCallsAreRefusedBeforeAnyOutboundCall(string $method, string $path): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('request');

		$this->expectException(CredentialAccessDeniedException::class);
		$this->makeBroker(client: $client)->request(
			credentialId: self::CREDENTIAL_UUID,
			appId: 'openconnector',
			method: $method,
			path: $path
		);
	}

	/**
	 * Build a broker wired to a github credential owned by the session user.
	 *
	 * @param IClient $client The (mock) HTTP client observing or refusing the outbound call.
	 */
	private function makeBroker(IClient $client): CredentialBrokerService {
		$credential = new ObjectEntity();
		$credential->setUuid(self::CREDENTIAL_UUID);
		$credential->setOwner('alice');
		$credential->setObject(
			[
				'name' => 'github-publiccode',
				'provider' => 'github',
				'owner' => 'alice',
				'allowedApps' => ['openconnector'],
				'createdAt' => '2026-10-01T00:00:00+00:00',
			]
		);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn($credential);

		$store = $this->createMock(CredentialStore::class);
		$store->method('get')->with(self::CREDENTIAL_UUID)->willReturn(self::PLACEHOLDER_TOKEN);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		return new CredentialBrokerService(
			$objectService,
			$store,
			$this->catalogue,
			$userSession,
			$clientService,
			$this->createMock(LoggerInterface::class),
			$this->createMock(OrganisationService::class)
		);
	}
}
