<?php

/**
 * Golden characterisation test for the refusals of OAuth2InstanceHost::normalise().
 *
 * Pins, on the code before the origin-shape and public-host checks were split into
 * named steps, the exact refusal message for each unsafe instance base URL — which
 * also pins the order of the checks, since a URL failing several gets the first.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Credential
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/credential-broker/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use InvalidArgumentException;
use OCA\OpenRegister\Service\Credential\OAuth2InstanceHost;
use PHPUnit\Framework\TestCase;

final class OAuth2InstanceHostRefusalGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/fixtures/oauth2-instance-host-refusals.json';

	private const CANDIDATES = [
		'http://mastodon.example',
		'ftp://mastodon.example',
		'https://user@mastodon.example',
		'https://user:pass@mastodon.example',
		'http://user@mastodon.example?x=1',
		'https://mastodon.example?a=b',
		'https://mastodon.example#x',
		'https://mastodon.example/path',
		'https://mastodon.example/path?q=1',
		'https://mastodon.example:8443',
		'https://mastodon.example:8443/path',
		'https://',
		'https://localhost',
		'https://127.0.0.1',
		'https://[::1]',
		'https://10.0.0.1',
		'https://intranet',
		'https://-bad-.example',
		'https://under_score.example',
		'https://server.local',
		'https://api.internal',
		'https://LOCALHOST',
		'not a url at all',
		'',
		'https://mastodon.example',
	];

	public function testEachRefusalMessageMatchesTheGolden(): void {
		$outcomes = [];
		foreach (self::CANDIDATES as $candidate) {
			try {
				$outcomes[$candidate] = 'allowed: ' . OAuth2InstanceHost::normalise(candidate: $candidate);
			} catch (InvalidArgumentException $e) {
				$outcomes[$candidate] = 'refused: ' . $e->getMessage();
			}
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($outcomes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), $outcomes);
	}
}//end class
