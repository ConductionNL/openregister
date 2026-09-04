<?php

/**
 * CredentialBrokerOAuth2Test — the broker's kind-aware injection and host resolution.
 *
 * The regression this file exists for is the one that would be silent: an existing
 * credential whose catalogue entry declares no `kind` must take the OLD path, byte
 * for byte, and must never touch the refresh service. Everything else here is a
 * fail-closed property: the object's mirrored `kind` never decides the injection
 * path, an entry with no `oauth2` block cannot serve one, a per-credential host is
 * resolved from the credential and refused when unsafe, and every guard still runs
 * BEFORE anything reaches a token endpoint.
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
 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-broker/spec.md#requirement-injection-is-selected-by-kind
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Credential\OAuth2RefreshService;
use OCA\OpenRegister\Service\Credential\ProviderCatalogue;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Credential\CredentialBrokerService
 */
class CredentialBrokerOAuth2Test extends TestCase {
	/** @var array<string, mixed>|null The options the outbound client was called with. */
	private ?array $capturedOptions = null;

	/** @var string|null The URL the outbound client was called with. */
	private ?string $capturedUrl = null;

	/** @var integer How many times the refresh service was asked for an access token. */
	private int $refreshCalls = 0;

	protected function setUp(): void {
		$this->capturedOptions = null;
		$this->capturedUrl = null;
		$this->refreshCalls = 0;
	}

	public function testAnEntryWithNoKindTakesTheUnchangedSecretPath(): void {
		$broker = $this->makeBroker(
			credData: ['provider' => 'github', 'owner' => 'alice', 'allowedApps' => ['hermiq']],
			provider: [
				'identifier' => 'github',
				'baseUrl' => 'https://api.github.com',
				'authScheme' => ['header' => 'Authorization', 'template' => 'token {secret}'],
				'allowRules' => [['method' => 'GET', 'pathPattern' => '/user']],
			],
			secret: 'CLASSIC_SECRET_VALUE'
		);

		$broker->request(credentialId: 'cred-1', appId: 'hermiq', method: 'GET', path: '/user');

		$this->assertSame('token CLASSIC_SECRET_VALUE', $this->capturedOptions['headers']['Authorization']);
		$this->assertSame(0, $this->refreshCalls, 'a classic credential must never reach the refresh service');
	}

	public function testAnOAuth2EntryInjectsTheRefreshedAccessToken(): void {
		$broker = $this->makeBroker(
			credData: [
				'provider' => 'x',
				'owner' => 'alice',
				'allowedApps' => ['pipelinq'],
				'kind' => 'oauth2-token-set',
				'status' => 'active',
			],
			provider: $this->oauth2Provider(),
			secret: null
		);

		$broker->request(credentialId: 'cred-1', appId: 'pipelinq', method: 'GET', path: '/2/users/me');

		$this->assertSame('Bearer LIVE_ACCESS_TOKEN', $this->capturedOptions['headers']['Authorization']);
		$this->assertSame(1, $this->refreshCalls);
	}

	public function testTheCatalogueWinsOverAStaleKindOnTheObject(): void {
		$broker = $this->makeBroker(
			// The object claims the classic kind; the catalogue says otherwise. If the
			// object could decide, its owner could change what their credential does
			// with an ordinary object write.
			credData: [
				'provider' => 'x',
				'owner' => 'alice',
				'allowedApps' => ['pipelinq'],
				'kind' => 'secret',
				'status' => 'active',
			],
			provider: $this->oauth2Provider(),
			secret: 'SHOULD_NOT_BE_USED'
		);

		$broker->request(credentialId: 'cred-1', appId: 'pipelinq', method: 'GET', path: '/2/users/me');

		$this->assertSame('Bearer LIVE_ACCESS_TOKEN', $this->capturedOptions['headers']['Authorization']);
	}

	public function testAnOAuth2EntryWithNoOauth2BlockIsDenied(): void {
		$provider = $this->oauth2Provider();
		unset($provider['oauth2']);

		$broker = $this->makeBroker(
			credData: ['provider' => 'x', 'owner' => 'alice', 'allowedApps' => ['pipelinq'], 'kind' => 'oauth2-token-set'],
			provider: $provider,
			secret: null
		);

		$this->expectException(CredentialAccessDeniedException::class);
		$broker->request(credentialId: 'cred-1', appId: 'pipelinq', method: 'GET', path: '/2/users/me');
	}

	public function testWithoutTheRefreshServiceAnOAuth2CredentialFailsClosed(): void {
		$broker = $this->makeBroker(
			credData: ['provider' => 'x', 'owner' => 'alice', 'allowedApps' => ['pipelinq'], 'kind' => 'oauth2-token-set'],
			provider: $this->oauth2Provider(),
			secret: 'A_TOKEN_SET_DOCUMENT',
			withRefreshService: false
		);

		// Falling through to the classic branch would put a whole JSON document in an
		// Authorization header and read the provider's refusal as a credential fault.
		$this->expectException(CredentialAccessDeniedException::class);
		$broker->request(credentialId: 'cred-1', appId: 'pipelinq', method: 'GET', path: '/2/users/me');
	}

	public function testAPerAccountHostIsResolvedFromTheCredential(): void {
		$broker = $this->makeBroker(
			credData: [
				'provider' => 'mastodon',
				'owner' => 'alice',
				'allowedApps' => ['pipelinq'],
				'kind' => 'oauth2-token-set',
				'status' => 'active',
				'instanceBaseUrl' => 'https://mastodon.example',
			],
			provider: [
				'identifier' => 'mastodon',
				'kind' => 'oauth2-token-set',
				'baseUrlFrom' => 'instanceBaseUrl',
				'oauth2' => ['tokenEndpoint' => '/oauth/token'],
				'authScheme' => ['header' => 'Authorization', 'template' => 'Bearer {secret}'],
				'allowRules' => [['method' => 'POST', 'pathPattern' => '/api/v1/statuses']],
			],
			secret: null
		);

		$broker->request(credentialId: 'cred-1', appId: 'pipelinq', method: 'POST', path: '/api/v1/statuses');

		$this->assertSame('https://mastodon.example/api/v1/statuses', $this->capturedUrl);
	}

