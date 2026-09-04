<?php

/**
 * OAuth2InstanceClientTest — registering an application at somebody else's server.
 *
 * A Mastodon connect with no application registered builds an authorization URL
 * naming an empty client id, and the person meets a confused error on their own
 * server rather than here. So the first assertion is simply that it happens at all,
 * because a registration method with no caller is the shape this started as.
 *
 * The rest are about how OFTEN. Registering again on every reconnect would leave a
 * trail of live applications on a person's own server, each holding a client secret
 * that nothing will ever revoke and nobody will ever look at, and no test that only
 * checked the happy path would notice.
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
use OCA\OpenRegister\Service\Credential\OAuth2InstanceClient;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Credential\OAuth2InstanceClient
 */
class OAuth2InstanceClientTest extends TestCase {
	/** @var array<int, string> Every URL the service POSTed to. */
	private array $posts = [];

	/** @var array<int, array<string, mixed>> Every credential minted. */
	private array $mints = [];

	protected function setUp(): void {
		$this->posts = [];
		$this->mints = [];
	}

	public function testAMastodonConnectRegistersAnApplicationAtTheAccountsOwnServer(): void {
		$claims = $this->makeClient()->ensure(
			provider: $this->mastodon(),
			claims: $this->claims(),
			redirectUri: 'https://home.example/apps/openregister/oauth2/callback'
		);

		$this->assertSame(['https://mastodon.example/api/v1/apps'], $this->posts);
		$this->assertSame('REGISTERED_CLIENT_ID', $claims['cl']);
		$this->assertSame('minted-uuid', $claims['cr']);
	}

	public function testTheIssuedClientSecretBecomesItsOwnBrokeredCredential(): void {
		$this->makeClient()->ensure(
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
		$claims = $this->makeClient()->ensure(
			provider: $this->mastodon(),
			claims: array_merge($this->claims(), ['cl' => 'TENANT_CLIENT_ID', 'cr' => 'tenant-ref']),
			redirectUri: 'https://home.example/apps/openregister/oauth2/callback'
		);

		$this->assertSame([], $this->posts);
		$this->assertSame('TENANT_CLIENT_ID', $claims['cl']);
	}

	public function testAReconnectReusesTheApplicationAlreadyPinnedToTheCredential(): void {
		$client = $this->makeClient(
			existing: [
				'provider' => 'mastodon',
				'clientId' => 'EXISTING_CLIENT_ID',
				'clientCredentialRef' => 'existing-ref',
			]
		);

		$claims = $client->ensure(
			provider: $this->mastodon(),
			claims: array_merge($this->claims(), ['cid' => 'existing-uuid']),
			redirectUri: 'https://home.example/apps/openregister/oauth2/callback'
		);

		$this->assertSame([], $this->posts, 'a reconnect must not leave a second live application behind');
		$this->assertSame('EXISTING_CLIENT_ID', $claims['cl']);
		$this->assertSame('existing-ref', $claims['cr']);
	}

	public function testAProviderWithACentralRegistryRegistersNothing(): void {
		$claims = $this->makeClient()->ensure(
			provider: ['identifier' => 'x', 'oauth2' => ['tokenEndpoint' => 'https://api.x.com/2/oauth2/token']],
			claims: $this->claims(),
			redirectUri: 'https://home.example/apps/openregister/oauth2/callback'
		);

		$this->assertSame([], $this->posts);
		$this->assertSame('', $claims['cl']);
	}

	public function testAServerThatIssuesNoClientIdIsRefusedRatherThanHalfConnected(): void {
		$client = $this->makeClient(registration: ['error' => 'unauthorized']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('no client id');

		$client->ensure(
			provider: $this->mastodon(),
			claims: $this->claims(),
			redirectUri: 'https://home.example/apps/openregister/oauth2/callback'
		);
	}

	public function testAnUnreachableServerIsReportedWithoutQuotingItsAnswer(): void {
		// A registration failure can quote the server's own words, and those words can
		// contain the request that was made. Only the class name travels.
		$client = $this->makeClient(postThrows: new RuntimeException('Connection refused to https://mastodon.example/api/v1/apps'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('application registration failed');

		try {
			$client->ensure(
				provider: $this->mastodon(),
				claims: $this->claims(),
				redirectUri: 'https://home.example/apps/openregister/oauth2/callback'
			);
		} catch (RuntimeException $refused) {
			$this->assertStringNotContainsString('Connection refused', $refused->getMessage());
			throw $refused;
		}
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
				'registrationEndpoint' => '/api/v1/apps',
				'endpointsRelativeToInstance' => true,
				'scopeSeparator' => ' ',
				'defaultScopes' => ['read:accounts'],
			],
		];
	}

	/**
	 * Build the service over a scripted server, broker and object store.
	 *
	 * @param array<string, mixed>|null $existing The credential a reconnect targets.
	 * @param array<string, mixed>|null $registration The app-registration response.
	 * @param \Throwable|null $postThrows What the HTTP client throws instead of answering.
	 *
	 * @return OAuth2InstanceClient The service under test.
	 */
	private function makeClient(
		?array $existing = null,
		?array $registration = null,
		?\Throwable $postThrows = null,
	): OAuth2InstanceClient {
		$registration ??= ['client_id' => 'REGISTERED_CLIENT_ID', 'client_secret' => 'YOUR_CLIENT_SECRET_HERE'];

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
				$this->mints[] = ['provider' => $provider, 'allowedApps' => $allowedApps, 'secret' => $secret];
				$minted = new ObjectEntity();
				$minted->setUuid('minted-uuid');
				return $minted;
			}
		);

		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			function (string $url) use ($registration, $postThrows): IResponse {
				$this->posts[] = $url;
				if ($postThrows !== null) {
					throw $postThrows;
				}

				$response = $this->createMock(IResponse::class);
				$response->method('getBody')->willReturn((string)json_encode($registration));
				return $response;
			}
		);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		return new OAuth2InstanceClient(
			broker: $broker,
			objectService: $objectService,
			clientService: $clientService
		);
	}
}
