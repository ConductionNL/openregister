<?php

/**
 * Golden test for OAuth2RelayGuard::permits(): every callback URL the relay forwards to, and every one it refuses.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Credential
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Credential;

use OCA\OpenRegister\Service\Credential\OAuth2RelayGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

final class OAuth2RelayCallbackGoldenTest extends TestCase {

	private const CALLBACKS = [
		'https://tenant.example/apps/openregister/oauth2/callback' => true,
		'https://TENANT.example/apps/openregister/oauth2/callback/' => true,
		'  https://tenant.example/index.php/apps/openregister/oauth2/callback  ' => true,
		'https://tenant.example:8443/apps/openregister/oauth2/callback' => true,
		'https://tenant.example:9443/apps/openregister/oauth2/callback' => false,
		'https://tenant.example:8443:x/apps/openregister/oauth2/callback' => false,
		'http://tenant.example/apps/openregister/oauth2/callback' => false,
		'HTTPS://tenant.example/apps/openregister/oauth2/callback' => true,
		'https://user@tenant.example/apps/openregister/oauth2/callback' => false,
		'https://:secret@tenant.example/apps/openregister/oauth2/callback' => false,
		'https://user:secret@tenant.example/apps/openregister/oauth2/callback' => false,
		'https://tenant.example/apps/openregister/oauth2/callbacks' => false,
		'https://tenant.example/apps/other/oauth2/callback' => false,
		'https://tenant.example/openregister/oauth2/callback' => true,
		'https:///apps/openregister/oauth2/callback' => false,
		'https://other.example/apps/openregister/oauth2/callback' => false,
		'' => false,
	];

	public function testEveryCallbackOutcome(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('https://tenant.example, https://tenant.example:8443');
		$guard = new OAuth2RelayGuard(appConfig: $config);

		$outcomes = [];
		foreach (array_keys(self::CALLBACKS) as $url) {
			$outcomes[$url] = $guard->permits(callbackUrl: (string)$url);
		}

		$this->assertSame(self::CALLBACKS, $outcomes);
	}
}
