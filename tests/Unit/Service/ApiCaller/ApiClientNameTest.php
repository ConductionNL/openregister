<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\ApiCaller;

use OCA\OpenRegister\Service\ApiCaller\ApiClientName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The client name is read from a closed, anchored pattern only.
 *
 * @spec openspec/changes/api-client-libraries/specs/api-client-libraries/spec.md#requirement-the-caller-record-names-the-client-library
 *
 * @covers \OCA\OpenRegister\Service\ApiCaller\ApiClientName
 */
final class ApiClientNameTest extends TestCase {

	/**
	 * User-Agents and what is recorded for them.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function userAgents(): array {
		return [
			'typescript client' => ['openregister-client-ts/1.4.0', 'openregister-client-ts/1.4.0'],
			'python client with a suffix' => ['openregister-client-python/2.0.13 httpx/0.27.0', 'openregister-client-python/2.0.13'],
			'client with a runtime comment' => ['openregister-client-ts/1.2.0 (node 22)', 'openregister-client-ts/1.2.0'],
			'browser' => ['Mozilla/5.0 (X11; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0', ''],
			'client name not at the start' => ['curl/8.5 openregister-client-ts/1.4.0', ''],
			'unknown language' => ['openregister-client-go/1.0.0', ''],
			'two-part version' => ['openregister-client-ts/1.4', ''],
			'four-part version keeps the first three' => ['openregister-client-ts/1.4.0.1', 'openregister-client-ts/1.4.0'],
			'pre-release tag' => ['openregister-client-ts/1.4.0-beta.1', 'openregister-client-ts/1.4.0'],
			'over-long version part' => ['openregister-client-ts/1234567.0.0', ''],
			'empty' => ['', ''],
		];
	}

	#[DataProvider('userAgents')]
	public function testOnlyAnOfficialClientIsNamed(string $userAgent, string $expected): void {
		$this->assertSame($expected, ApiClientName::fromUserAgent(userAgent: $userAgent));
	}

	public function testTheLongestNameFitsTheColumn(): void {
		$name = ApiClientName::fromUserAgent(userAgent: 'openregister-client-python/999999.999999.999999');

		$this->assertSame('openregister-client-python/999999.999999.999999', $name);
		$this->assertLessThanOrEqual(ApiClientName::MAX_LENGTH, strlen($name));
	}
}
