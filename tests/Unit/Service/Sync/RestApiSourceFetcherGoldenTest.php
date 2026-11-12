<?php

/**
 * Golden test for RestApiSourceFetcher gather, fetch and request headers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Sync
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Sync;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Service\Sync\RestApiSourceFetcher;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Each case gathers ids from (or fetches one record of) a scripted REST
 * source and records the result or the error, every request (url, headers,
 * options) and every decrypt call. Compared with
 * fixtures/rest-api-source-fetcher-golden.json; set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Service\Sync\RestApiSourceFetcher
 */
class RestApiSourceFetcherGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/rest-api-source-fetcher-golden.json';

	private const BASE = 'https://example.test/api/records';

	/**
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function cases(): array {
		$page = static fn (array $body, int $status = 200, array $headers = []): array => [$status, $headers, (string) json_encode($body)];
		$two = ['next' => self::BASE . '?page=2'];
		return [
			'no url'                     => ['gather', ['url' => '']],
			'results list'               => ['gather', ['pages' => [self::BASE => $page(['results' => [['id' => 1], ['id' => 'a'], ['name' => 'x'], 'scalar', ['id' => null], ['id' => 1]]])]]],
			'items key'                  => ['gather', ['pages' => [self::BASE => $page(['items' => ['k' => ['id' => 'i1']]])]]],
			'data key'                   => ['gather', ['pages' => [self::BASE => $page(['data' => [['id' => 'd1']], 'results' => 'not-a-list'])]]],
			'value key'                  => ['gather', ['pages' => [self::BASE => $page(['value' => [['id' => 'v1']]])]]],
			'bare list'                  => ['gather', ['pages' => [self::BASE => $page([['id' => 'b1'], ['id' => 'b2']])]]],
			'object without list'        => ['gather', ['pages' => [self::BASE => $page(['total' => 3])]]],
			'not json'                   => ['gather', ['pages' => [self::BASE => [200, [], 'nope']]]],
			'scalar json'                => ['gather', ['pages' => [self::BASE => [200, [], '5']]]],
			'not modified'               => ['gather', ['pages' => [self::BASE => [304, [], '']]]],
			'next top level'             => ['gather', ['pages' => [self::BASE => $page(['results' => [['id' => 1]]] + $two), self::BASE . '?page=2' => $page(['results' => [['id' => 2], ['id' => 1]]])]]],
			'next empty string'          => ['gather', ['pages' => [self::BASE => $page(['results' => [['id' => 1]], 'next' => ''])]]],
			'next not a string'          => ['gather', ['pages' => [self::BASE => $page(['results' => [['id' => 1]], 'next' => 2])]]],
			'links next href'            => ['gather', ['pages' => [self::BASE => $page(['_links' => ['next' => ['href' => self::BASE . '?p=2']], 'next' => 'ignored', 'items' => [['id' => 1]]]), self::BASE . '?p=2' => $page(['items' => [['id' => 3]]])]]],
			'links next numeric href'    => ['gather', ['pages' => [self::BASE => $page(['_links' => ['next' => ['href' => 7]], 'items' => [['id' => 1]]]), '7' => $page([])]]],
			'link header next'           => ['gather', ['pages' => [self::BASE => $page(['items' => [['id' => 1]]], 200, ['Link' => '<' . self::BASE . '?h=2>; rel="next", <x>; rel="last"']), self::BASE . '?h=2' => $page(['items' => [['id' => 4]]])]]],
			'link header without next'   => ['gather', ['pages' => [self::BASE => $page(['items' => [['id' => 1]]], 200, ['Link' => '<x>; rel="prev"'])]]],
			'second page not modified'   => ['gather', ['pages' => [self::BASE => $page(['results' => [['id' => 1]]] + $two), self::BASE . '?page=2' => [304, [], '']]]],
			'second page not json'       => ['gather', ['pages' => [self::BASE => $page(['results' => [['id' => 1]]] + $two), self::BASE . '?page=2' => [200, [], '<html>']]]],
			'page loop is bounded'       => ['gather', ['loop' => true]],
			'apikey default header'      => ['gather', ['authType' => 'apikey', 'auth' => ['key' => 'enc:secret']]],
			'apikey custom header'       => ['gather', ['authType' => 'apikey', 'auth' => ['header' => 'X-Token', 'key' => 'plain']]],
			'apikey header Accept'       => ['gather', ['authType' => 'apikey', 'auth' => ['header' => 'Accept', 'key' => 'k']]],
			'apikey numeric header'      => ['gather', ['authType' => 'apikey', 'auth' => ['header' => '123', 'key' => 'k']]],
			'apikey no key'              => ['gather', ['authType' => 'apikey', 'auth' => []]],
			'basic auth'                 => ['gather', ['authType' => 'basic', 'auth' => ['username' => 'enc:bob', 'password' => 'enc:pw', 'extra' => 5, 'empty' => '']]],
			'basic auth missing parts'   => ['gather', ['authType' => 'basic', 'auth' => ['username' => 'u']]],
			'unknown auth type'          => ['gather', ['authType' => 'oauth2', 'auth' => ['key' => 'enc:x']]],
			'no auth type'               => ['gather', ['authType' => null, 'auth' => null]],
			'since header'               => ['gather', ['since' => '2026-03-04T05:06:07+02:00']],
			'since unparseable'          => ['gather', ['since' => 'not a date']],
			'since and apikey'           => ['gather', ['since' => '2026-01-01T00:00:00Z', 'authType' => 'apikey', 'auth' => ['header' => 'If-Modified-Since', 'key' => 'k']]],
			'fetch record'               => ['fetch', ['id' => 'a b/1', 'pages' => [self::BASE . '/a%20b%2F1' => $page(['id' => 'a b/1', 'name' => 'x'])]]],
			'fetch trailing slash base'  => ['fetch', ['url' => self::BASE . '/', 'id' => '9', 'pages' => [self::BASE . '/9' => $page(['id' => 9])]]],
			'fetch not json'             => ['fetch', ['id' => '9', 'pages' => [self::BASE . '/9' => [200, [], 'x']]]],
			'fetch with basic auth'      => ['fetch', ['id' => '9', 'authType' => 'basic', 'auth' => ['username' => 'u', 'password' => 'enc:p'], 'pages' => [self::BASE . '/9' => $page([])]]],
		];
	}

	/**
	 * @param string               $operation gather or fetch.
	 * @param array<string, mixed> $scenario  The scripted source and answers.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testOperation(string $operation, array $scenario): void {
		$record = ['requests' => [], 'decrypted' => []];

		$client = $this->createMock(Client::class);
		$client->method('request')->willReturnCallback(
			static function (string $method, string $url, array $options) use ($scenario, &$record): Response {
				$record['requests'][] = [$method, $url, $options];
				if (($scenario['loop'] ?? false) === true) {
					return new Response(200, [], (string) json_encode(['items' => [['id' => 'same']], 'next' => $url]));
				}

				[$status, $headers, $body] = ($scenario['pages'][$url] ?? [404, [], '{"error":"not scripted"}']);
				return new Response($status, $headers, $body);
			}
		);

		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willReturnCallback(
			static function (string $value) use (&$record): string {
				$record['decrypted'][] = $value;
				if (str_starts_with($value, 'enc:') === false) {
					throw new \RuntimeException('not encrypted');
				}

				return 'dec(' . substr($value, 4) . ')';
			}
		);

		$source = new Source();
		$source->setDatabaseUrl(($scenario['url'] ?? self::BASE));
		$source->setAuthType((array_key_exists('authType', $scenario) === true) ? $scenario['authType'] : 'none');
		$source->setAuthConfig((array_key_exists('auth', $scenario) === true) ? $scenario['auth'] : []);

		$fetcher = new RestApiSourceFetcher($client, $crypto, $this->createMock(LoggerInterface::class));
		try {
			$outcome = match ($operation) {
				'gather' => $fetcher->gather($source, ($scenario['since'] ?? null)),
				default => $fetcher->fetch($source, $scenario['id']),
			};
		} catch (\Throwable $e) {
			$outcome = ['thrown' => get_class($e), 'message' => $e->getMessage()];
		}

		if (($scenario['loop'] ?? false) === true) {
			$record['requests'] = ['count' => count($record['requests']), 'first' => $record['requests'][0]];
		}

		$actual = json_decode((string) json_encode(['outcome' => $outcome, 'record' => $record]), true);
		$case = (string) $this->dataName();
		$golden = [];
		if (file_exists(self::FIXTURE) === true) {
			$golden = json_decode((string) file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$case] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($case, $golden, 'No golden entry; record with UPDATE_GOLDEN=1.');
		$this->assertSame($golden[$case], $actual);
	}
}
