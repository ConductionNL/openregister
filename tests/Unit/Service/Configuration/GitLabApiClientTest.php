<?php

/**
 * The GitLab API client: one HTTP client, the configured API base, and GET
 * requests that carry the token header and return the decoded JSON body.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Service\Configuration\GitLabApiClient;
use OCA\OpenRegister\Service\Configuration\GitLabApiSettings;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * GitLabApiClient.
 *
 * @covers \OCA\OpenRegister\Service\Configuration\GitLabApiClient
 * @uses \OCA\OpenRegister\Service\Configuration\GitLabApiSettings
 */
class GitLabApiClientTest extends TestCase {

	private IClient $http;

	private function client(string $token = ''): GitLabApiClient {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnMap(
			[
				['openregister', 'gitlab_api_url', '', 'https://git.example.nl/api/v4'],
				['openregister', 'gitlab_api_token', '', $token],
			]
		);

		$this->http = $this->createMock(IClient::class);
		$service    = $this->createMock(IClientService::class);
		$service->expects($this->once())->method('newClient')->willReturn($this->http);

		return new GitLabApiClient($service, new GitLabApiSettings($appConfig, $this->createMock(IConfig::class)));
	}//end client()

	private function response(string $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn($body);

		return $response;
	}//end response()

	public function testTheApiBaseIsReadOnceFromTheSettings(): void {
		$this->assertSame('https://git.example.nl/api/v4', $this->client()->apiBase());
	}//end testTheApiBaseIsReadOnceFromTheSettings()

	public function testAGetCarriesTheTokenAndReturnsTheDecodedBody(): void {
		$client = $this->client('glpat-1');
		$this->http->expects($this->once())->method('request')
			->with('GET', 'https://git.example.nl/api/v4/projects/7', ['query' => ['ref' => 'main'], 'headers' => ['PRIVATE-TOKEN' => 'glpat-1']])
			->willReturn($this->response('{"id":7,"name":"regs"}'));

		$this->assertSame(['id' => 7, 'name' => 'regs'], $client->getJson('/projects/7', ['query' => ['ref' => 'main']]));
	}//end testAGetCarriesTheTokenAndReturnsTheDecodedBody()

	public function testCallerHeadersAreKeptOverTheDefaults(): void {
		$client = $this->client('glpat-1');
		$this->http->expects($this->once())->method('request')
			->with('GET', 'https://git.example.nl/api/v4/x', ['headers' => ['Accept' => 'text/plain']])
			->willReturn($this->response('"ok"'));

		$this->assertSame('ok', $client->getJson('/x', ['headers' => ['Accept' => 'text/plain']]));
	}//end testCallerHeadersAreKeptOverTheDefaults()

	public function testANonJsonBodyDecodesToNull(): void {
		$client = $this->client();
		$this->http->method('request')->willReturn($this->response('<html>'));

		$this->assertNull($client->getJson('/projects'));
	}//end testANonJsonBodyDecodesToNull()

	public function testAWriteSendsTheJsonBodyWithTheTokenAndReturnsTheDecodedAnswer(): void {
		$client = $this->client('glpat-1');
		$this->http->expects($this->once())->method('request')
			->with('PUT', 'https://git.example.nl/api/v4/projects/7/repository/files/a.json', ['json' => ['branch' => 'main'], 'headers' => ['PRIVATE-TOKEN' => 'glpat-1']])
			->willReturn($this->response('{"file_path":"a.json","branch":"main"}'));

		$this->assertSame(['file_path' => 'a.json', 'branch' => 'main'], $client->sendJson('PUT', '/projects/7/repository/files/a.json', ['branch' => 'main']));
	}//end testAWriteSendsTheJsonBodyWithTheTokenAndReturnsTheDecodedAnswer()
}//end class
