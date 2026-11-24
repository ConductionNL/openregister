<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Oas;

use DateTime;
use OCA\OpenRegister\Service\Oas\NlGovResponseProbe;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The response probe: bounded self-requests, judged per response rule, kept per register.
 *
 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
 */
#[CoversClass(NlGovResponseProbe::class)]
class NlGovResponseProbeTest extends TestCase {

	/** @var list<array{string, array<string, mixed>}> */
	private array $requests = [];

	/** @var array<string, string> */
	private array $stored = [];

	/**
	 * A register document with a collection and an item path.
	 *
	 * @return array<string, mixed>
	 */
	private static function oas(): array {
		return [
			'servers' => [['url' => 'https://nc.example/index.php/apps/openregister/api/']],
			'paths' => [
				'/objects/reg/{schema}/{id}' => ['get' => []],
				'/objects/reg/case' => ['post' => [], 'get' => []],
				'/objects/reg/case/{id}' => ['get' => [], 'delete' => []],
			],
		];
	}

	/**
	 * A probe whose client answers a collection read and an error read with the given headers.
	 *
	 * @param array<string, string> $collectionHeaders Headers of the collection response.
	 * @param string                $body              Body of the collection response.
	 * @param string                $errorType         Content-Type of the error response.
	 * @param string                $auth              The caller's Authorization header.
	 *
	 * @return NlGovResponseProbe
	 */
	private function probe(array $collectionHeaders, string $body = '{"results":[]}', string $errorType = 'application/problem+json', string $auth = ''): NlGovResponseProbe {
		$collection = $this->response(status: 200, headers: $collectionHeaders, body: $body);
		$error      = $this->response(status: 404, headers: ['Content-Type' => $errorType], body: '{}');

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url, array $options) use ($collection, $error): IResponse {
				$this->requests[] = [$url, $options];
				return str_contains($url, NlGovResponseProbe::NIL_UUID) ? $error : $collection;
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$config = $this->createMock(IAppConfig::class);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored[$app.'/'.$key] = $value;
				return true;
			}
		);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->stored[$app.'/'.$key] ?? $default)
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-24T12:00:00+00:00'));

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnMap([['Authorization', $auth]]);

		return new NlGovResponseProbe($clients, $config, $time, $request);
	}

	/**
	 * A response with the given status, headers and body.
	 *
	 * @param int                   $status  The status.
	 * @param array<string, string> $headers The headers.
	 * @param string                $body    The body.
	 *
	 * @return IResponse
	 */
	private function response(int $status, array $headers, string $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);
		$response->method('getHeader')->willReturnCallback(fn (string $name): string => ($headers[$name] ?? ''));
		return $response;
	}

	/**
	 * Every mandatory security header, set right.
	 *
	 * @return array<string, string>
	 */
	private static function secure(string $version = '1.2.0'): array {
		return [
			'API-Version' => $version,
			'Cache-Control' => 'no-store',
			'Content-Security-Policy' => "frame-ancestors 'none'",
			'Strict-Transport-Security' => 'max-age=31536000',
			'X-Content-Type-Options' => 'nosniff',
			'X-Frame-Options' => 'DENY',
		];
	}

	public function testTheProbeStaysWithinTheRequestCapAndBoundsEveryRequest(): void {
		$result = $this->probe(collectionHeaders: self::secure())->probe(registerId: '7', oas: self::oas());

		$this->assertSame(2, $result['requests']);
		$this->assertCount(2, $this->requests);
		$this->assertLessThanOrEqual(NlGovResponseProbe::MAX_REQUESTS, count($this->requests));
		$this->assertSame('https://nc.example/index.php/apps/openregister/api/objects/reg/case', $this->requests[0][0]);
		$this->assertSame('https://nc.example/index.php/apps/openregister/api/objects/reg/case/'.NlGovResponseProbe::NIL_UUID, $this->requests[1][0]);
		foreach ($this->requests as [, $options]) {
			$this->assertSame(NlGovResponseProbe::TIMEOUT_SECONDS, $options['timeout']);
			$this->assertFalse($options['allow_redirects']);
			$this->assertFalse($options['http_errors']);
		}
	}

	public function testADigitsOnlyVersionHeaderFailsTheRule(): void {
		$result = $this->probe(collectionHeaders: self::secure(version: '1'))->probe(registerId: '7', oas: self::oas());

		$this->assertSame('fail', $result['rules']['/core/version-header']['result']);
		$this->assertStringContainsString('"1"', $result['rules']['/core/version-header']['findings'][0]['message']);
	}

	public function testAWellFormedResponsePassesEveryResponseRule(): void {
		$result = $this->probe(collectionHeaders: self::secure(), body: '{"results":[{"created":"2026-01-02T03:04:05+00:00","due":"2026-02-01"}]}')
			->probe(registerId: '7', oas: self::oas());

		foreach (['/core/version-header', '/core/transport/security-headers', '/core/date-time/timezone', '/core/error-handling/problem-details'] as $rule) {
			$this->assertSame('pass', $result['rules'][$rule]['result'], $rule);
			$this->assertSame([], $result['rules'][$rule]['findings'], $rule);
		}
	}

	public function testMissingAndWrongSecurityHeadersAreNamed(): void {
		$headers = self::secure();
		unset($headers['Strict-Transport-Security']);
		$headers['X-Content-Type-Options'] = 'sniff';

		$rule = $this->probe(collectionHeaders: $headers)->probe(registerId: '7', oas: self::oas())['rules']['/core/transport/security-headers'];

		$this->assertSame('fail', $rule['result']);
		$this->assertCount(2, $rule['findings']);
		$this->assertStringContainsString('Strict-Transport-Security', $rule['findings'][0]['message']);
		$this->assertStringContainsString('X-Content-Type-Options', $rule['findings'][1]['message']);
	}

	public function testADateTimeWithoutAnOffsetFailsTheTimezoneRule(): void {
		$rule = $this->probe(collectionHeaders: self::secure(), body: '{"results":[{"updated":"2026-01-02T03:04:05"},{"updated":"2026-01-02T03:04:05Z"}]}')
			->probe(registerId: '7', oas: self::oas())['rules']['/core/date-time/timezone'];

		$this->assertSame('fail', $rule['result']);
		$this->assertStringContainsString('1 date-time values', $rule['findings'][0]['message']);
	}

	public function testAnErrorServedAsPlainJsonFailsProblemDetails(): void {
		$rule = $this->probe(collectionHeaders: self::secure(), errorType: 'application/json; charset=utf-8')
			->probe(registerId: '7', oas: self::oas())['rules']['/core/error-handling/problem-details'];

		$this->assertSame('fail', $rule['result']);
		$this->assertStringContainsString('404', $rule['findings'][0]['message']);
	}

	public function testTheResultIsKeptPerRegisterAndReadBack(): void {
		$probe  = $this->probe(collectionHeaders: self::secure());
		$result = $probe->probe(registerId: '7', oas: self::oas());

		$this->assertArrayHasKey('openregister/nlgov_probe_7', $this->stored);
		$this->assertSame('2026-11-24T12:00:00+00:00', $result['probedAt']);
		$this->assertSame($result, $probe->lastResult(registerId: '7'));
		$this->assertNull($probe->lastResult(registerId: '8'));
	}

	public function testTheCallersAuthorizationIsPassedOn(): void {
		$this->probe(collectionHeaders: self::secure(), auth: 'Basic YWRtaW46c2VjcmV0')->probe(registerId: '7', oas: self::oas());

		$this->assertSame('Basic YWRtaW46c2VjcmV0', $this->requests[0][1]['headers']['Authorization']);
	}

	public function testADocumentWithoutPathsFailsTheResponseRulesWithoutARequest(): void {
		$result = $this->probe(collectionHeaders: self::secure())->probe(registerId: '7', oas: ['servers' => [['url' => 'https://nc.example']], 'paths' => []]);

		$this->assertSame(0, $result['requests']);
		$this->assertSame('fail', $result['rules']['/core/version-header']['result']);
	}

	public function testFullVersionsAreRecognised(): void {
		$this->assertTrue(NlGovResponseProbe::isFullVersion(value: '1.0.0'));
		$this->assertTrue(NlGovResponseProbe::isFullVersion(value: '2.3.4-beta.1'));
		$this->assertFalse(NlGovResponseProbe::isFullVersion(value: '1'));
		$this->assertFalse(NlGovResponseProbe::isFullVersion(value: '20261124'));
		$this->assertFalse(NlGovResponseProbe::isFullVersion(value: '1.0'));
	}
}
