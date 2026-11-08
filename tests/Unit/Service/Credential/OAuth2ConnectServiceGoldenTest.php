<?php

/**
 * OAuth2ConnectServiceGoldenTest — pins the connect flow before it is restructured.
 *
 * Every outcome of the connect seam a refactor could move: the authorization URL a
 * person is sent to, the exact token request, the credential minted or repaired, the
 * re-authorisation refusals, and the best-effort upstream revoke. Built from the leaf
 * collaborators only, so the class layout behind the seam may change freely.
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
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\OAuth2AccountIdentity;
use OCA\OpenRegister\Service\Credential\OAuth2ClientResolver;
use OCA\OpenRegister\Service\Credential\OAuth2CodeExchange;
use OCA\OpenRegister\Service\Credential\OAuth2ConnectService;
use OCA\OpenRegister\Service\Credential\OAuth2ConnectionWriter;
use OCA\OpenRegister\Service\Credential\OAuth2InstanceClient;
use OCA\OpenRegister\Service\Credential\OAuth2RefreshService;
use OCA\OpenRegister\Service\Credential\OAuth2TokenSet;
use OCA\OpenRegister\Service\Credential\OAuth2UpstreamRevoker;
use OCA\OpenRegister\Service\Credential\ProviderCatalogue;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Service\Credential\OAuth2ConnectClients;
use OCA\OpenRegister\Service\Credential\OAuth2ConnectSteps;

/**
 * Golden outcomes of the OAuth2 connect seam.
 */
class OAuth2ConnectServiceGoldenTest extends TestCase {
	/** @var array<int, array{0: string, 1: array<string, mixed>}> Every HTTP POST made. */
	private array $posts = [];

	/** @var array<int, array<string, mixed>> Every broker mint call's arguments. */
	private array $mints = [];

	/** @var array<int, array<string, mixed>> Every refresh persist call's arguments. */
	private array $persists = [];

	/** @var array<int, array<string, mixed>> Every identity record call's arguments. */
	private array $records = [];

	/** @var array<int, array<string, mixed>> Every client resolve call's arguments. */
	private array $resolves = [];

	/** @var string|\Throwable The token endpoint's body, or what it throws. */
	private string|\Throwable $tokenBody = '{"access_token":"AT","refresh_token":"RT","expires_in":3600,"scope":"read write"}';

	/** @var array<string, mixed>|null The credential the object store holds. */
	private ?array $stored = null;

	/** @var string|null The client secret the resolver returns. */
	private ?string $clientSecret = 'SECRET';

	public function testAuthorizationUrlForAPerInstanceProviderWithPkce(): void {
		$url = $this->service(provider: $this->mastodon())->authorizationUrl(
			provider: $this->mastodon(),
			claims: ['p' => 'mastodon', 'u' => 'alice', 'h' => 'https://Mastodon.Example/', 'sc' => ['read', 'write'], 'cl' => 'CID', 'cr' => 'CREF'],
			redirectUri: 'https://home.example/cb',
			state: 'STATE',
			challenge: 'CHALLENGE'
		);

		$this->assertSame(
			'https://mastodon.example/oauth/authorize?response_type=code&client_id=RESOLVED&redirect_uri=https%3A%2F%2Fhome.example%2Fcb'
			. '&state=STATE&scope=read%2Cwrite&code_challenge=CHALLENGE&code_challenge_method=S256&force_login=true',
			$url
		);
		$this->assertSame(
			[['credential' => ['clientId' => 'CID', 'clientCredentialRef' => 'CREF'], 'provider' => 'mastodon', 'actingUserId' => 'alice']],
			$this->resolves
		);
	}

	public function testAuthorizationUrlForACentralProviderUsesDefaultScopesAndNoPkce(): void {
		$url = $this->service(provider: $this->github())->authorizationUrl(
			provider: $this->github(),
			claims: ['p' => 'github', 'u' => 'bob'],
			redirectUri: 'https://home.example/cb',
			state: 'S',
			challenge: 'C'
		);

		$this->assertSame(
			'https://github.com/login/oauth/authorize?response_type=code&client_id=RESOLVED&redirect_uri=https%3A%2F%2Fhome.example%2Fcb&state=S&scope=repo+user',
			$url
		);
	}

	public function testAuthorizationUrlWithoutAnEndpointIsRefused(): void {
		$provider = $this->github();
		unset($provider['oauth2']['authorizationEndpoint']);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('the catalogue names no authorizationEndpoint for this provider');

		$this->service(provider: $provider)->authorizationUrl(provider: $provider, claims: [], redirectUri: 'r', state: 's', challenge: 'c');
	}

