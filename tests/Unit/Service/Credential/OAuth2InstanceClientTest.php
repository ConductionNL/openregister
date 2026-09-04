<?php

/**
 * OAuth2InstanceClientTest — registering an application at somebody else's server,
 * and learning whose account came back.
 *
 * Two steps that are easy to write and never call, and that fail silently when
 * nobody does. A Mastodon connect with no application registered would build an
 * authorization URL naming an empty client id, and the person would meet a confused
 * error on their own server rather than here. A connect that never asks who the
 * account belongs to leaves a credential holding a live token and unable to say
 * whose, which is a connection nobody can audit.
 *
 * The registration assertions are therefore about how OFTEN it happens as much as
 * whether: registering again on every reconnect would leave a trail of live
 * applications on the person's own server, each holding a secret nothing will revoke.
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
 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-bluesky-is-its-own-client-and-mastodon-registers-per-instance
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\OAuth2ClientResolver;
use OCA\OpenRegister\Service\Credential\OAuth2ConnectService;
use OCA\OpenRegister\Service\Credential\OAuth2RefreshService;
use OCA\OpenRegister\Service\Credential\OAuth2TokenSet;
use OCA\OpenRegister\Service\Credential\ProviderCatalogue;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Credential\OAuth2ConnectService
 */
class OAuth2InstanceClientTest extends TestCase {
	/** @var array<int, string> Every URL the service POSTed to. */
	private array $posts = [];

	/** @var array<int, array<string, mixed>> Every credential minted. */
	private array $mints = [];

	/** @var array<int, array<string, mixed>> Every brokered call made on the new credential. */
	private array $brokered = [];

	/** @var array<int, OAuth2TokenSet> Every token set persisted. */
	private array $persisted = [];

	protected function setUp(): void {
		$this->posts = [];
		$this->mints = [];
		$this->brokered = [];
		$this->persisted = [];
	}

	public function testAMastodonConnectRegistersAnApplicationAtTheAccountsOwnServer(): void {
		$service = $this->makeService();

		$claims = $service->ensureInstanceClient(
			provider: $this->mastodon(),
			claims: $this->claims(),
			redirectUri: 'https://home.example/apps/openregister/oauth2/callback'
		);

		$this->assertSame('https://mastodon.example/api/v1/apps', $this->posts[0]);
		$this->assertSame('REGISTERED_CLIENT_ID', $claims['cl']);
		$this->assertSame('minted-uuid', $claims['cr']);
	}

	public function testTheIssuedClientSecretBecomesItsOwnBrokeredCredential(): void {
		$service = $this->makeService();

		$service->ensureInstanceClient(
			provider: $this->mastodon(),
			claims: $this->claims(),
			redirectUri: 'https://home.example/apps/openregister/oauth2/callback'
		);

		// The secret goes to the broker and nowhere else: the connection object gets
		// the client id, which is not a secret, and a reference to the rest.
		$this->assertCount(1, $this->mints);
		$this->assertSame('generic-oauth2', $this->mints[0]['provider']);
		$this->assertSame('YOUR_CLIENT_SECRET_HERE', $this->mints[0]['secret']);
	}

	public function testATenantThatBroughtItsOwnApplicationIsLeftAlone(): void {
		$service = $this->makeService();

		$claims = $service->ensureInstanceClient(
			provider: $this->mastodon(),
			claims: array_merge($this->claims(), ['cl' => 'TENANT_CLIENT_ID', 'cr' => 'tenant-ref']),
			redirectUri: 'https://home.example/apps/openregister/oauth2/callback'
		);

		$this->assertSame([], $this->posts);
		$this->assertSame('TENANT_CLIENT_ID', $claims['cl']);
	}

	public function testAReconnectReusesTheApplicationAlreadyPinnedToTheCredential(): void {
		$service = $this->makeService(
			existing: [
				'provider' => 'mastodon',
				'clientId' => 'EXISTING_CLIENT_ID',
				'clientCredentialRef' => 'existing-ref',
			]
		);

		$claims = $service->ensureInstanceClient(
			provider: $this->mastodon(),
			claims: array_merge($this->claims(), ['cid' => 'existing-uuid']),
			redirectUri: 'https://home.example/apps/openregister/oauth2/callback'
		);

		$this->assertSame([], $this->posts, 'a reconnect must not leave a second live application behind');
		$this->assertSame('EXISTING_CLIENT_ID', $claims['cl']);
		$this->assertSame('existing-ref', $claims['cr']);
	}

