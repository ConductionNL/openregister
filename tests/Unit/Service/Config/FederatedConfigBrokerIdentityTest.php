<?php

/**
 * Brokered GitHub calls name the calling app and the acting user (G1).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Config
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/store-through-federated-config
 */

declare(strict_types=1);

namespace Unit\Service\Config;

use OCA\OpenRegister\Service\Config\BrokeredPublishTarget;
use OCA\OpenRegister\Service\Config\BrokerIdentity;
use OCA\OpenRegister\Service\Config\BundleFetchRequest;
use OCA\OpenRegister\Service\Config\BundleSigner;
use OCA\OpenRegister\Service\Config\ConfigPublishRequest;
use OCA\OpenRegister\Service\Config\ConfigPublishTarget;
use OCA\OpenRegister\Service\Config\FederatedConfigService;
use OCA\OpenRegister\Service\Config\FederatedConfigTransport;
use OCA\OpenRegister\Service\Config\IShareableConfigType;
use OCA\OpenRegister\Service\Config\ShareableConfigTypeRegistry;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(FederatedConfigService::class)]
#[CoversClass(BrokerIdentity::class)]
#[CoversClass(BrokeredPublishTarget::class)]
#[CoversClass(BundleFetchRequest::class)]
#[UsesClass(ConfigPublishTarget::class)]
#[UsesClass(ConfigPublishRequest::class)]
#[UsesClass(FederatedConfigTransport::class)]
final class FederatedConfigBrokerIdentityTest extends TestCase {

	/**
	 * Every broker call made, as `METHOD path` => [appId, actingUserId].
	 *
	 * @var list<array{0: string, 1: string, 2: string|null}>
	 */
	private array $calls = [];

	public function testAPublishWithoutAnIdentityIsMadeAsOpenRegisterWithNoActingUser(): void {
		$this->service()->publish('demo.type', ['x' => 1], new ConfigPublishTarget(repo: 'acme/demo', path: 'bundle.json'), 'cred-1');

		self::assertNotSame([], $this->calls);
		foreach ($this->calls as [$call, $app, $user]) {
			self::assertSame('openregister', $app, $call);
			self::assertNull($user, $call);
		}
	}

	public function testEveryPublishCallNamesTheCallingAppAndTheActingUser(): void {
		$this->service()->publish(
			typeId: 'demo.type',
			selection: ['x' => 1],
			target: new ConfigPublishTarget(repo: 'acme/demo', path: 'bundle.json'),
			credentialId: 'cred-1',
			appId: 'hermiq',
			actingUserId: 'alice'
		);

		$made = array_column($this->calls, 0);
		self::assertContains('GET /repos/acme/demo', $made);
		self::assertContains('PUT /repos/acme/demo/contents/bundle.json', $made);
		self::assertContains('PUT /repos/acme/demo/topics', $made);
		foreach ($this->calls as [$call, $app, $user]) {
			self::assertSame('hermiq', $app, $call);
			self::assertSame('alice', $user, $call);
		}
	}

	public function testABrokeredSearchNamesTheCallingApp(): void {
		$this->service()->discover(topic: 'demo-topic', credentialId: 'cred-1', appId: 'hermiq', actingUserId: 'alice');

		self::assertSame([['GET /search/repositories?q=topic%3Ademo-topic&sort=updated&per_page=50', 'hermiq', 'alice']], $this->calls);
	}

	public function testABrokeredReadNamesTheCallingAppAndEmptyValuesFallBack(): void {
		$bundle = $this->service()->fetchBundle(repo: 'acme/demo', path: '/bundle.json', credentialId: 'cred-1', appId: ' ', actingUserId: '');

		self::assertSame(['type' => 'demo.type'], $bundle);
		self::assertSame([['GET /repos/acme/demo/contents/bundle.json', 'openregister', null]], $this->calls);
	}

	public function testAnAnonymousReadNeverReachesTheBroker(): void {
		$client = $this->createMock(\OCP\Http\Client\IClient::class);
		$response = $this->createMock(\OCP\Http\Client\IResponse::class);
		$response->method('getBody')->willReturn('{"items":[]}');
		$client->method('get')->willReturn($response);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		self::assertSame([], $this->service(clients: $clients)->discover(topic: 'demo-topic', appId: 'hermiq'));
		self::assertSame([], $this->calls);
	}

	/**
	 * The service over a broker that records who each call names.
	 *
	 * @param IClientService|null $clients The anonymous HTTP client, when a test needs one.
	 *
	 * @return FederatedConfigService
	 */
	private function service(?IClientService $clients = null): FederatedConfigService {
		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('request')->willReturnCallback(
			function (
				string $credentialId,
				string $appId,
				string $method,
				string $path,
				array $headers = [],
				?string $body = null,
				?string $actingUserId = null
			): array {
				$this->calls[] = [$method . ' ' . $path, $appId, $actingUserId];
				if ($method === 'GET' && str_contains($path, '/contents/') === true) {
					return ['status' => 200, 'body' => (string)json_encode(['content' => base64_encode('{"type":"demo.type"}')])];
				}

				if ($method === 'GET' && str_starts_with($path, '/search/') === true) {
					return ['status' => 200, 'body' => '{"items":[]}'];
				}

				return ['status' => 201, 'body' => '{}'];
			}
		);
		$signer = $this->createMock(BundleSigner::class);
		$signer->method('sign')->willReturnArgument(0);
		$registry = $this->createMock(ShareableConfigTypeRegistry::class);
		$registry->method('get')->willReturn($this->type());

		return new FederatedConfigService(
			new FederatedConfigTransport($broker, $signer, ($clients ?? $this->createMock(IClientService::class))),
			$registry,
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * A shareable type whose discovery topic is `demo-topic`.
	 *
	 * @return IShareableConfigType
	 */
	private function type(): IShareableConfigType {
		return new class implements IShareableConfigType {
			public function getId(): string {
				return 'demo.type';
			}

			public function getDisplayName(): string {
				return 'Demo';
			}

			public function getTopic(): string {
				return 'demo-topic';
			}

			public function serialise(array $selection): array {
				return ['type' => 'demo.type'];
			}

			public function deserialise(array $bundle): array {
				return [];
			}
		};
	}
}