	public function testOauth2ProviderRefusesUnknownAndBlocklessEntries(): void {
		$this->assertSame($this->github(), $this->service(provider: $this->github())->oauth2Provider(providerId: 'github'));

		try {
			$this->service(provider: null)->oauth2Provider(providerId: 'nope');
			$this->fail('unknown provider accepted');
		} catch (InvalidArgumentException $refused) {
			$this->assertSame('provider "nope" is not an OAuth2 connection', $refused->getMessage());
		}

		try {
			$this->service(provider: ['kind' => 'oauth2-token-set'])->oauth2Provider(providerId: 'bare');
			$this->fail('provider without oauth2 block accepted');
		} catch (InvalidArgumentException $refused) {
			$this->assertSame('provider "bare" declares no oauth2 block', $refused->getMessage());
		}
	}

	public function testAFirstConnectPostsTheExactTokenRequestAndMintsTheCredential(): void {
		$id = $this->service(provider: $this->mastodon())->complete(
			claims: [
				'p' => 'mastodon', 'u' => 'alice', 'h' => 'https://mastodon.example', 's' => 'organisation', 'o' => 'org-1',
				'cl' => 'CID', 'cr' => 'CREF', 'a' => ['pipelinq'], 'nm' => 'Company account', 'sc' => ['read'],
			],
			code: 'CODE',
			verifier: 'VERIFIER',
			redirectUri: 'https://home.example/cb'
		);

		$this->assertSame('minted-uuid', $id);
		$this->assertSame(
			[[
				'https://mastodon.example/oauth/token',
				[
					'body' => [
						'grant_type' => 'authorization_code', 'code' => 'CODE', 'redirect_uri' => 'https://home.example/cb',
						'client_id' => 'RESOLVED', 'code_verifier' => 'VERIFIER', 'client_secret' => 'SECRET',
					],
					'headers' => ['Accept' => 'application/json'],
					'timeout' => 20,
				],
			]],
			$this->posts
		);
		$this->assertSame(
			['credential' => ['provider' => 'mastodon', 'clientId' => 'CID', 'clientCredentialRef' => 'CREF'], 'provider' => 'mastodon', 'actingUserId' => 'alice'],
			$this->resolves[0]
		);

		$this->assertCount(1, $this->mints);
		$mint = $this->mints[0];
		$this->assertSame('Company account', $mint['name']);
		$this->assertSame('mastodon', $mint['provider']);
		$this->assertSame('alice', $mint['owner']);
		$this->assertSame(['pipelinq', 'openregister'], $mint['allowedApps']);
		$this->assertSame('organisation', $mint['scope']);
		$this->assertSame('org-1', $mint['organisation']);
		$this->assertSame('AT', OAuth2TokenSet::fromStoredJson(stored: (string)$mint['secret'])->getAccessToken());
		$expiresAt = $mint['metadata']['expiresAt'];
		unset($mint['metadata']['expiresAt']);
		$this->assertIsString($expiresAt);
		$this->assertSame(
			[
				'kind' => 'oauth2-token-set', 'status' => 'active', 'scopes' => ['read', 'write'],
				'account' => ['id' => '', 'handle' => '', 'displayName' => ''],
				'clientId' => 'CID', 'clientCredentialRef' => 'CREF', 'instanceBaseUrl' => 'https://mastodon.example',
			],
			$mint['metadata']
		);

		$this->assertSame([], $this->persists);
		$this->assertCount(1, $this->records);
		$this->assertSame(['credentialId' => 'minted-uuid', 'scope' => 'organisation', 'owner' => 'alice'], $this->records[0]);
	}

	public function testAFirstConnectToACentralProviderWithBasicAuthAndNoPkce(): void {
		$this->service(provider: $this->github())->complete(
			claims: ['p' => 'github', 'u' => 'bob', 'a' => 'not-a-list'],
			code: 'CODE',
			verifier: 'VERIFIER',
			redirectUri: 'https://home.example/cb'
		);

		$this->assertSame(
			[[
				'https://github.com/login/oauth/access_token',
				[
					'body' => ['grant_type' => 'authorization_code', 'code' => 'CODE', 'redirect_uri' => 'https://home.example/cb', 'client_id' => 'RESOLVED'],
					'headers' => ['Accept' => 'application/json'],
					'timeout' => 20,
					'auth' => ['RESOLVED', 'SECRET'],
				],
			]],
			$this->posts
		);
		$mint = $this->mints[0];
		$this->assertSame('github', $mint['name']);
		$this->assertSame(['openregister'], $mint['allowedApps']);
		$this->assertSame('personal', $mint['scope']);
		$this->assertNull($mint['organisation']);
		$this->assertArrayNotHasKey('instanceBaseUrl', $mint['metadata']);
		$this->assertSame('', $mint['metadata']['clientId']);
	}

