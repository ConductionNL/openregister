<?php

/**
 * DangerousExtensionListsTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\File
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Service\File\FileValidationHandler;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyParts;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;

/**
 * Pins that the upload validation and the file property upload guard refuse the
 * same dangerous extensions, in the same order.
 */
class DangerousExtensionListsTest extends TestCase {

	/**
	 * The extension list both upload paths refuse.
	 *
	 * @return list<string>
	 */
	private function expected(): array {
		return [
			'exe', 'bat', 'cmd', 'com', 'msi', 'scr', 'vbs', 'vbe', 'js', 'jse', 'wsf', 'wsh', 'ps1', 'dll',
			'sh', 'bash', 'csh', 'ksh', 'zsh', 'run', 'bin', 'app', 'deb', 'rpm',
			'php', 'phtml', 'php3', 'php4', 'php5', 'phps', 'phar', 'py', 'pyc', 'pyo', 'pyw', 'pl', 'pm',
			'cgi', 'rb', 'rbw', 'jar', 'war', 'ear', 'class',
			'appimage', 'snap', 'flatpak', 'dmg', 'pkg', 'command', 'apk',
			'elf', 'out', 'o', 'so', 'dylib',
		];
	}

	public function testUploadValidationListIsPinned(): void {
		$list = (new ReflectionClassConstant(FileValidationHandler::class, 'DANGEROUS_EXTENSIONS'))->getValue();

		$this->assertSame($this->expected(), $list);
	}

	public function testFilePropertyGuardListIsPinned(): void {
		$list = (new ReflectionClassConstant(FilePropertyParts::class, 'DANGEROUS_EXECUTABLE_EXTENSIONS'))->getValue();

		$this->assertSame($this->expected(), $list);
	}
}//end class
