<?php

declare(strict_types=1);

/**
 * Golden of a GitHub store search across several topics: the worst outcome
 * wins, a repository carrying two topics is one card, and the answer is capped.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Store\Source
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

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
 * Pins how answers for several topics combine into one search answer.
 */
class GitHubStoreSourceTopicsGoldenTest extends TestCase {

	public function testCardsOfEveryTopicAreMergedOncePerRepository(): void {
		$result = $this->search(
			answers: [
				't-a' => [200, ['o/one', 'o/both']],
				't-b' => [200, ['o/both', 'o/two']],
			]
		);

		$this->assertSame(GenericStoreService::OUTCOME_OK, $result['outcome']);
		$this->assertSame(['o/one', 'o/both', 'o/two'], array_column($result['cards'], 'slug'));
		$this->assertSame(['t-a', 't-b', 't-b'], array_column($result['cards'], 'kind'));
	}//end testCardsOfEveryTopicAreMergedOncePerRepository()

	public function testTheWorstOutcomeWinsAndTheOtherTopicsStillCount(): void {
		$result = $this->search(
			answers: [
				't-a' => [200, ['o/one']],
				't-b' => [500, []],
				't-c' => [200, ['o/three']],
			]
		);

		$this->assertSame(GenericStoreService::OUTCOME_UNREACHABLE, $result['outcome']);
		$this->assertSame(['o/one', 'o/three'], array_column($result['cards'], 'slug'));
	}//end testTheWorstOutcomeWinsAndTheOtherTopicsStillCount()

	public function testTheAnswerIsCappedAtThirtyCards(): void {
		$first  = array_map(static fn (int $i): string => 'o/a' . $i, range(1, 20));
		$second = array_map(static fn (int $i): string => 'o/b' . $i, range(1, 20));

		$result = $this->search(answers: ['t-a' => [200, $first], 't-b' => [200, $second]]);

		$this->assertCount(30, $result['cards']);
		$this->assertSame('o/b10', $result['cards'][29]['slug']);
	}//end testTheAnswerIsCappedAtThirtyCards()

	/**
	 * Search every declared topic with no kind filter.
	 *
	 * @param array<string, array{0: int, 1: array<int, string>}> $answers Topic → status and repository names.
	 *
	 * @return array{outcome: string, cards: array}
	 */
	private function search(array $answers): array {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url, array $options) use ($answers): IResponse {
				$topic          = substr((string)$options['query']['q'], strlen('topic:'));
				[$status, $names] = $answers[trim($topic)];
				$items          = array_map(static fn (string $name): array => ['full_name' => $name, 'name' => $name], $names);

				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn($status);
				$response->method('getBody')->willReturn(json_encode(['items' => $items]));
				return $response;
			}
		);

		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);
		$cache = $this->createMock(ICacheFactory::class);
		$cache->method('isAvailable')->willReturn(false);

		$manifest = StoreManifest::fromManifest('demo', ['store' => ['source' => 'github', 'topics' => array_keys($answers)]]);

		return (new GitHubStoreSource($clients, $cache, $this->createMock(LoggerInterface::class)))->search($manifest, '', null);
	}//end search()
}//end class