	public function testAPublicClientSendsNoSecretAnywhere(): void {
		$this->clientSecret = null;
		$this->service(provider: $this->github())->complete(claims: ['p' => 'github', 'u' => 'bob'], code: 'C', verifier: 'V', redirectUri: 'R');

		$this->assertSame(
			['body' => ['grant_type' => 'authorization_code', 'code' => 'C', 'redirect_uri' => 'R', 'client_id' => 'RESOLVED'], 'headers' => ['Accept' => 'application/json'], 'timeout' => 20],
			$this->posts[0][1]
		);
	}

	public function testATransportFailureIsReportedByClassAndLeavesNothingBehind(): void {
		$this->tokenBody = new \LogicException('upstream said AT=leaked');
		$this->assertCompleteRefused(message: 'token exchange failed: LogicException');
	}

	public function testANonJsonTokenBodyIsRefused(): void {
		$this->tokenBody = 'not json';
		$this->assertCompleteRefused(message: 'token endpoint returned a body that is not JSON');
	}

	public function testATokenErrorIsRefused(): void {
		$this->tokenBody = '{"error":"invalid_grant"}';
		$this->assertCompleteRefused(message: 'token endpoint returned error invalid_grant');
	}

	public function testATokenResponseWithoutAnAccessTokenIsRefused(): void {
		$this->tokenBody = '{"token_type":"Bearer"}';

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('token response carries no access token');
		$this->service(provider: $this->github())->complete(claims: ['p' => 'github'], code: 'C', verifier: 'V', redirectUri: 'R');
	}

	public function testAReauthorisationOverwritesInPlaceAndKeepsTheStoredClient(): void {
		$this->stored = ['provider' => 'mastodon', 'owner' => 'carol', 'instanceBaseUrl' => 'https://mastodon.example', 'clientId' => 'OLD', 'clientCredentialRef' => 'OLDREF'];

		$id = $this->service(provider: $this->mastodon())->complete(
			claims: ['p' => 'mastodon', 'u' => 'alice', 'h' => 'https://mastodon.example', 'cid' => 'existing-uuid', 'cl' => ' ', 's' => 'personal'],
			code: 'C',
			verifier: 'V',
			redirectUri: 'R'
		);

		$this->assertSame('existing-uuid', $id);
		$this->assertSame([], $this->mints);
		$this->assertSame('OLD', $this->resolves[0]['credential']['clientId']);
		$this->assertSame('OLDREF', $this->resolves[0]['credential']['clientCredentialRef']);
		$this->assertSame([['credentialId' => 'existing-uuid', 'scope' => 'personal', 'extraMetadata' => ['status' => 'active']]], $this->persists);
		$this->assertSame([['credentialId' => 'existing-uuid', 'scope' => 'personal', 'owner' => 'carol']], $this->records);
	}

	public function testAReauthorisationFallsBackToTheClaimedOwner(): void {
		$this->stored = ['provider' => 'github'];
		$this->service(provider: $this->github())->complete(claims: ['p' => 'github', 'u' => 'alice', 'cid' => 'x'], code: 'C', verifier: 'V', redirectUri: 'R');

		$this->assertSame('alice', $this->records[0]['owner']);
	}

	/**
	 * @return array<string, array{0: array<string, mixed>|null, 1: string}>
	 */
	public static function refusedReauthorisations(): array {
		return [
			'gone' => [null, 'the credential being re-authorised no longer exists'],
			'other provider' => [['provider' => 'github'], 'a re-authorisation may not change a credential\'s provider'],
			'other host' => [['provider' => 'mastodon', 'instanceBaseUrl' => 'https://other.example'], 'a re-authorisation may not change a credential\'s pinned host'],
		];
	}

	/**
	 * @dataProvider refusedReauthorisations
	 *
	 * @param array<string, mixed>|null $stored The stored credential.
	 * @param string $message The expected refusal.
	 */
	public function testAReauthorisationThatWouldRepointIsRefusedBeforeAnyExchange(?array $stored, string $message): void {
		$this->stored = $stored;

		try {
			$this->service(provider: $this->mastodon())->complete(
				claims: ['p' => 'mastodon', 'u' => 'alice', 'h' => 'https://mastodon.example', 'cid' => 'existing-uuid'],
				code: 'C',
				verifier: 'V',
				redirectUri: 'R'
			);
			$this->fail('re-authorisation accepted');
		} catch (RuntimeException $refused) {
			$this->assertSame($message, $refused->getMessage());
		}

		$this->assertSame([], $this->posts);
		$this->assertSame([], $this->mints);
		$this->assertSame([], $this->persists);
	}

