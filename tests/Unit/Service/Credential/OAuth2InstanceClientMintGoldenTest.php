<?php

/**
 * OAuth2InstanceClientMintGoldenTest — who owns the client secret a registration mints.
 *
 * A per-instance registration stores the issued client secret as its own brokered
 * credential. Its owner, credential scope and organisation decide who may later
 * spend it, so this pins every argument the broker receives for a personal and an
 * organisation-scoped flow, and that a refused registration mints nothing.
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
 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-bluesky-is-its-own-client-and-mastodon-registers-per-instance
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\CredentialMintOptions;
use OCA\OpenRegister\Service\Credential\CredentialMint;
use OCA\OpenRegister\Service\Credential\OAuth2InstanceClient;
use OCA\OpenRegister\Service\Credential\OAuth2RegistrationFailedException;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Credential\OAuth2InstanceClient
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Service\Credential\OAuth2InstanceHost
 * @uses \OCA\OpenRegister\Service\Credential\OAuth2FlowClaims
 * @uses \OCA\OpenRegister\Service\Credential\CredentialMint
 * @uses \OCA\OpenRegister\Service\Credential\CredentialMintOptions
 * @uses \OCA\OpenRegister\Service\Credential\OAuth2ClientRegistration
 */
class OAuth2InstanceClientMintGoldenTest extends TestCase {
	/** @var array<int, array<string, mixed>> Every mint call, with all its named arguments. */
	private array $mints = [];

	/** @var array<int, array<string, mixed>> Every registration POST, URL and options. */
	private array $posts = [];

	protected function setUp(): void {
		$this->mints = [];
		$this->posts = [];
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function flows(): array {
		return [
			'personal' => [
				['u' => 'alice', 's' => 'personal', 'h' => 'https://Mastodon.Example/', 'sc' => ['read:accounts']],
				[
					'name' => 'OAuth2 client for https://mastodon.example',
					'provider' => 'generic-oauth2',
					'owner' => 'alice',
					'allowedApps' => ['openregister'],
					'secret' => 'ISSUED_SECRET',
					'scope' => 'personal',
					'organisation' => null,
				],
			],
			'organisation' => [
				['u' => 'bob', 's' => 'organisation', 'o' => 'org-uuid-1', 'h' => 'https://mastodon.example', 'sc' => ['read:accounts', 'write:statuses']],
				[
					'name' => 'OAuth2 client for https://mastodon.example',
					'provider' => 'generic-oauth2',
					'owner' => 'bob',
					'allowedApps' => ['openregister'],
					'secret' => 'ISSUED_SECRET',
					'scope' => 'organisation',
					'organisation' => 'org-uuid-1',
				],
			],
			'scope defaults to personal' => [
				['u' => 'carol', 'h' => 'https://mastodon.example'],
				[
					'name' => 'OAuth2 client for https://mastodon.example',
					'provider' => 'generic-oauth2',
					'owner' => 'carol',
					'allowedApps' => ['openregister'],
					'secret' => 'ISSUED_SECRET',
					'scope' => 'personal',
					'organisation' => null,
				],
			],
		];
	}//end flows()

	/**
	 * The client secret is minted for the flow's user, scope and organisation.
	 *
	 * @param array<string, mixed> $claims   The flow claims.
	 * @param array<string, mixed> $expected The mint arguments.
	 *
	 * @return void
	 */
	#[DataProvider('flows')]
	public function testTheClientSecretIsMintedForTheFlowsOwner(array $claims, array $expected): void {
		$result = $this->client(['client_id' => 'CID', 'client_secret' => 'ISSUED_SECRET'])->ensure(registration: new \OCA\OpenRegister\Service\Credential\OAuth2ClientRegistration(
			provider: $this->mastodon(),
			claims: $claims,
			redirectUri: 'https://home.example/cb'
		));

		$this->assertSame([$expected], $this->mints);
		$this->assertSame('CID', $result['cl']);
		$this->assertSame('minted-uuid', $result['cr']);
		$this->assertSame('minted-uuid', $result[OAuth2InstanceClient::MINTED_KEY]);
		$this->assertSame('https://mastodon.example/api/v1/apps', $this->posts[0]['url']);
		$this->assertSame('https://home.example/cb', $this->posts[0]['options']['body']['redirect_uris']);
		$this->assertSame(implode(' ', ($claims['sc'] ?? ['read:accounts'])), $this->posts[0]['options']['body']['scopes']);
	}//end testTheClientSecretIsMintedForTheFlowsOwner()

	/**
	 * A registration answer without a client id is refused and mints nothing.
	 *
	 * @return void
	 */
	public function testARefusedRegistrationMintsNothing(): void {
		try {
			$this->client(['client_secret' => 'ISSUED_SECRET'])->ensure(registration: new \OCA\OpenRegister\Service\Credential\OAuth2ClientRegistration(
				provider: $this->mastodon(),
				claims: ['u' => 'alice', 's' => 'personal', 'h' => 'https://mastodon.example'],
				redirectUri: 'https://home.example/cb'
			));
			$this->fail('A registration without a client id must be refused.');
		} catch (OAuth2RegistrationFailedException $refused) {
			$this->assertSame([], $this->mints);
		}
	}//end testARefusedRegistrationMintsNothing()

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
	}//end mastodon()

	/**
	 * The service over a scripted server and a recording broker.
	 *
	 * @param array<string, mixed> $registration The app-registration answer.
	 *
	 * @return OAuth2InstanceClient The service under test.
	 */
	private function client(array $registration): OAuth2InstanceClient {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn(null);

		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('mintCredential')->willReturnCallback(
			function (CredentialMint $mint, CredentialMintOptions $options): ObjectEntity {
				$this->mints[] = [
					'name' => $mint->name,
					'provider' => $mint->provider,
					'owner' => $mint->owner,
					'allowedApps' => $options->allowedApps,
					'secret' => $options->secret(),
					'scope' => $options->scope,
					'organisation' => $options->organisation,
				];
				$minted = new ObjectEntity();
				$minted->setUuid('minted-uuid');
				return $minted;
			}
		);

		$http = $this->createMock(IClient::class);
		$http->method('post')->willReturnCallback(
			function (string $url, array $options) use ($registration): IResponse {
				$this->posts[] = ['url' => $url, 'options' => $options];
				$response = $this->createMock(IResponse::class);
				$response->method('getBody')->willReturn((string)json_encode($registration));
				return $response;
			}
		);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($http);

		return new OAuth2InstanceClient(
			broker: $broker,
			objectService: $objectService,
			clientService: $clientService
		);
	}//end client()
}//end class
