<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/content-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectFileWrite;
use OCA\OpenRegister\Service\ObjectFilesInterface;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A single file property keeps a bare file id only when the object's own folder holds that file.
 *
 * @spec openspec/specs/content-versioning/spec.md
 */
class FilePropertyOwnFolderSaveTest extends TestCase {

	private const OWN = 43;

	private const FOREIGN = 12345;

	/**
	 * The object data after saving a value into the single file property `doc`.
	 *
	 * @param mixed $value The incoming value.
	 *
	 * @return array<string, mixed>
	 */
	private function saved(mixed $value): array {
		$fileService = $this->createMock(ObjectFilesInterface::class);
		$fileService->method('getFile')->willReturnCallback(
			fn (mixed $object, mixed $file): ?File => ((int)$file === self::OWN) ? $this->createMock(File::class) : null
		);

		$entity = new ObjectEntity();
		$entity->setUuid('obj-1');
		$schema = new Schema();
		$schema->setId(7);
		$schema->setProperties(['doc' => ['type' => 'file']]);

		$write         = new ObjectFileWrite();
		$write->entity = $entity;
		$write->schema = $schema;
		$write->data   = ['doc' => $value];

		(new FilePropertyHandler(new NullLogger(), $fileService))->handleFileProperty($write, 'doc');

		return $write->data;
	}//end saved()

	public function testAnIdOfAFileInTheObjectsFolderIsKept(): void {
		$this->assertSame(['doc' => self::OWN], $this->saved(self::OWN));
		$this->assertSame(['doc' => (string)self::OWN], $this->saved((string)self::OWN));
	}//end testAnIdOfAFileInTheObjectsFolderIsKept()

	public function testAnIdOfAFileOutsideTheObjectsFolderIsRefused(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage("only accepts files in the object's folder");

		$this->saved(self::FOREIGN);
	}//end testAnIdOfAFileOutsideTheObjectsFolderIsRefused()

	public function testANumericStringOutsideTheObjectsFolderIsRefused(): void {
		$this->expectException(Exception::class);

		$this->saved((string)self::FOREIGN);
	}//end testANumericStringOutsideTheObjectsFolderIsRefused()

	public function testAValueThatIsNoFileIdIsLeftAsItWas(): void {
		$this->assertSame(['doc' => 'plain text'], $this->saved('plain text'));
	}//end testAValueThatIsNoFileIdIsLeftAsItWas()
}//end class