	public function testAnUnpinnedCredentialMayBeReauthorisedFromAnyHost(): void {
		$this->stored = ['provider' => 'mastodon', 'instanceBaseUrl' => ' '];
		$id = $this->service(provider: $this->mastodon())->complete(
			claims: ['p' => 'mastodon', 'h' => 'https://mastodon.example', 'cid' => 'existing-uuid'],
			code: 'C',
			verifier: 'V',
			redirectUri: 'R'
		);

		$this->assertSame('existing-uuid', $id);
	}

	public function testRevokeIsSkippedWithoutAnEndpoint(): void {
		$this->assertSame('', $this->service(provider: null)->revokeUpstream(provider: $this->github(), credential: [], credentialId: 'c', scope: 'personal'));
		$this->assertSame('', $this->service(provider: null)->revokeUpstream(provider: ['oauth2' => 'x'], credential: [], credentialId: 'c', scope: 'personal'));
		$this->assertSame([], $this->posts);
	}

	public function testRevokePostsTheTokenAndClient(): void {
		$provider = $this->mastodon();
		$provider['oauth2']['revokeEndpoint'] = '/oauth/revoke';

		$result = $this->service(provider: null)->revokeUpstream(
			provider: $provider,
			credential: ['provider' => 'mastodon', 'owner' => 'alice', 'instanceBaseUrl' => 'https://mastodon.example'],
			credentialId: 'c',
			scope: 'personal'
		);

		$this->assertSame('', $result);
		$this->assertSame(
			[['https://mastodon.example/oauth/revoke', ['body' => ['token' => 'STORED_AT', 'client_id' => 'RESOLVED', 'client_secret' => 'SECRET'], 'timeout' => 20]]],
			$this->posts
		);
		$this->assertSame('alice', $this->resolves[0]['actingUserId']);
	}

	public function testRevokeFailureIsReportedNotThrown(): void {
		$this->clientSecret = null;
		$this->tokenBody = new \LogicException('down');
		$provider = $this->github();
		$provider['oauth2']['revokeEndpoint'] = 'https://api.github.com/revoke';

		$this->assertSame('revoke_failed', $this->service(provider: null)->revokeUpstream(provider: $provider, credential: [], credentialId: 'c', scope: 'personal'));
		$this->assertSame(['token' => 'STORED_AT', 'client_id' => 'RESOLVED'], $this->posts[0][1]['body']);
	}

	/**
	 * Assert complete() refuses with the message and leaves nothing behind.
	 *
	 * @param string $message The expected refusal.
	 *
	 * @return void
	 */
	private function assertCompleteRefused(string $message): void {
		try {
			$this->service(provider: $this->github())->complete(claims: ['p' => 'github'], code: 'C', verifier: 'V', redirectUri: 'R');
			$this->fail('exchange accepted');
		} catch (RuntimeException $refused) {
			$this->assertSame($message, $refused->getMessage());
			$this->assertStringNotContainsString('leaked', $refused->getMessage());
		}

		$this->assertSame([], $this->mints);
		$this->assertSame([], $this->persists);
		$this->assertSame([], $this->records);
	}

	/**
	 * A per-instance provider with PKCE and client_secret_post.
	 *
	 * @return array<string, mixed>
	 */
	private function mastodon(): array {
		return [
			'identifier' => 'mastodon',
			'kind' => 'oauth2-token-set',
			'oauth2' => [
				'authorizationEndpoint' => '/oauth/authorize',
				'tokenEndpoint' => '/oauth/token',
				'endpointsRelativeToInstance' => true,
				'clientAuth' => 'client_secret_post',
				'pkce' => 'S256',
				'scopeSeparator' => ',',
				'authorizationParameters' => ['force_login' => 'true'],
				'defaultScopes' => ['read:accounts'],
			],
		];
	}

	/**
	 * A central provider with basic client auth and no PKCE.
	 *
	 * @return array<string, mixed>
	 */
	private function github(): array {
		return [
			'identifier' => 'github',
			'kind' => 'oauth2-token-set',
			'oauth2' => [
				'authorizationEndpoint' => 'https://github.com/login/oauth/authorize',
				'tokenEndpoint' => 'https://github.com/login/oauth/access_token',
				'clientAuth' => 'client_secret_basic',
				'defaultScopes' => ['repo', 'user'],
			],
		];
	}

