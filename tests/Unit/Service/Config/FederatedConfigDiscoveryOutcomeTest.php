<?php

/**
 * Discovery says how the search went and sends the free-text term (G2).
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

use OCA\OpenRegister\Service\Config\BrokerIdentity;
use OCA\OpenRegister\Service\Config\BundleSigner;
use OCA\OpenRegister\Service\Config\FederatedConfigService;
use OCA\OpenRegister\Service\Config\FederatedConfigTransport;
use OCA\OpenRegister\Service\Config\ShareableConfigTypeRegistry;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(FederatedConfigService::class)]
#[UsesClass(BrokerIdentity::class)]
#[UsesClass(FederatedConfigTransport::class)]
final class FederatedConfigDiscoveryOutcomeTest extends TestCase {

	private const ITEMS = '{"items":[{"full_name":"acme/notes","name":"notes","description":"Meeting notes","html_url":"https://github.com/acme/notes","stargazers_count":3,"updated_at":"2026-09-01T00:00:00Z","topics":["hermiq-skill",7]},"junk"]}';

	/**
	 * The broker calls made: [path, appId, actingUserId].
	 *
	 * @var list<array{0: string, 1: string, 2: string|null}>
	 */
	private array $brokered = [];

	/**
	 * The anonymous URLs read.
	 *
	 * @var list<string>
	 */
	private array $anonymous = [];

	public function testAnAnonymousSearchSendsTheTopicAndTheTermTogetherAndAnswersCards(): void {
		$answer = $this->service(anonymous: [200, self::ITEMS])->discoverOutcome(topic: ' hermiq-skill ', term: ' meeting notes ');

		self::assertSame(
			['https://api.github.com/search/repositories?q=' . rawurlencode('topic:hermiq-skill meeting notes') . '&sort=updated&per_page=50'],
			$this->anonymous
		);
		self::assertSame('ok', $answer['outcome']);
		self::assertSame([false, false, 200], [$answer['brokerUsed'], $answer['rateLimited'], $answer['status']]);
		self::assertSame(
			[['repo' => 'acme/notes', 'name' => 'notes', 'description' => 'Meeting notes', 'url' => 'https://github.com/acme/notes', 'stars' => 3, 'updated' => '2026-09-01T00:00:00Z', 'topics' => ['hermiq-skill']]],
			$answer['cards']
		);
	}

	public function testWithoutATermOnlyTheTopicIsSearched(): void {
		$this->service(anonymous: [200, '{"items":[]}'])->discoverOutcome(topic: 'hermiq-skill');

		self::assertStringContainsString('q=' . rawurlencode('topic:hermiq-skill') . '&', $this->anonymous[0]);
	}

	public function testABrokeredSearchNamesTheCallingAppAndTheActingUser(): void {
		$answer = $this->service(broker: [200, self::ITEMS])->discoverOutcome(
			topic: 'hermiq-skill',
			credentialId: 'cred-1',
			identity: BrokerIdentity::of(appId: 'hermiq', actingUserId: 'alice')
		);

		self::assertSame('hermiq', $this->brokered[0][1]);
		self::assertSame('alice', $this->brokered[0][2]);
		self::assertSame([], $this->anonymous);
		self::assertSame(['ok', true, 1], [$answer['outcome'], $answer['brokerUsed'], count($answer['cards'])]);
	}

	public function testABrokeredSearchWithoutAnIdentityIsMadeAsOpenRegister(): void {
		$this->service(broker: [200, '{"items":[]}'])->discoverOutcome(topic: 'hermiq-skill', credentialId: 'cred-1');

		self::assertSame(['openregister', null], [$this->brokered[0][1], $this->brokered[0][2]]);
	}

	public function testASearchTheBrokerRefusesIsRetriedAnonymously(): void {
		$answer = $this->service(broker: new RuntimeException('not allowed for this app'), anonymous: [200, self::ITEMS])
			->discoverOutcome(topic: 'hermiq-skill', credentialId: 'cred-1');

		self::assertCount(1, $this->brokered);
		self::assertCount(1, $this->anonymous);
		self::assertSame(['ok', false, 1], [$answer['outcome'], $answer['brokerUsed'], count($answer['cards'])]);
	}

	public function testARateLimitedSearchSaysSoInsteadOfAnsweringAnEmptyList(): void {
		foreach ([403, 429] as $status) {
			$answer = $this->service(anonymous: [$status, '{"message":"API rate limit exceeded"}'])->discoverOutcome(topic: 'hermiq-skill');

			self::assertSame(['rate_limited', true, $status, []], [$answer['outcome'], $answer['rateLimited'], $answer['status'], $answer['cards']], (string)$status);
		}
	}

	public function testABrokeredRateLimitIsNotRetriedAnonymously(): void {
		$answer = $this->service(broker: [403, '{}'], anonymous: [200, self::ITEMS])->discoverOutcome(topic: 'hermiq-skill', credentialId: 'cred-1');

		self::assertSame([], $this->anonymous);
		self::assertSame(['rate_limited', true], [$answer['outcome'], $answer['brokerUsed']]);
	}

	public function testAnUnreachableGitHubSaysUnreachable(): void {
		$failed = $this->service(anonymous: new RuntimeException('connection refused'))->discoverOutcome(topic: 'hermiq-skill');
		$broken = $this->service(anonymous: [502, 'Bad gateway'])->discoverOutcome(topic: 'hermiq-skill');

		self::assertSame(['unreachable', 0, false], [$failed['outcome'], $failed['status'], $failed['rateLimited']]);
		self::assertSame(['unreachable', 502], [$broken['outcome'], $broken['status']]);
	}

	public function testAnEmptyTopicSearchesNothing(): void {
		$answer = $this->service(anonymous: [200, self::ITEMS])->discoverOutcome(topic: '  ', term: 'notes');

		self::assertSame([], $this->anonymous);
		self::assertSame(['ok', []], [$answer['outcome'], $answer['cards']]);
	}

	/**
	 * A service over a broker and an anonymous client that answer as given.
	 *
	 * @param array{0: int, 1: string}|RuntimeException|null $broker    The broker's answer, or what it throws.
	 * @param array{0: int, 1: string}|RuntimeException|null $anonymous The anonymous answer, or what the client throws.
	 *
	 * @return FederatedConfigService
	 */
	private function service(array|RuntimeException|null $broker = null, array|RuntimeException|null $anonymous = null): FederatedConfigService {
		$brokerService = $this->createMock(CredentialBrokerService::class);
		$brokerService->method('request')->willReturnCallback(
			function (string $credentialId, string $appId, string $method, string $path, array $headers = [], ?string $body = null, ?string $actingUserId = null) use ($broker): array {
				$this->brokered[] = [$path, $appId, $actingUserId];
				if ($broker instanceof RuntimeException) {
					throw $broker;
				}

				return ['status' => $broker[0], 'body' => $broker[1]];
			}
		);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url) use ($anonymous): IResponse {
				$this->anonymous[] = $url;
				if ($anonymous instanceof RuntimeException) {
					throw $anonymous;
				}

				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn($anonymous[0]);
				$response->method('getBody')->willReturn($anonymous[1]);
				return $response;
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		return new FederatedConfigService(
			new FederatedConfigTransport($brokerService, $this->createMock(BundleSigner::class), $clients),
			$this->createMock(ShareableConfigTypeRegistry::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class)
		);
	}
}
