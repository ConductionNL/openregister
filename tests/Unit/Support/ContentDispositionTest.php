<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Support\ContentDisposition}.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Support;

use OCA\OpenRegister\Support\ContentDisposition;
use PHPUnit\Framework\TestCase;

/**
 * The Content-Disposition header value a file is served under.
 */
class ContentDispositionTest extends TestCase {

	/**
	 * Header values for plain, hostile and non-ASCII names.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function names(): array {
		return [
			'plain' => ['inline', 'a.txt', "inline; filename=\"a.txt\"; filename*=UTF-8''a.txt"],
			'header split' => ['attachment', "x\r\nSet-Cookie: y.txt", "attachment; filename=\"xSet-Cookie: y.txt\"; filename*=UTF-8''xSet-Cookie%3A%20y.txt"],
			'quote and backslash' => ['attachment', 'a"b\\c.txt', "attachment; filename=\"a_b_c.txt\"; filename*=UTF-8''a%22b%5Cc.txt"],
			'non-ascii' => ['inline', 'café.pdf', "inline; filename=\"caf__.pdf\"; filename*=UTF-8''caf%C3%A9.pdf"],
		];
	}//end names()

	/**
	 * The value keeps control characters out and carries an ASCII and a UTF-8 name.
	 *
	 * @param string $disposition 'inline' or 'attachment'.
	 * @param string $filename    The raw name.
	 * @param string $expected    The header value.
	 *
	 * @dataProvider names
	 *
	 * @return void
	 */
	public function testHeaderValue(string $disposition, string $filename, string $expected): void {
		$this->assertSame($expected, (new ContentDisposition(disposition: $disposition, filename: $filename))->headerValue());
	}//end testHeaderValue()
}//end class
