<?php

/**
 * GithubShopRulesTest — pins the `github` provider's six "shop" allow-rules
 * (search, identity, repository create, branch-ref update and discovery
 * topics) through the broker's real rule matcher (github-provider-shop-rules).
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
 * @spec openspec/changes/github-provider-shop-rules/specs/credential-broker/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\CredentialOutbound;
use OCA\OpenRegister\Service\Credential\CredentialPrincipals;
use OCA\OpenRegister\Service\Credential\CredentialSources;
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

/**
 * @covers \OCA\OpenRegister\Service\Credential\CredentialBrokerService
 * @covers \OCA\OpenRegister\Service\Credential\ProviderCatalogue
 */
class GithubShopRulesTest extends TestCase {
	private const PLACEHOLDER_TOKEN = 'YOUR_API_KEY_HERE';

	private const CREDENTIAL_UUID = '00000000-0000-0000-0000-000000000001';

	/**
	 * The six rules this change adds, as `METHOD pattern`.
	 */
	private const SHOP_RULES = [
		'GET /search/repositories',
		'GET /user',
		'POST /user/repos',
		'POST /orgs/*/repos',
		'PATCH /repos/*/git/refs/*',
		'PUT /repos/*/topics',
	];

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
	 * Each shop rule is in the catalogue exactly once, the auth scheme is
	 * unchanged and no GET rule for contents or git data was added beside
	 * `GET /repos/*`, which already spans them.
	 */
	public function testTheCatalogueCarriesEachShopRuleOnce(): void {
		$github = $this->catalogue->get('github');
		$this->assertIsArray($github);
		$this->assertSame('https://api.github.com', $github['baseUrl']);
		$this->assertSame(['header' => 'Authorization', 'template' => 'token {secret}'], $github['authScheme']);

		$rules = array_map(
			static fn (array $rule): string => $rule['method'] . ' ' . $rule['pathPattern'],
			$github['allowRules']
		);
		foreach (self::SHOP_RULES as $rule) {
			$this->assertSame(1, count(array_keys($rules, $rule, true)), $rule);
		}

		$extraReads = array_filter(
			$rules,
			static fn (string $rule): bool => preg_match('#^GET /repos/\*/(contents|git)#', $rule) === 1
		);
		$this->assertSame([], array_values($extraReads), 'GET /repos/* already covers contents and git reads');
	}

	/**
	 * Calls the shop makes, each allowed and host-locked to api.github.com.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function allowedCalls(): array {
		return [
			'repository search with a query' => ['GET', '/search/repositories?q=topic%3Anextcloud-app&per_page=50'],
			'who am I' => ['GET', '/user'],
			'my repositories (existing rule, distinct from /user)' => ['GET', '/user/repos'],
			'create a personal repository' => ['POST', '/user/repos'],
			'create an organisation repository' => ['POST', '/orgs/conduction/repos'],
			'read a file (existing GET /repos/*)' => ['GET', '/repos/owner/repo/contents/x'],
			'read a tree (existing GET /repos/*)' => ['GET', '/repos/owner/repo/git/trees/main'],
			'move a branch' => ['PATCH', '/repos/owner/repo/git/refs/heads/main'],
			'set discovery topics' => ['PUT', '/repos/owner/repo/topics'],
		];
	}

	/**
	 * @dataProvider allowedCalls
	 */
	public function testAShopCallIsAllowedAndHostLocked(string $method, string $path): void {
		$captured = [];

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getHeaders')->willReturn([]);
		$response->method('getBody')->willReturn('{}');

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
			method: $method,
			path: $path
		);

		$this->assertSame(200, $result['status']);
		$this->assertSame($method, $captured['method']);
		$this->assertSame('https://api.github.com' . $path, $captured['url']);
		$this->assertSame('token ' . self::PLACEHOLDER_TOKEN, $captured['options']['headers']['Authorization']);
	}

	/**
	 * What the shop rules do not open: identity stays read-only, repository
	 * settings and deletes, workflows and webhooks stay refused.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function refusedCalls(): array {
		return [
			'identity written as POST /user' => ['POST', '/user'],
			'identity sub-resource not granted' => ['GET', '/user/emails'],
			'repository settings' => ['PATCH', '/repos/owner/repo'],
			'repository replaced' => ['PUT', '/repos/owner/repo'],
			'repository deleted' => ['DELETE', '/repos/owner/repo'],
			'branch deleted' => ['DELETE', '/repos/owner/repo/git/refs/heads/main'],
			'topics read with the wrong verb' => ['POST', '/repos/owner/repo/topics'],
			'organisation repository listed by POST elsewhere' => ['POST', '/orgs/conduction/members'],
			'workflow dispatched' => ['POST', '/repos/owner/repo/actions/workflows/ci.yml/dispatches'],
			'webhook created' => ['POST', '/repos/owner/repo/hooks'],
			'user search not granted' => ['GET', '/search/users?q=x'],
		];
	}

	/**
	 * @dataProvider refusedCalls
	 */
	public function testACallOutsideTheRulesIsRefusedBeforeAnyOutboundCall(string $method, string $path): void {
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
				'name' => 'github-shop',
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
			sources: new CredentialSources(
				objectService: $objectService,
				credentialStore: $store,
				catalogue: $this->catalogue
			),
			principals: new CredentialPrincipals(
				userSession: $userSession,
				organisationService: $this->createMock(OrganisationService::class)
			),
			outbound: new CredentialOutbound(
				clientService: $clientService,
				logger: $this->createMock(LoggerInterface::class)
			)
		);
	}
}
