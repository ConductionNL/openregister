<?php

/**
 * Earlier versions through the optional versions app.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Service\File\DocumentVersions;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Without the versions app every answer is "none", and nothing reaches the container.
 */
#[CoversClass(DocumentVersions::class)]
class DocumentVersionsTest extends TestCase {

	private function versions(bool $installed): DocumentVersions {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->with('files_versions')->willReturn($installed);
		$container = $this->createMock(ContainerInterface::class);
		if ($installed === false || interface_exists(DocumentVersions::MANAGER) === false) {
			$container->expects($this->never())->method('get');
		}

		return new DocumentVersions(appManager: $apps, userManager: $this->createMock(IUserManager::class), container: $container);
	}//end versions()

	public function testWithoutTheVersionsAppNothingIsKept(): void {
		$versions = $this->versions(installed: false);

		$this->assertFalse($versions->keepsVersions(folder: $this->createMock(Folder::class)));
		$this->assertSame([], $versions->versionsOf(file: $this->createMock(File::class)));
	}//end testWithoutTheVersionsAppNothingIsKept()

	public function testAVersionThatCannotBeReadThrows(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('An earlier version cannot be read.');

		$this->versions(installed: false)->read(version: new \stdClass());
	}//end testAVersionThatCannotBeReadThrows()
}//end class
