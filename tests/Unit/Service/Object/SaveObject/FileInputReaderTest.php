<?php

/**
 * FileInputReader reads uploads, data URIs and base64.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use Exception;
use OCA\OpenRegister\Service\Object\SaveObject\FileInputReader;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyLog;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\FileInputReader
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\FilePropertyLog
 */
final class FileInputReaderTest extends TestCase {

	/**
	 * A data URI keeps its declared MIME type; the extension follows from it.
	 *
	 * @return void
	 */
	public function testParsesADataUri(): void {
		$this->assertSame(
			['content' => 'hi', 'mimeType' => 'application/pdf', 'extension' => 'pdf', 'size' => 2],
			$this->reader()->parse('data:application/pdf;base64,' . base64_encode('hi'))
		);
	}//end testParsesADataUri()

	/**
	 * Invalid base64 is refused.
	 *
	 * @return void
	 */
	public function testRefusesInvalidBase64(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Invalid base64 content');

		$this->reader()->parse('***');
	}//end testRefusesInvalidBase64()

	/**
	 * An unknown MIME type maps to bin.
	 *
	 * @return void
	 */
	public function testUnknownMimeTypeIsBin(): void {
		$this->assertSame('bin', $this->reader()->extensionOf('application/x-unknown'));
		$this->assertSame('docx', $this->reader()->extensionOf('application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
	}//end testUnknownMimeTypeIsBin()

	/**
	 * Only http(s) URLs are http URLs.
	 *
	 * @return void
	 */
	public function testIsHttpUrl(): void {
		$this->assertTrue($this->reader()->isHttpUrl('https://example.com/a.pdf'));
		$this->assertFalse($this->reader()->isHttpUrl('ftp://example.com/a.pdf'));
		$this->assertFalse($this->reader()->isHttpUrl('not a url'));
	}//end testIsHttpUrl()

	/**
	 * An upload becomes a data URI; array notation appends; an errored upload is skipped.
	 *
	 * @return void
	 */
	public function testInjectsUploads(): void {
		$tmp = tempnam(sys_get_temp_dir(), 'or-reader-');
		file_put_contents($tmp, 'up');
		$ok = ['name' => 'a', 'type' => 'text/plain', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 2];

		$data = $this->reader()->injectUploads(
			['one' => $ok, 'many[]' => $ok, 'bad' => ['error' => UPLOAD_ERR_PARTIAL] + $ok],
			['many' => ['kept']]
		);
		unlink($tmp);

		$uri = 'data:text/plain;base64,' . base64_encode('up');
		$this->assertSame(['many' => ['kept', $uri], 'one' => $uri], $data);
	}//end testInjectsUploads()

	/**
	 * Array notation on a field the data does not hold yet starts a list.
	 *
	 * @return void
	 */
	public function testArrayNotationStartsAList(): void {
		$tmp = tempnam(sys_get_temp_dir(), 'or-reader-');
		file_put_contents($tmp, 'up');
		$ok = ['name' => 'a', 'type' => 'text/plain', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 2];

		$data = $this->reader()->injectUploads(['fresh[0]' => $ok], ['title' => 't']);
		unlink($tmp);

		$this->assertSame(['title' => 't', 'fresh' => ['data:text/plain;base64,' . base64_encode('up')]], $data);
	}//end testArrayNotationStartsAList()

	/**
	 * The file data shape carries the content's size.
	 *
	 * @return void
	 */
	public function testFileData(): void {
		$this->assertSame(
			['content' => 'abcd', 'mimeType' => 'm', 'extension' => 'e', 'size' => 4],
			$this->reader()->fileData('abcd', 'm', 'e')
		);
	}//end testFileData()

	/**
	 * A reader over a silent logger.
	 *
	 * @return FileInputReader
	 */
	private function reader(): FileInputReader {
		return new FileInputReader(new FilePropertyLog($this->createMock(LoggerInterface::class)));
	}//end reader()
}//end class
