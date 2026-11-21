<?php

/**
 * Unit tests for InstanceSecretFilter: secret values left out of an instance set, with their paths.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Import
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Import;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Import\InstanceSecretFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(InstanceSecretFilter::class)]
final class InstanceSecretFilterTest extends TestCase {

	/**
	 * @return array<string, array{0: string|int, 1: bool}>
	 */
	public static function keys(): array {
		return [
			'password'          => ['password', true],
			'snake api key'     => ['api_key', true],
			'camel api key'     => ['apiKey', true],
			'client secret'     => ['client-secret', true],
			'access token'      => ['accessToken', true],
			'private key'       => ['private.key', true],
			'authorization'     => ['Authorization', true],
			'credentials'       => ['credentials', true],
			'plain title'       => ['title', false],
			'key alone'         => ['key', false],
			'numeric list key'  => [0, false],
		];
	}

	#[DataProvider('keys')]
	public function testIsSecretKey(string|int $key, bool $secret): void {
		$this->assertSame($secret, (new InstanceSecretFilter())->isSecretKey($key));
	}

	public function testStripLeavesSecretsOutAndNamesTheirPaths(): void {
		$input = [
			'title'  => 'Bron',
			'auth'   => ['type' => 'oauth', 'clientSecret' => 's3cr3t', 'username' => 'svc'],
			'apiKey' => 'abc',
			'list'   => [['token' => 't1', 'name' => 'a'], ['name' => 'b']],
		];

		[$clean, $excluded] = (new InstanceSecretFilter())->strip($input, 'sources');

		$this->assertSame(
			[
				'title' => 'Bron',
				'auth'  => ['type' => 'oauth', 'username' => 'svc'],
				'list'  => [['name' => 'a'], ['name' => 'b']],
			],
			$clean
		);
		$this->assertSame(['sources.auth.clientSecret', 'sources.apiKey', 'sources.list.0.token'], $excluded);
		$this->assertStringNotContainsString('s3cr3t', (string) json_encode($clean));
		$this->assertStringNotContainsString('s3cr3t', implode(' ', $excluded));
	}

	public function testAnEmptySecretKeepsItsShapeAndIsNotReported(): void {
		[$clean, $excluded] = (new InstanceSecretFilter())->strip(['token' => null, 'password' => '', 'apiKey' => []]);

		$this->assertSame(['token' => null, 'password' => '', 'apiKey' => []], $clean);
		$this->assertSame([], $excluded);
	}

	public function testRootPathsHaveNoLeadingDot(): void {
		[, $excluded] = (new InstanceSecretFilter())->strip(['password' => 'x']);

		$this->assertSame(['password'], $excluded);
	}
}
