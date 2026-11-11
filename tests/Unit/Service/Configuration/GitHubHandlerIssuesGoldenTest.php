<?php

/**
 * Golden test for GitHubHandler::listIssues.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Service\Configuration\AttributionFormatter;
use OCA\OpenRegister\Service\Configuration\GitHubHandler;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\GitHubIssueSelection;

/**
 * Pins every request listIssues sends and the list it returns, for no label,
 * one label and an OR-merge over several labels, with and without a token.
 *
 * @covers \OCA\OpenRegister\Service\Configuration\GitHubHandler
 * @covers \OCA\OpenRegister\Service\Configuration\GitHubIssueQuery
 */
class GitHubHandlerIssuesGoldenTest extends TestCase {

	/**
	 * Raw issues per label filter ('' = none).
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private const PAGES = [
		'' => [
			['number' => 1, 'title' => 'One', 'body' => 'b1', 'html_url' => 'u1', 'user' => ['login' => 'a', 'avatar_url' => 'av', 'id' => 9], 'reactions' => ['total_count' => 2, '+1' => 1, 'heart' => 1], 'created_at' => 'c', 'updated_at' => 'u', 'labels' => [['name' => 'bug', 'color' => 'red', 'id' => 4], 'junk'], 'email' => 'x@y'],
			['number' => 2, 'title' => 'PR', 'pull_request' => ['url' => 'p']],
			'not an issue',
		],
		'bug' => [
			['number' => 3, 'title' => 'Three', 'reactions' => ['total_count' => 1]],
			['number' => 4, 'title' => 'Four', 'reactions' => ['total_count' => 7]],
		],
		'ux' => [
			['number' => 4, 'title' => 'Four again', 'reactions' => ['total_count' => 7]],
			['number' => 5, 'title' => 'Five', 'reactions' => ['total_count' => 5]],
			['number' => 0, 'title' => 'No number'],
		],
	];

	/**
	 * Run listIssues and return its result plus every request it sent.
	 *
	 * @param string $token The configured app token.
	 * @param array $args The listIssues arguments.
	 *
	 * @return array{result: array, requests: array<int, array>}
	 */
	private function listWith(string $token, array $args): array {
		$requests = [];
		$client = $this->createMock(IClient::class);
		$client->method('request')->willReturnCallback(
			function (string $method, string $url, array $options) use (&$requests): IResponse {
				$requests[] = [$method, $url, $options];
				$response = $this->createMock(IResponse::class);
				$response->method('getBody')->willReturn((string)json_encode(self::PAGES[(string)($options['query']['labels'] ?? '')]));
				return $response;
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->with('openregister', 'github_api_token', '')->willReturn($token);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));
		$handler = new GitHubHandler(
			client: $client,
			appConfig: $appConfig,
			config: $this->createMock(IConfig::class),
			cacheFactory: $cacheFactory,
			attributionFormatter: $this->createMock(AttributionFormatter::class),
			logger: $this->createMock(LoggerInterface::class),
		);

		return ['result' => $handler->listIssues(selection: new GitHubIssueSelection(...$args)), 'requests' => $requests];
	}//end listWith()

	/**
	 * Cases: token, listIssues arguments.
	 *
	 * @return array<string, array{0: string, 1: array}>
	 */
	public static function cases(): array {
		return [
			'defaults, no token' => ['', ['owner' => 'Con duction', 'repo' => 'open/register']],
			'no labels, token' => ['pat', ['owner' => 'o', 'repo' => 'r', 'state' => 'all', 'sort' => 'created', 'perPage' => 5, 'labels' => null]],
			'blank labels only' => ['pat', ['owner' => 'o', 'repo' => 'r', 'labels' => ['', '']]],
			'one label' => ['', ['owner' => 'o', 'repo' => 'r', 'labels' => ['', 'bug']]],
			'two labels merged' => ['pat', ['owner' => 'o', 'repo' => 'r', 'state' => 'closed', 'perPage' => 2, 'labels' => ['bug', 'ux']]],
			'two labels, room for all' => ['', ['owner' => 'o', 'repo' => 'r', 'labels' => ['ux', 'bug']]],
		];
	}//end cases()

	/**
	 * The result and requests equal the recording.
	 *
	 * @param string $token The token.
	 * @param array $args The arguments.
	 *
	 * @dataProvider cases
	 *
	 * @return void
	 */
	public function testListIssuesMatchesTheRecording(string $token, array $args): void {
		$actual = $this->listWith(token: $token, args: $args);
		$golden = json_decode((string)file_get_contents(__DIR__ . '/fixtures/github-issues-golden.json'), true);

		$this->assertSame($golden[$this->dataName()], $actual);
	}//end testListIssuesMatchesTheRecording()
}//end class
