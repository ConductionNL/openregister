<?php

/**
 * FilePropertyParts builds the collaborators of FilePropertyHandler.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\SaveObject\FileInputReader;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyConfigs;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyFiles;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyParts;
use OCA\OpenRegister\Service\Object\SaveObject\FileUploadGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\FilePropertyParts
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\FilePropertyLog
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\FileInputReader
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\FileUploadGuard
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\FilePropertyFiles
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\FilePropertyConfigs
 */
final class FilePropertyPartsTest extends TestCase {

	/**
	 * Every part is built, and the guard blocks executable extensions.
	 *
	 * @return void
	 */
	public function testBuildsEveryPart(): void {
		$parts = new FilePropertyParts($this->createMock(LoggerInterface::class), $this->createMock(FileService::class));

		$this->assertInstanceOf(FileInputReader::class, $parts->reader);
		$this->assertInstanceOf(FileUploadGuard::class, $parts->guard);
		$this->assertInstanceOf(FilePropertyFiles::class, $parts->files);
		$this->assertInstanceOf(FilePropertyConfigs::class, $parts->configs);

		$this->expectExceptionMessage('P is an executable file (.exe).');
		$parts->guard->blockExecutableExtension(['filename' => 'x.exe'], 'P');
	}//end testBuildsEveryPart()
}//end class
