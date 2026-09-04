<?php

/**
 * OAuth2ReauthorisationTest — repairing a connection without moving it.
 *
 * Every `socialAccount` and `searchProperty` in a consuming app points at a
 * credential id, so a repair that minted a SECOND credential would leave all of them
 * pointing at the dead one, silently. These tests pin the three invariants that make
 * a repair a repair: the same id comes back, the grants survive, and a
 * re-authorisation cannot quietly re-point the credential at another provider or
 * another server.
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
 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-token-set/spec.md#requirement-a-re-authorised-credential-returns-to-active-in-place
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\OAuth2AccountIdentity;
use OCA\OpenRegister\Service\Credential\OAuth2ClientResolver;
use OCA\OpenRegister\Service\Credential\OAuth2ConnectService;
use OCA\OpenRegister\Service\Credential\OAuth2InstanceClient;
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
class OAuth2ReauthorisationTest extends TestCase {
	/** @var integer How many brand-new credentials were minted. */
	private int $mints = 0;

	/** @var array<int, array<string, mixed>> Token sets persisted onto an existing credential. */
	private array $persists = [];

	/** @var array<int, string> The allowedApps the last mint was given. */
	private array $mintedApps = [];

	protected function setUp(): void {
		$this->mints = 0;
		$this->persists = [];
	}

	public function testReauthorisingKeepsTheCredentialIdAndMintsNothingNew(): void {
		$service = $this->makeService(existing: $this->existingCredential());

		$credentialId = $service->complete(
			claims: $this->claims(),
			code: 'AUTH_CODE_HERE',
			verifier: 'VERIFIER_HERE',
			redirectUri: 'https://home.example/apps/openregister/oauth2/callback'
		);

		$this->assertSame('existing-uuid', $credentialId);
		$this->assertSame(0, $this->mints, 'a repair must not create a second credential for the same account');
		$this->assertCount(1, $this->persists);
		$this->assertSame('active', $this->persists[0]['extraMetadata']['status']);
	}

	public function testReauthorisingCannotChangeTheProvider(): void {
		$service = $this->makeService(existing: array_merge($this->existingCredential(), ['provider' => 'x']));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('provider');

		$service->complete(
			claims: $this->claims(),
			code: 'AUTH_CODE_HERE',
			verifier: 'VERIFIER_HERE',
			redirectUri: 'https://home.example/apps/openregister/oauth2/callback'
		);
	}

	public function testReauthorisingCannotChangeThePinnedHost(): void {
		$service = $this->makeService(existing: $this->existingCredential());

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('pinned host');

		$service->complete(
			claims: array_merge($this->claims(), ['h' => 'https://another.example']),
			code: 'AUTH_CODE_HERE',
			verifier: 'VERIFIER_HERE',
			redirectUri: 'https://home.example/apps/openregister/oauth2/callback'
		);
	}

	public function testReauthorisingACredentialThatIsGoneIsRefused(): void {
		$service = $this->makeService(existing: null);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('no longer exists');

		$service->complete(
			claims: $this->claims(),
			code: 'AUTH_CODE_HERE',
			verifier: 'VERIFIER_HERE',
			redirectUri: 'https://home.example/apps/openregister/oauth2/callback'
		);
	}

	public function testAFirstConnectMintsANewCredential(): void {
		$service = $this->makeService(existing: null);

		$credentialId = $service->complete(
			claims: array_merge($this->claims(), ['cid' => '']),
			code: 'AUTH_CODE_HERE',
			verifier: 'VERIFIER_HERE',
			redirectUri: 'https://home.example/apps/openregister/oauth2/callback'
		);

		$this->assertSame('minted-uuid', $credentialId);
		$this->assertSame(1, $this->mints);
		$this->assertSame([], $this->persists);

		// openregister is granted alongside whatever the start asked for, because it
		// is the app that reads the connected account's identity right afterwards and
		// the allowedApps guard would otherwise deny that call. Leaving it off
		// produces no error, just a connection that never learns whose it is.
		$this->assertSame(['pipelinq', 'openregister'], $this->mintedApps);
	}

	public function testAProviderThatIsNotAnOAuth2ConnectionIsRefused(): void {
		$service = $this->makeService(existing: null, provider: ['identifier' => 'github', 'baseUrl' => 'https://api.github.com']);

		$this->expectException(\InvalidArgumentException::class);

		$service->complete(
			claims: array_merge($this->claims(), ['p' => 'github', 'cid' => '']),
			code: 'AUTH_CODE_HERE',
			verifier: 'VERIFIER_HERE',
			redirectUri: 'https://home.example/apps/openregister/oauth2/callback'
		);
	}

	/**
	 * The claims a re-authorisation carries.
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
			'cid' => 'existing-uuid',
			'nm' => 'Mastodon company account',
		];
	}

	/**
	 * The credential a repair targets.
	 *
	 * @return array<string, mixed> The credential's property bag.
	 */
	private function existingCredential(): array {
		return [
			'name' => 'Mastodon company account',
			'provider' => 'mastodon',
			'owner' => 'alice',
			'kind' => 'oauth2-token-set',
			'status' => 'relink_needed',
			'instanceBaseUrl' => 'https://mastodon.example',
			'allowedApps' => ['pipelinq', 'hermiq'],
		];
	}

	/**
	 * Build the service with a scripted token endpoint and object store.
	 *
	 * @param array<string, mixed>|null $existing The credential the repair targets, or null.
	 * @param array<string, mixed>|null $provider The catalogue entry.
	 *
	 * @return OAuth2ConnectService The service under test.
	 */
	private function makeService(?array $existing, ?array $provider = null): OAuth2ConnectService {
		$provider ??= [
			'identifier' => 'mastodon',
			'kind' => 'oauth2-token-set',
			'baseUrlFrom' => 'instanceBaseUrl',
			'oauth2' => [
				'tokenEndpoint' => '/oauth/token',
				'endpointsRelativeToInstance' => true,
				'clientAuth' => 'client_secret_post',
				'pkce' => 'S256',
				'defaultScopes' => ['read:accounts'],
			],
		];

		$catalogue = $this->createMock(ProviderCatalogue::class);
		$catalogue->method('get')->willReturn($provider);

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
			function (string $name, string $provider, string $owner, array $allowedApps = []): ObjectEntity {
				$this->mints++;
				$this->mintedApps = $allowedApps;
				$minted = new ObjectEntity();
				$minted->setUuid('minted-uuid');
				return $minted;
			}
		);

		$refresh = $this->createMock(OAuth2RefreshService::class);
		$refresh->method('persist')->willReturnCallback(
			function (string $credentialId, string $scope, OAuth2TokenSet $set, array $extraMetadata = []): void {
				$this->persists[] = ['credentialId' => $credentialId, 'scope' => $scope, 'extraMetadata' => $extraMetadata];
			}
		);

		$clients = $this->createMock(OAuth2ClientResolver::class);
		$clients->method('resolve')->willReturn(['clientId' => 'CLIENT_ID_HERE', 'clientSecret' => 'YOUR_CLIENT_SECRET_HERE']);

		$httpResponse = $this->createMock(IResponse::class);
		$httpResponse->method('getBody')->willReturn(
			(string)json_encode(['access_token' => 'ACCESS_TOKEN_HERE', 'refresh_token' => 'REFRESH_TOKEN_HERE', 'expires_in' => 3600])
		);
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturn($httpResponse);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		return new OAuth2ConnectService(
			catalogue: $catalogue,
			broker: $broker,
			clients: $clients,
			refresh: $refresh,
			instanceClients: $this->createMock(OAuth2InstanceClient::class),
			identities: $this->createMock(OAuth2AccountIdentity::class),
			objectService: $objectService,
			clientService: $clientService,
			logger: $this->createMock(LoggerInterface::class)
		);
	}
}
