<?php

/**
 * FileUploadGuard refuses what a file property must not store.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Service\Object\SaveObject\UploadedContent;
use OCA\OpenRegister\Service\Object\SaveObject\UploadCheck;
use Exception;
use OCA\OpenRegister\Service\File\ExecutableContentDetector;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyLog;
use OCA\OpenRegister\Service\Object\SaveObject\FileUploadGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\FileUploadGuard
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\FilePropertyLog
 * @uses   \OCA\OpenRegister\Service\File\ExecutableContentDetector
 * @uses   \OCA\OpenRegister\Service\File\FilePropertyRules
 */
final class FileUploadGuardTest extends TestCase {

	private const TEXT = ['content' => 'abc', 'mimeType' => 'text/plain', 'size' => 3];

	/**
	 * The error prefix names the index of an array item.
	 *
	 * @return void
	 */
	public function testErrorPrefix(): void {
		$this->assertSame('File at doc', $this->guard()->errorPrefix('doc', null));
		$this->assertSame('File at doc[2]', $this->guard()->errorPrefix('doc', 2));
	}//end testErrorPrefix()

	/**
	 * An accepted file passes.
	 *
	 * @return void
	 */
	public function testAnAcceptedFilePasses(): void {
		$this->guard()->assertAccepted(
			new UploadCheck(fileData: self::TEXT, errorPrefix: 'P', fileConfig: ['allowedTypes' => ['text/plain'], 'maxSize' => 3])
		);
		$this->addToAssertionCount(1);
	}//end testAnAcceptedFilePasses()

	/**
	 * The MIME type is refused before the size.
	 *
	 * @return void
	 */
	public function testTheTypeIsRefusedBeforeTheSize(): void {
		$this->expectExceptionMessage("P has invalid type 'text/plain'. Allowed types: image/png");

		$this->guard()->assertAccepted(
			new UploadCheck(fileData: self::TEXT, errorPrefix: 'P', fileConfig: ['allowedTypes' => ['image/png'], 'maxSize' => 1])
		);
	}//end testTheTypeIsRefusedBeforeTheSize()

	/**
	 * An oversized file is refused.
	 *
	 * @return void
	 */
	public function testAnOversizedFileIsRefused(): void {
		$this->expectExceptionMessage('P exceeds maximum size (2 bytes). File size: 3 bytes');

		$this->guard()->assertAccepted(new UploadCheck(fileData: self::TEXT, errorPrefix: 'P', fileConfig: ['maxSize' => 2]));
	}//end testAnOversizedFileIsRefused()

	/**
	 * A dangerous extension is refused, case-insensitively; a safe one or none passes.
	 *
	 * @return void
	 */
	public function testDangerousExtensions(): void {
		$this->guard()->blockExecutableExtension(['filename' => 'a.txt'], 'P');
		$this->guard()->blockExecutableExtension([], 'P');

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('P is an executable file (.exe).');
		$this->guard()->blockExecutableExtension(['filename' => 'A.EXE'], 'P');
	}//end testDangerousExtensions()

	/**
	 * Executable content is refused.
	 *
	 * @return void
	 */
	public function testExecutableContentIsRefused(): void {
		$this->expectExceptionMessage('P contains executable code (Windows executable (PE/EXE)).');

		$this->guard()->blockExecutableContent(new UploadedContent(content: "MZ\x90\x00", errorPrefix: 'P', fileName: ''));
	}//end testExecutableContentIsRefused()

	/**
	 * A MIME type in the given list is refused; one outside it, or none, passes.
	 *
	 * @return void
	 */
	public function testMimeTypeIn(): void {
		$guard = $this->guard();
		$guard->blockMimeTypeIn(new UploadCheck(fileData: ['mimeType' => 'text/plain'], errorPrefix: 'P'), $guard->executableMimeTypes());
		$guard->blockMimeTypeIn(new UploadCheck(fileData: [], errorPrefix: 'P'), $guard->executableMimeTypes());

		$this->expectExceptionMessage("P has executable MIME type 'application/x-sh'.");
		$guard->blockMimeTypeIn(new UploadCheck(fileData: ['mimeType' => 'application/x-sh'], errorPrefix: 'P'), $guard->executableMimeTypes());
	}//end testMimeTypeIn()

	/**
	 * A guard blocking `exe` over a silent logger.
	 *
	 * @return FileUploadGuard
	 */
	private function guard(): FileUploadGuard {
		return new FileUploadGuard(
			new FilePropertyLog($this->createMock(LoggerInterface::class)),
			new ExecutableContentDetector(),
			['exe']
		);
	}//end guard()
}//end class
