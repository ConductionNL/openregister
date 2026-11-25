<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Service\File\PostedFileContent;
use PHPUnit\Framework\TestCase;

/**
 * Posted content is decoded the way the create pipeline writes it.
 *
 * @covers \OCA\OpenRegister\Service\File\PostedFileContent
 */
final class PostedFileContentTest extends TestCase {

	public function testPlainBase64IsDecoded(): void {
		$this->assertSame('hello', (new PostedFileContent())->decoded(base64_encode('hello')));
	}

	public function testABase64DataUriIsDecoded(): void {
		$this->assertSame('hello', (new PostedFileContent())->decoded('data:text/plain;base64,' . base64_encode('hello')));
	}

	public function testADataUriThatIsNotBase64StaysAsSent(): void {
		$this->assertSame('data:text/plain,hello%20world', (new PostedFileContent())->decoded('data:text/plain,hello%20world'));
	}

	public function testTextThatIsNotBase64StaysAsSent(): void {
		$this->assertSame('Hello World!', (new PostedFileContent())->decoded('Hello World!'));
	}

	public function testAStreamIsReturnedAsGiven(): void {
		$stream = fopen('php://memory', 'r+');

		$this->assertSame($stream, (new PostedFileContent())->decoded($stream));
	}
}
