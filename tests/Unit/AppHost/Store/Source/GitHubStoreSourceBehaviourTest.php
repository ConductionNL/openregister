<?php

/**
 * Behaviour tests for GitHubStoreSource's kind narrowing, query building and status mapping.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Store\Source
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Store\Source;

use OCA\OpenRegister\AppHost\Service\GenericStoreService;
use OCA\OpenRegister\AppHost\Store\Source\GitHubStoreSource;
use OCA\OpenRegister\AppHost\Store\StoreManifest;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Kind-to-topic mapping, the search query, empty topics and non-2xx statuses.
 */
class GitHubStoreSourceBehaviourTest extends TestCase {

	/**
	 * A source without a cache over the given client.
	 *
	 * @param IClient $client The HTTP client.
	 *
	 * @return GitHubStoreSource
	 */
	private function source(IClient $client): GitHubStoreSource {
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('isAvailable')->willReturn(false);
		return new GitHubStoreSource($clientService, $cacheFactory, $this->createMock(LoggerInterface::class));
	}//end source()

	/**
	 * A response double.
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Body.
	 *
	 * @return IResponse
	 */
	private function response(int $status, string $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);
		return $response;
	}//end response()

	/**
	 * The source names itself "github".
	 *
	 * @return void
	 */
	public function testSourceIdIsGithub(): void {
		$this->assertSame('github', $this->source($this->createMock(IClient::class))->sourceId());
	}//end testSourceIdIsGithub()

	/**
	 * A known kind searches only its own topic, and the user query is appended.
	 *
	 * @return void
	 */
	public function testAKnownKindSearchesItsTopicWithTheQuery(): void {
		$manifest = StoreManifest::fromManifest('demo', [
			'store' => ['source' => 'github', 'topics' => ['t-app', 't-lib'], 'kinds' => ['app', 'lib']],
		]);

		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('get')
			->with(
				'https://api.github.com/search/repositories',
				$this->callback(static fn (array $o): bool => $o['query']['q'] === 'topic:t-lib parser')
			)
			->willReturn($this->response(200, json_encode(['items' => [['full_name' => 'o/r', 'name' => 'r']]])));

		$result = $this->source($client)->search($manifest, 'parser', 'lib');

		$this->assertSame(GenericStoreService::OUTCOME_OK, $result['outcome']);
		$this->assertSame('o/r', $result['cards'][0]['slug']);
		$this->assertSame('t-lib', $result['cards'][0]['kind']);
	}//end testAKnownKindSearchesItsTopicWithTheQuery()

	/**
	 * A kind on a store with no topics maps to the empty topic, which is skipped:
	 * no request is made and the answer is an empty ok.
	 *
	 * @return void
	 */
	public function testAnEmptyTopicIsSkippedWithoutARequest(): void {
		$manifest = StoreManifest::fromManifest('demo', [
			'store' => ['schema' => 'item', 'kinds' => ['app']],
		]);

		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('get');

		$this->assertSame(
			['outcome' => GenericStoreService::OUTCOME_OK, 'cards' => []],
			$this->source($client)->search($manifest, '', 'app')
		);
	}//end testAnEmptyTopicIsSkippedWithoutARequest()

	/**
	 * A 5xx from GitHub is unreachable, not rate-limited and not ok.
	 *
	 * @return void
	 */
	public function testAServerErrorIsUnreachable(): void {
		$manifest = StoreManifest::fromManifest('demo', ['store' => ['source' => 'github', 'topics' => ['t-app']]]);

		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method('get')->willReturn($this->response(502, '{"items":[]}'));

		$this->assertSame(
			['outcome' => GenericStoreService::OUTCOME_UNREACHABLE, 'cards' => []],
			$this->source($client)->search($manifest, '', null)
		);
	}//end testAServerErrorIsUnreachable()

	/**
	 * A redirect (not followed) is unreachable too: the 2xx window is closed at 300.
	 *
	 * @return void
	 */
	public function testARedirectIsUnreachable(): void {
		$manifest = StoreManifest::fromManifest('demo', ['store' => ['source' => 'github', 'topics' => ['t-app']]]);

		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method('get')->willReturn($this->response(301, '{"items":[]}'));

		$this->assertSame(
			GenericStoreService::OUTCOME_UNREACHABLE,
			$this->source($client)->search($manifest, '', null)['outcome']
		);
	}//end testARedirectIsUnreachable()
}//end class
