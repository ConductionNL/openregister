<?php

/**
 * Golden test: the outbound OAuth call options and the signed JWT
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
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

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\AuthenticationService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Twig\Loader\ArrayLoader;

/**
 * Pins the exact client-credentials call options (body, basic auth, other,
 * with and without a client assertion), the refusals of fetchOAuthTokens
 * before any HTTP call, and the exact compact JWT for a fixed HMAC payload.
 */
class AuthenticationServiceCallConfigGoldenTest extends TestCase {

	/** @var array<int, array<string, mixed>> What fetchJWTToken was asked for. */
	private array $jwtRequests = [];

	/**
	 * A service whose fetchJWTToken records its configuration.
	 *
	 * @return AuthenticationService
	 */
	private function recordingService(): AuthenticationService {
		$service = $this->getMockBuilder(AuthenticationService::class)
			->setConstructorArgs([new ArrayLoader()])
			->onlyMethods(['fetchJWTToken'])
			->getMock();
		$service->method('fetchJWTToken')->willReturnCallback(
			function (array $configuration): string {
				$this->jwtRequests[] = $configuration;
				return 'signed-assertion';
			}
		);
		return $service;
	}//end recordingService()

	/**
	 * Call the private client-credentials builder.
	 *
	 * @param AuthenticationService $service       The service.
	 * @param array<string, mixed>  $configuration The configuration.
	 *
	 * @return mixed
	 */
	private function clientConfig(AuthenticationService $service, array $configuration): mixed {
		$method = new ReflectionMethod(AuthenticationService::class, 'createClientCredentialConfig');
		$method->setAccessible(true);
		return $method->invoke($service, $configuration);
	}//end clientConfig()

	/**
	 * A complete client-credentials configuration.
	 *
	 * @param string $authentication Where the credentials go.
	 *
	 * @return array<string, mixed>
	 */
	private function base(string $authentication): array {
		return [
			'grant_type' => 'client_credentials',
			'scope' => 'api',
			'authentication' => $authentication,
			'client_id' => 'id-1',
			'client_secret' => 's-1',
		];
	}//end base()

	/**
	 * The client-credentials call options, per placement and assertion.
	 *
	 * @return void
	 */
	public function testClientCredentialCallOptions(): void {
		$service = $this->recordingService();

		$this->assertSame(
			['form_params' => ['grant_type' => 'client_credentials', 'scope' => 'api', 'client_id' => 'id-1', 'client_secret' => 's-1']],
			$this->clientConfig($service, $this->base('body'))
		);
		$this->assertSame(
			['form_params' => ['grant_type' => 'client_credentials', 'scope' => 'api'], 'auth' => ['username' => 'id-1', 'password' => 's-1']],
			$this->clientConfig($service, $this->base('basic_auth'))
		);
		$this->assertSame(
			['form_params' => ['grant_type' => 'client_credentials', 'scope' => 'api']],
			$this->clientConfig($service, $this->base('header') + ['client_assertion_type' => 'urn:other'])
		);
		$this->assertSame([], $this->jwtRequests, 'Another assertion type signs nothing.');

		$assertion = [
			'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
			'private_key' => 'pk',
			'x5t' => 'thumb',
			'payload' => '{"a":1}',
		];
		$this->assertSame(
			[
				'form_params' => [
					'grant_type' => 'client_credentials',
					'scope' => 'api',
					'client_id' => 'id-1',
					'client_secret' => 's-1',
					'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
					'client_assertion' => 'signed-assertion',
				],
			],
			$this->clientConfig($service, $this->base('body') + $assertion)
		);
		$this->assertSame(
			[['algorithm' => 'PS256', 'secret' => 'pk', 'x5t' => 'thumb', 'payload' => '{"a":1}']],
			$this->jwtRequests
		);

		$this->assertSame(
			[
				'form_params' => [
					'grant_type' => 'client_credentials',
					'scope' => 'api',
					'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
					'client_assertion' => 'signed-assertion',
				],
				'auth' => ['username' => 'id-1', 'password' => 's-1'],
			],
			$this->clientConfig($service, $this->base('basic_auth') + $assertion)
		);
	}//end testClientCredentialCallOptions()

	/**
	 * Missing client-credentials parameters are listed in the refusal.
	 *
	 * @return void
	 */
	public function testMissingClientParametersAreListed(): void {
		try {
			$this->clientConfig($this->recordingService(), ['scope' => 'api', 'client_id' => 'x']);
			$this->fail('No refusal.');
		} catch (BadRequestException $e) {
			$this->assertSame('Some required parameters are not set: [grant_type,authentication,client_secret]', $e->getMessage());
		}
	}//end testMissingClientParametersAreListed()

	/**
	 * The refusals fetchOAuthTokens gives before any HTTP call, in order.
	 *
	 * @return void
	 */
	public function testOAuthTokenRefusals(): void {
		$service = new AuthenticationService(new ArrayLoader());
		$cases = [
			[['tokenUrl' => 'x'], 'Grant type not set, cannot request token'],
			[['grant_type' => 'nope'], 'Token URL not set, cannot request token'],
			[['grant_type' => 'nope', 'tokenUrl' => 'x'], 'Grant type not supported'],
			[['grant_type' => 'password', 'tokenUrl' => 'x'], 'Some required parameters are not set: [scope,authentication,username,password]'],
			[['grant_type' => 'client_credentials', 'tokenUrl' => 'x'], 'Some required parameters are not set: [scope,authentication,client_id,client_secret]'],
		];
		foreach ($cases as [$configuration, $message]) {
			try {
				$service->fetchOAuthTokens($configuration);
				$this->fail('No refusal for ' . $message);
			} catch (BadRequestException $e) {
				$this->assertSame($message, $e->getMessage());
			}
		}
	}//end testOAuthTokenRefusals()

	/**
	 * The exact compact HMAC JWT, with and without a thumbprint.
	 *
	 * @return void
	 */
	public function testSignedHmacTokenIsExact(): void {
		$service = new AuthenticationService(new ArrayLoader());
		$configuration = ['payload' => '{"iss":"or","n":{{ 1 + 1 }}}', 'secret' => 'a-secret-of-thirty-two-bytes-ok!', 'algorithm' => 'HS256'];

		$plain = $service->fetchJWTToken($configuration);
		$withThumb = $service->fetchJWTToken($configuration + ['x5t' => 'thumb']);
		$nullThumb = $service->fetchJWTToken($configuration + ['x5t' => null]);

		[$header, $payload] = explode('.', $plain);
		$this->assertSame('{"alg":"HS256","typ":"JWT"}', base64_decode(strtr($header, '-_', '+/')));
		$this->assertSame('{"iss":"or","n":2}', base64_decode(strtr($payload, '-_', '+/')));
		$this->assertSame(hash_hmac('sha256', $header . '.' . $payload, addslashes($configuration['secret'])), bin2hex($this->sig($plain)));

		[$thumbHeader] = explode('.', $withThumb);
		$this->assertSame('{"alg":"HS256","typ":"JWT","x5t":"thumb"}', base64_decode(strtr($thumbHeader, '-_', '+/')));
		$this->assertSame($plain, $nullThumb, 'A null thumbprint adds no header.');
	}//end testSignedHmacTokenIsExact()

	/**
	 * The raw signature of a compact JWS.
	 *
	 * @param string $token The token.
	 *
	 * @return string
	 */
	private function sig(string $token): string {
		$parts = explode('.', $token);
		$raw = strtr($parts[2], '-_', '+/');
		return (string) base64_decode($raw . str_repeat('=', (4 - strlen($raw) % 4) % 4));
	}//end sig()
}//end class