	public function testAPerAccountCredentialWithAnUnsafeHostIsDenied(): void {
		$broker = $this->makeBroker(
			credData: [
				'provider' => 'mastodon',
				'owner' => 'alice',
				'allowedApps' => ['pipelinq'],
				'kind' => 'oauth2-token-set',
				'instanceBaseUrl' => 'http://127.0.0.1:8080',
			],
			provider: [
				'identifier' => 'mastodon',
				'kind' => 'oauth2-token-set',
				'baseUrlFrom' => 'instanceBaseUrl',
				'oauth2' => ['tokenEndpoint' => '/oauth/token'],
				'authScheme' => ['header' => 'Authorization', 'template' => 'Bearer {secret}'],
				'allowRules' => [['method' => 'POST', 'pathPattern' => '/api/v1/statuses']],
			],
			secret: null
		);

		$this->expectException(CredentialAccessDeniedException::class);
		$broker->request(credentialId: 'cred-1', appId: 'pipelinq', method: 'POST', path: '/api/v1/statuses');
	}

	public function testAGuardRefusalHappensBeforeAnyTokenEndpointIsContacted(): void {
		$broker = $this->makeBroker(
			// `pipelinq` is not on allowedApps, so Guard 2 must refuse.
			credData: [
				'provider' => 'x',
				'owner' => 'alice',
				'allowedApps' => ['hermiq'],
				'kind' => 'oauth2-token-set',
				'status' => 'active',
			],
			provider: $this->oauth2Provider(),
			secret: null
		);

		try {
			$broker->request(credentialId: 'cred-1', appId: 'pipelinq', method: 'GET', path: '/2/users/me');
			$this->fail('an app that is not allow-listed must be refused');
		} catch (CredentialAccessDeniedException) {
			$this->assertSame(0, $this->refreshCalls, 'a denied caller must never cause a token exchange');
		}
	}

	public function testAnInjectOnlyProviderIsStillRefusedByTheProxy(): void {
		$broker = $this->makeBroker(
			credData: ['provider' => 'generic-oauth2', 'owner' => 'alice', 'allowedApps' => ['pipelinq'], 'kind' => 'oauth2-token-set'],
			provider: [
				'identifier' => 'generic-oauth2',
				'kind' => 'oauth2-token-set',
				'inject_only' => true,
				'authScheme' => ['header' => 'Authorization', 'template' => 'Bearer {secret}'],
			],
			secret: null
		);

		$this->expectException(CredentialAccessDeniedException::class);
		$broker->request(credentialId: 'cred-1', appId: 'pipelinq', method: 'GET', path: '/anything');
	}

	/**
	 * A host-locked OAuth2 catalogue entry.
	 *
	 * @return array<string, mixed> The entry.
	 */
	private function oauth2Provider(): array {
		return [
			'identifier' => 'x',
			'kind' => 'oauth2-token-set',
			'baseUrl' => 'https://api.x.com',
			'oauth2' => ['tokenEndpoint' => 'https://api.x.com/2/oauth2/token', 'refreshGrant' => 'refresh_token'],
			'authScheme' => ['header' => 'Authorization', 'template' => 'Bearer {secret}'],
			'allowRules' => [
				['method' => 'GET', 'pathPattern' => '/2/users/me'],
				['method' => 'POST', 'pathPattern' => '/2/tweets'],
			],
		];
	}

	/**
	 * Wire a broker with fully mocked collaborators.
	 *
	 * @param array<string, mixed> $credData The credential object's property bag.
	 * @param array<string, mixed>|null $provider The catalogue entry.
	 * @param string|null $secret What the custody leaf returns.
	 * @param boolean $withRefreshService Whether the refresh collaborator is present.
	 *
	 * @return CredentialBrokerService The broker under test.
	 */
	private function makeBroker(
		array $credData,
		?array $provider,
		?string $secret,
		bool $withRefreshService = true,
	): CredentialBrokerService {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$entity = new ObjectEntity();
		$entity->setOwner((string)($credData['owner'] ?? 'alice'));
		$entity->setObject($credData);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn($entity);

		$catalogue = $this->createMock(ProviderCatalogue::class);
		$catalogue->method('get')->willReturn($provider);

		$store = $this->createMock(CredentialStore::class);
		$store->method('get')->willReturn($secret);

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getHeaders')->willReturn([]);
		$response->method('getBody')->willReturn('{}');

		$client = $this->createMock(IClient::class);
		$client->method('request')->willReturnCallback(
			function (string $method, string $uri, array $options) use ($response): IResponse {
				$this->capturedUrl = $uri;
				$this->capturedOptions = $options;
				return $response;
			}
		);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$refresh = null;
		if ($withRefreshService === true) {
			$refresh = $this->createMock(OAuth2RefreshService::class);
			$refresh->method('accessTokenFor')->willReturnCallback(
				function (): string {
					$this->refreshCalls++;
					return 'LIVE_ACCESS_TOKEN';
				}
			);
		}

		return new CredentialBrokerService(
			$objectService,
			$store,
			$catalogue,
			$session,
			$clientService,
			$this->createMock(LoggerInterface::class),
			$this->createMock(OrganisationService::class),
			null,
			null,
			null,
			$refresh
		);
	}
}
