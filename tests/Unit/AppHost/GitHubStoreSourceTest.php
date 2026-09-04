<?php

/**
 * Tests for the GitHub store discovery source.
 *
 * The assertions worth having here are the ones that keep a reader correctly
 * informed when the source is NOT working:
 *
 *   - a rate limit must report itself, never `store_unreachable`, because the
 *     remedy differs and someone acts on it;
 *   - a failure must still be cached, or the next keystroke walks straight
 *     back into the limit that just fired;
 *   - a repeated query inside the window must issue no second request.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
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

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Service\GenericStoreService;
use OCA\OpenRegister\AppHost\Store\Source\GitHubStoreSource;
use OCA\OpenRegister\AppHost\Store\StoreManifest;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\AppHost\Store\Source\GitHubStoreSource
 * @uses   \OCA\OpenRegister\AppHost\Store\StoreManifest
 */
class GitHubStoreSourceTest extends TestCase {
	/**
	 * A store block declaring a github source with one topic.
	 *
	 * @return StoreManifest
	 */
	private function manifest(): StoreManifest {
		return StoreManifest::fromManifest('demo', [
			'store' => ['source' => 'github', 'topics' => ['demo-app']],
		]);
	}

	/**
	 * Build a source whose HTTP client returns the given response.
	 *
	 * @param IResponse|Throwable $result       What the client does.
	 * @param ICache|null         $cache        Cache to use, or null for none.
	 * @param int                 $expectedGets How many GETs are allowed.
	 *
	 * @return GitHubStoreSource
	 */
	private function sourceReturning($result, ?ICache $cache, int $expectedGets): GitHubStoreSource {
		$client = $this->createMock(IClient::class);
		$invocation = $client->expects($this->exactly($expectedGets))->method('get');
		if ($result instanceof \Throwable) {
			$invocation->willThrowException($result);
		} else {
			$invocation->willReturn($result);
		}

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('isAvailable')->willReturn($cache !== null);
		if ($cache !== null) {
			$cacheFactory->method('createDistributed')->willReturn($cache);
		}

		return new GitHubStoreSource(
			$clientService,
			$cacheFactory,
			$this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * Build a response double.
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Response body.
	 *
	 * @return IResponse
	 */
	private function response(int $status, string $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);
		return $response;
	}

	/**
	 * The happy path normalises repositories into cards.
	 *
	 * @return void
	 */
	public function testSearchReturnsNormalisedCards(): void {
		$body = json_encode([
			'items' => [
				[
					'full_name' => 'conduction/demo-app',
					'name' => 'demo-app',
					'description' => 'A demo',
					'default_branch' => 'main',
					'owner' => ['login' => 'conduction'],
					'stargazers_count' => 7,
					'html_url' => 'https://github.com/conduction/demo-app',
				],
			],
		]);

		$source = $this->sourceReturning($this->response(200, $body), null, 1);
		$result = $source->search($this->manifest(), '', null);

		$this->assertSame(GenericStoreService::OUTCOME_OK, $result['outcome']);
		$this->assertCount(1, $result['cards']);
		$this->assertSame('conduction/demo-app', $result['cards'][0]['slug']);
		$this->assertSame(7, $result['cards'][0]['stars']);
	}

	/**
	 * 🔴 The distinction the reader acts on: 403 is rate limiting, not an
	 * unreachable registry.
	 *
	 * @return void
	 */
	public function testRateLimitIsReportedAsItselfNotAsUnreachable(): void {
		$source = $this->sourceReturning($this->response(403, '{}'), null, 1);
		$result = $source->search($this->manifest(), '', null);

		$this->assertSame(
			GenericStoreService::OUTCOME_RATE_LIMITED,
			$result['outcome'],
			'A 403 must not be reported as store_unreachable: the remedy differs.'
		);
		$this->assertSame([], $result['cards']);
	}

	/**
	 * A thrown connection error stays unreachable.
	 *
	 * @return void
	 */
	public function testConnectionFailureIsUnreachable(): void {
		$source = $this->sourceReturning(new RuntimeException('connect'), null, 1);
		$result = $source->search($this->manifest(), '', null);

		$this->assertSame(GenericStoreService::OUTCOME_UNREACHABLE, $result['outcome']);
	}

	/**
	 * A 200 carrying nonsense is invalid, not unreachable.
	 *
	 * @return void
	 */
	public function testUnparseableBodyIsInvalid(): void {
		$source = $this->sourceReturning($this->response(200, 'not json'), null, 1);
		$result = $source->search($this->manifest(), '', null);

		$this->assertSame(GenericStoreService::OUTCOME_INVALID, $result['outcome']);
	}

	/**
	 * A cached answer must suppress the upstream request entirely.
	 *
	 * The mock allows ZERO gets, so a request would fail the test rather than
	 * merely be slower.
	 *
	 * @return void
	 */
	public function testACachedAnswerIssuesNoRequest(): void {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn(json_encode([
			'outcome' => GenericStoreService::OUTCOME_OK,
			'cards' => [['slug' => 'conduction/cached']],
		]));

		$source = $this->sourceReturning($this->response(200, '{}'), $cache, 0);
		$result = $source->search($this->manifest(), '', null);

		$this->assertSame('conduction/cached', $result['cards'][0]['slug']);
	}

	/**
	 * 🔴 Failures are cached too, or a brief limit becomes a persistent one.
	 *
	 * @return void
	 */
	public function testARateLimitedAnswerIsCached(): void {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn(null);
		$cache->expects($this->once())
			->method('set')
			->with(
				$this->anything(),
				$this->stringContains(GenericStoreService::OUTCOME_RATE_LIMITED),
				$this->anything()
			);

		$source = $this->sourceReturning($this->response(403, '{}'), $cache, 1);
		$source->search($this->manifest(), '', null);
	}

	/**
	 * A kind that maps to no topic finds nothing, rather than everything.
	 *
	 * @return void
	 */
	public function testAnUnknownKindNarrowsToNothing(): void {
		$manifest = StoreManifest::fromManifest('demo', [
			'store' => [
				'source' => 'github',
				'topics' => ['demo-app'],
				'kinds' => ['app'],
			],
		]);

		$source = $this->sourceReturning($this->response(200, '{}'), null, 0);
		$result = $source->search($manifest, '', 'not-a-kind');

		$this->assertSame(GenericStoreService::OUTCOME_OK, $result['outcome']);
		$this->assertSame([], $result['cards']);
	}
}