	public function testAProviderWithACentralRegistryRegistersNothing(): void {
		$service = $this->makeService();

		$claims = $service->ensureInstanceClient(
			provider: ['identifier' => 'x', 'kind' => 'oauth2-token-set', 'oauth2' => ['tokenEndpoint' => 'https://api.x.com/2/oauth2/token']],
			claims: $this->claims(),
			redirectUri: 'https://home.example/apps/openregister/oauth2/callback'
		);

		$this->assertSame([], $this->posts);
		$this->assertSame('', $claims['cl']);
	}

	public function testAServerThatIssuesNoClientIdIsRefusedRatherThanHalfConnected(): void {
		$service = $this->makeService(registration: ['error' => 'unauthorized']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('no client id');

		$service->ensureInstanceClient(
			provider: $this->mastodon(),
			claims: $this->claims(),
			redirectUri: 'https://home.example/apps/openregister/oauth2/callback'
		);
	}

	public function testTheConnectedAccountsHandleIsReadOnceAndRecorded(): void {
		$service = $this->makeService();

		$service->complete(
			claims: array_merge($this->claims(), ['cid' => '', 'cl' => 'CLIENT_ID_HERE']),
			code: 'AUTH_CODE_HERE',
			verifier: 'VERIFIER_HERE',
			redirectUri: 'https://home.example/apps/openregister/oauth2/callback'
		);

		$this->assertCount(1, $this->brokered);
		$this->assertSame('/api/v1/accounts/verify_credentials', $this->brokered[0]['path']);
		$this->assertSame('GET', $this->brokered[0]['method']);

		$recorded = end($this->persisted);
		$this->assertSame(
			['id' => '42', 'handle' => 'example@mastodon.example', 'displayName' => 'Example Reisbureau'],
			$recorded->getAccount()
		);
	}

	public function testAnIdentityCallThatFailsDoesNotUndoAWorkingConnection(): void {
		$service = $this->makeService(identityFails: true);

		$credentialId = $service->complete(
			claims: array_merge($this->claims(), ['cid' => '', 'cl' => 'CLIENT_ID_HERE']),
			code: 'AUTH_CODE_HERE',
			verifier: 'VERIFIER_HERE',
			redirectUri: 'https://home.example/apps/openregister/oauth2/callback'
		);

		$this->assertSame('minted-uuid', $credentialId, 'the connection stands even when its label could not be read');
	}

	public function testOpenregisterIsAlwaysGrantedSoItCanReadTheAccountItJustConnected(): void {
		$service = $this->makeService();

		$service->complete(
			claims: array_merge($this->claims(), ['cid' => '', 'cl' => 'CLIENT_ID_HERE', 'a' => ['pipelinq']]),
			code: 'AUTH_CODE_HERE',
			verifier: 'VERIFIER_HERE',
			redirectUri: 'https://home.example/apps/openregister/oauth2/callback'
		);

		$this->assertSame(['pipelinq', 'openregister'], $this->mints[0]['allowedApps']);
	}

	/**
	 * The claims a Mastodon start carries.
	 *
	 * @return array<string, mixed> The claims.
	 */
	private function claims(): array {
		return [
			'u' => 'alice',
			'p' => 'mastodon',
			's' => 'personal',
			'h' => 'https://mastodon.example',
			'sc' => ['read:accounts', 'write:statuses'],
			'a' => ['pipelinq'],
			'cl' => '',
			'cr' => '',
			'cid' => '',
			'nm' => 'Mastodon company account',
		];
	}

	/**
	 * The Mastodon catalogue entry, cut to what these tests exercise.
	 *
	 * @return array<string, mixed> The provider entry.
	 */
	private function mastodon(): array {
		return [
			'identifier' => 'mastodon',
			'kind' => 'oauth2-token-set',
			'baseUrlFrom' => 'instanceBaseUrl',
			'oauth2' => [
				'authorizationEndpoint' => '/oauth/authorize',
				'tokenEndpoint' => '/oauth/token',
				'registrationEndpoint' => '/api/v1/apps',
				'endpointsRelativeToInstance' => true,
				'clientAuth' => 'client_secret_post',
				'scopeSeparator' => ' ',
				'pkce' => 'S256',
				'defaultScopes' => ['read:accounts'],
			],
			'identity' => [
				'method' => 'GET',
				'path' => '/api/v1/accounts/verify_credentials',
				'idField' => 'id',
				'handleField' => 'acct',
				'displayNameField' => 'display_name',
			],
		];
	}

	/**
	 * Build the service over a scripted server, broker and object store.
	 *
	 * @param array<string, mixed>|null $existing The credential a reconnect targets.
	 * @param array<string, mixed>|null $registration The app-registration response.
	 * @param boolean $identityFails Whether the identity call throws.
	 *
	 * @return OAuth2ConnectService The service under test.
	 */
	private function makeService(
		?array $existing = null,
		?array $registration = null,
		bool $identityFails = false,
	): OAuth2ConnectService {
		$registration ??= ['client_id' => 'REGISTERED_CLIENT_ID', 'client_secret' => 'YOUR_CLIENT_SECRET_HERE'];

		$catalogue = $this->createMock(ProviderCatalogue::class);
		$catalogue->method('get')->willReturn($this->mastodon());

		$objectService = $this->createMock(ObjectService::class);
		if ($existing === null) {
			$objectService->method('find')->willReturn(null);
		} else {
			$entity = new ObjectEntity();
			$entity->setUuid('existing-uuid');
			$entity->setObject($existing);
			$objectService->method('find')->willReturn($entity);
		}

		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('mint')->willReturnCallback(
			function (
				string $name,
				string $provider,
				string $owner,
				array $allowedApps = [],
				?string $secret = null,
			): ObjectEntity {
				$this->mints[] = [
					'provider' => $provider,
					'owner' => $owner,
					'allowedApps' => $allowedApps,
					'secret' => $secret,
				];
				$minted = new ObjectEntity();
				$minted->setUuid('minted-uuid');
				return $minted;
			}
		);
		$broker->method('request')->willReturnCallback(
			function (string $credentialId, string $appId, string $method, string $path) use ($identityFails): array {
				$this->brokered[] = ['credentialId' => $credentialId, 'appId' => $appId, 'method' => $method, 'path' => $path];
				if ($identityFails === true) {
					throw new RuntimeException('the provider is having a day');
				}

				return [
					'status' => 200,
					'headers' => [],
					'body' => (string)json_encode(
						['id' => '42', 'acct' => 'example@mastodon.example', 'display_name' => 'Example Reisbureau']
					),
				];
			}
		);

		$refresh = $this->createMock(OAuth2RefreshService::class);
		$refresh->method('persist')->willReturnCallback(
			function (string $credentialId, string $scope, OAuth2TokenSet $set): void {
				$this->persisted[] = $set;
			}
		);

		$clients = $this->createMock(OAuth2ClientResolver::class);
		$clients->method('resolve')->willReturn(['clientId' => 'CLIENT_ID_HERE', 'clientSecret' => 'YOUR_CLIENT_SECRET_HERE']);

		// The body is chosen by the URL rather than by call order: a registration and a
		// token exchange are two different endpoints, and a test that scripted them by
		// position would pass or fail on the order the service happened to call them
		// in rather than on which endpoint it called.
		$token = (string)json_encode(
			['access_token' => 'ACCESS_TOKEN_HERE', 'refresh_token' => 'REFRESH_TOKEN_HERE', 'expires_in' => 3600]
		);

		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			function (string $url) use ($registration, $token): IResponse {
				$this->posts[] = $url;

				$response = $this->createMock(IResponse::class);
				$response->method('getBody')->willReturn(
					str_contains($url, '/api/v1/apps') === true ? (string)json_encode($registration) : $token
				);

				return $response;
			}
		);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		return new OAuth2ConnectService(
			catalogue: $catalogue,
			broker: $broker,
			clients: $clients,
			refresh: $refresh,
			objectService: $objectService,
			clientService: $clientService,
			logger: $this->createMock(LoggerInterface::class)
		);
	}
}