	/**
	 * Build the connect seam from scripted leaf collaborators.
	 *
	 * @param array<string, mixed>|null $provider What the catalogue returns.
	 *
	 * @return OAuth2ConnectService
	 */
	private function service(?array $provider): OAuth2ConnectService {
		$catalogue = $this->createMock(ProviderCatalogue::class);
		$catalogue->method('get')->willReturn($provider);

		$objectService = $this->createMock(ObjectOperationsInterface::class);
		$objectService->method('find')->willReturnCallback(function (): ?ObjectEntity {
			if ($this->stored === null) {
				return null;
			}

			$entity = new ObjectEntity();
			$entity->setUuid('existing-uuid');
			$entity->setObject($this->stored);
			return $entity;
		});

		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('mint')->willReturnCallback(function (string $name, string $provider, string $owner, array $allowedApps = [], ?string $secret = null, string $scope = 'personal', ?string $organisation = null, array $metadata = []): ObjectEntity {
			$this->mints[] = compact('name', 'provider', 'owner', 'allowedApps', 'secret', 'scope', 'organisation', 'metadata');
			$minted = new ObjectEntity();
			$minted->setUuid('minted-uuid');
			return $minted;
		});

		$refresh = $this->createMock(OAuth2RefreshService::class);
		$refresh->method('persist')->willReturnCallback(function (string $credentialId, string $scope, OAuth2TokenSet $set, array $extraMetadata = []): void {
			$this->persists[] = ['credentialId' => $credentialId, 'scope' => $scope, 'extraMetadata' => $extraMetadata];
		});
		$refresh->method('storedSet')->willReturn(OAuth2TokenSet::fromTokenResponse(response: ['access_token' => 'STORED_AT']));

		$identities = $this->createMock(OAuth2AccountIdentity::class);
		$identities->method('record')->willReturnCallback(function (array $provider, string $credentialId, string $scope, OAuth2TokenSet $set, string $owner): void {
			$this->records[] = ['credentialId' => $credentialId, 'scope' => $scope, 'owner' => $owner];
		});

		$clients = $this->createMock(OAuth2ClientResolver::class);
		$clients->method('resolve')->willReturnCallback(function (array $credential, string $provider, ?string $actingUserId = null): array {
			$this->resolves[] = ['credential' => $credential, 'provider' => $provider, 'actingUserId' => $actingUserId];
			return ['clientId' => 'RESOLVED', 'clientSecret' => $this->clientSecret];
		});

		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(function (string $url, array $options): IResponse {
			$this->posts[] = [$url, $options];
			if ($this->tokenBody instanceof \Throwable) {
				throw $this->tokenBody;
			}

			$response = $this->createMock(IResponse::class);
			$response->method('getBody')->willReturn($this->tokenBody);
			return $response;
		});
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		return $this->build(
			catalogue: $catalogue,
			broker: $broker,
			clients: $clients,
			refresh: $refresh,
			identities: $identities,
			objectService: $objectService,
			clientService: $clientService
		);
	}

	/**
	 * Wire the seam the way the container does.
	 *
	 * @param ProviderCatalogue $catalogue The catalogue.
	 * @param CredentialBrokerService $broker The broker.
	 * @param OAuth2ClientResolver $clients The client resolver.
	 * @param OAuth2RefreshService $refresh The refresh service.
	 * @param OAuth2AccountIdentity $identities The identity reader.
	 * @param ObjectOperationsInterface $objectService The object store.
	 * @param IClientService $clientService The HTTP client factory.
	 *
	 * @return OAuth2ConnectService
	 */
	private function build(
		ProviderCatalogue $catalogue,
		CredentialBrokerService $broker,
		OAuth2ClientResolver $clients,
		OAuth2RefreshService $refresh,
		OAuth2AccountIdentity $identities,
		ObjectOperationsInterface $objectService,
		IClientService $clientService,
	): OAuth2ConnectService {
		$logger = $this->createMock(LoggerInterface::class);

		return new OAuth2ConnectService(
			sources: new OAuth2ConnectClients(
				catalogue: $catalogue,
				clients: $clients,
				instanceClients: $this->createMock(OAuth2InstanceClient::class),
			),
			steps: new OAuth2ConnectSteps(
				exchange: new OAuth2CodeExchange(clientService: $clientService),
				writer: new OAuth2ConnectionWriter(
					broker: $broker,
					refresh: $refresh,
					identities: $identities,
					objectService: $objectService
				),
				revoker: new OAuth2UpstreamRevoker(refresh: $refresh, clients: $clients, clientService: $clientService, logger: $logger),
			),
		);
	}
}
