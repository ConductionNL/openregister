<?php

/**
 * Unit tests for WebsiteFormat (or-form-and-journey-registry task 4.2).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Formats
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Formats;

use OCA\OpenRegister\Formats\WebsiteFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A website is a host with an optional scheme and path. The corpus the
 * app-local forks disagreed on is pinned: `www.nl` passes, `http://.nl` and a
 * hyphen-only label do not.
 *
 * @covers \OCA\OpenRegister\Formats\WebsiteFormat
 *
 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-named-formats-must-replace-the-app-local-validator-forks
 */
class WebsiteFormatTest extends TestCase {

	/**
	 * The format under test.
	 *
	 * @var WebsiteFormat
	 */
	private WebsiteFormat $format;

	protected function setUp(): void {
		$this->format = new WebsiteFormat();
	}//end setUp()

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function accepted(): array {
		return [
			'bare host' => ['www.nl'],
			'host and tld' => ['example.nl'],
			'subdomain' => ['www.example.com'],
			'https scheme' => ['https://example.nl'],
			'http scheme' => ['http://example.nl'],
			'scheme and path' => ['https://example.nl/contact'],
			'a hyphen inside a label' => ['my-site.nl'],
			'deep subdomain' => ['a.b.c.example.org'],
		];
	}//end accepted()

	#[DataProvider('accepted')]
	public function testAWebsiteIsAccepted(string $value): void {
		$this->assertTrue($this->format->validate($value), $value . ' should be a website');
	}//end testAWebsiteIsAccepted()

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function refused(): array {
		return [
			'no host after the scheme' => ['http://.nl'],
			'a hyphen-only label' => ['---.nl'],
			'a leading hyphen label' => ['-example.nl'],
			'a trailing hyphen label' => ['example-.nl'],
			'no tld' => ['localhost'],
			'a one-letter tld' => ['example.n'],
			'a numeric tld' => ['example.123'],
			'a space' => ['not a web address'],
			'empty' => [''],
			'whitespace only' => ['   '],
			'not a string' => [42],
			'an array' => [['example.nl']],
		];
	}//end refused()

	#[DataProvider('refused')]
	public function testANonWebsiteIsRefused(mixed $value): void {
		$this->assertFalse($this->format->validate($value), var_export($value, true) . ' should not be a website');
	}//end testANonWebsiteIsRefused()
}//end class
