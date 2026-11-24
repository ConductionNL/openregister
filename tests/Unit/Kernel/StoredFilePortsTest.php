<?php

/**
 * The stored-file read and write ports are what ObjectFilesInterface combines.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Kernel
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Kernel;

use OCA\OpenRegister\Kernel\Contracts\Core\StoredFileReaderInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\StoredFileWriterInterface;
use OCA\OpenRegister\Service\FileReaderInterface;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\FileWriterInterface;
use OCA\OpenRegister\Service\ObjectFilesInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Core reads and writes an object's files through its own ports; the file interfaces extend them.
 */
final class StoredFilePortsTest extends TestCase {

	public function testObjectFilesCombinesTheTwoCorePorts(): void {
		$this->assertSame(
			[StoredFileReaderInterface::class, StoredFileWriterInterface::class],
			(new ReflectionClass(ObjectFilesInterface::class))->getInterfaceNames()
		);
	}

	public function testTheFileInterfacesAreThePortsUnderTheirOldNames(): void {
		$this->assertTrue(is_subclass_of(FileReaderInterface::class, StoredFileReaderInterface::class));
		$this->assertTrue(is_subclass_of(FileWriterInterface::class, StoredFileWriterInterface::class));
		$this->assertSame([], $this->ownMethods(FileReaderInterface::class));
		$this->assertSame([], $this->ownMethods(FileWriterInterface::class));
	}

	public function testThePortsKeepEveryReadAndWriteMethod(): void {
		$this->assertSame(
			['formatFile', 'findObjectForFile', 'getFile', 'getFileById', 'getFileTags', 'getFiles', 'isManagedFile'],
			$this->ownMethods(StoredFileReaderInterface::class)
		);
		$this->assertSame(['addFile', 'deleteFile', 'overwriteFile'], $this->ownMethods(StoredFileWriterInterface::class));
	}

	public function testFileServiceStillAnswersEveryName(): void {
		foreach ([FileReaderInterface::class, FileWriterInterface::class, ObjectFilesInterface::class, StoredFileReaderInterface::class, StoredFileWriterInterface::class] as $contract) {
			$this->assertTrue(is_subclass_of(FileService::class, $contract), $contract);
		}
	}

	/**
	 * The methods an interface declares itself, in declaration order.
	 *
	 * @param class-string $interface The interface.
	 *
	 * @return list<string>
	 */
	private function ownMethods(string $interface): array {
		$names = [];
		foreach ((new ReflectionClass($interface))->getMethods() as $method) {
			if ($method->getDeclaringClass()->getName() === $interface) {
				$names[] = $method->getName();
			}
		}

		return $names;
	}
}//end class
