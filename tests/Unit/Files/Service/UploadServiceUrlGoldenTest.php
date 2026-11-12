<?php

/**
 * UploadServiceUrlGoldenTest
 *
 * Pins the URL source of UploadService::getUploadedJson(): the SSRF refusals
 * before any request, the single GET without redirects, the content-type
 * driven JSON / YAML parsing, and every error response.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Files\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OCA\OpenRegister\Files\Service\UploadService;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;

/**
 * Golden outcomes of the URL upload source.
 */
final class UploadServiceUrlGoldenTest extends TestCase {

	/**
	 * The requests the client sent, with their options.
	 *
	 * @var list<array>
	 */
	private array $sent = [];

	/**
	 * Fetch the URL through a client answering with the given responses.
	 *
	 * @param string $url       The URL.
	 * @param array  $responses The queued responses.
	 *
	 * @return array|JSONResponse
	 */
	private function fetch(string $url, array $responses = []): array|JSONResponse {
		$stack = HandlerStack::create(new MockHandler($responses));
		$stack->push(Middleware::history($this->sent));
		return (new UploadService(new Client(['handler' => $stack])))->getUploadedJson(['url' => $url]);
	}//end fetch()

	/**
	 * The status and body of an error response.
	 *
	 * @param array|JSONResponse $result The result.
	 *
	 * @return array
	 */
	private static function error(array|JSONResponse $result): array {
		self::assertInstanceOf(JSONResponse::class, $result);
		return [$result->getStatus(), $result->getData()];
	}//end error()

	/**
	 * Non-public and malformed URLs are refused before any request.
	 *
	 * @return void
	 */
	public function testRefusedUrls(): void {
		self::assertSame(
			[400, ['error' => 'URL is not allowed: URL resolves to a non-public address and cannot be fetched.']],
			self::error($this->fetch('http://127.0.0.1/config.json'))
		);
		self::assertSame(
			[400, ['error' => 'URL is not allowed: Only http and https URLs are allowed.']],
			self::error($this->fetch('ftp://93.184.216.34/config.json'))
		);
		self::assertSame([], $this->sent);
	}//end testRefusedUrls()

	/**
	 * JSON, YAML and sniffed bodies parse; the source URL is added; one GET without redirects.
	 *
	 * @return void
	 */
	public function testParsedBodies(): void {
		$url = 'http://93.184.216.34/config';
		self::assertSame(['a' => 1, 'source' => $url], $this->fetch($url, [new Response(200, ['Content-Type' => 'application/json'], '{"a":1}')]));
		self::assertSame(['b' => 2, 'source' => $url], $this->fetch($url, [new Response(200, ['Content-Type' => 'application/yaml'], "b: 2\n")]));
		self::assertSame(['c' => 3, 'source' => $url], $this->fetch($url, [new Response(200, [], '{"c":3}')]));
		self::assertSame(['d' => 4, 'source' => $url], $this->fetch($url, [new Response(200, ['Content-Type' => 'text/plain'], "d: 4\n")]));

		self::assertCount(4, $this->sent);
		foreach ($this->sent as $exchange) {
			self::assertSame('GET', $exchange['request']->getMethod());
			self::assertSame($url, (string)$exchange['request']->getUri());
			self::assertFalse($exchange['options']['allow_redirects']);
		}
	}//end testParsedBodies()

	/**
	 * A failing request and an unparseable body are 400s.
	 *
	 * @return void
	 */
	public function testErrors(): void {
		$url = 'http://93.184.216.34/config';
		[$status, $data] = self::error($this->fetch($url, [new Response(404, [], 'gone')]));
		self::assertSame(400, $status);
		self::assertStringStartsWith('Failed to do a GET api-call on url: '.$url.' Client error: `GET '.$url.'` resulted in a `404 Not Found`', $data['error']);

		self::assertSame(
			[400, ['error' => 'Failed to parse response body as JSON or YAML.']],
			self::error($this->fetch($url, [new Response(200, ['Content-Type' => 'application/json'], 'not json')]))
		);
		self::assertSame(
			[400, ['error' => 'Failed to parse response body as JSON or YAML.']],
			self::error($this->fetch($url, [new Response(200, ['Content-Type' => 'application/yaml'], '')]))
		);
	}//end testErrors()
}//end class
