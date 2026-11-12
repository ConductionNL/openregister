<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace Unit\Service;

use OCA\OpenRegister\Service\AuthenticationService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Twig\Loader\ArrayLoader;

/**
 * Pins SEC-SVC-4: the JWT payload template sees the configuration without any
 * credential-bearing key, and sees every other key.
 *
 * @covers \OCA\OpenRegister\Service\AuthenticationService
 */
class AuthenticationServiceJwtPayloadSecretsTest extends TestCase {

	/**
	 * The keys the payload template must never see.
	 */
	private const SECRET_KEYS = [
		'secret',
		'clientSecret',
		'client_secret',
		'password',
		'privateKey',
		'private_key',
		'apiKey',
		'api_key',
		'token',
		'accessToken',
		'access_token',
		'refreshToken',
		'refresh_token',
		'authorization',
	];

	/**
	 * Every secret key is stripped from the template context; the other keys stay.
	 *
	 * @return void
	 */
	public function testSecretsNeverReachThePayloadTemplate(): void {
		$keys = array_merge(self::SECRET_KEYS, ['issuer', 'audience']);
		$configuration = [];
		$template = [];
		foreach ($keys as $key) {
			$configuration[$key] = 'value-of-' . $key;
			$template[] = '"' . $key . '": "{{ ' . $key . ' is defined ? "seen" : "absent" }}"';
		}

		$configuration['payload'] = '{' . implode(', ', $template) . '}';

		$service = new AuthenticationService(new ArrayLoader());
		$payload = (new ReflectionMethod(AuthenticationService::class, 'getJWTPayload'))->invoke($service, $configuration);

		$expected = array_fill_keys(self::SECRET_KEYS, 'absent') + ['issuer' => 'seen', 'audience' => 'seen'];
		$this->assertSame($expected, $payload);
	}//end testSecretsNeverReachThePayloadTemplate()
}//end class
