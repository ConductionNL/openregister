<?php

/**
 * The published read contract for the files attached to an object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Contract
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace Unit\Contract;

use OCA\OpenRegister\Contract\ObjectFileReaderInterface;
use OCA\OpenRegister\Service\File\ObjectFileReader;
use OCA\OpenRegister\Service\FileService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A consuming app reads an object's attached files through the interface.
 */
#[CoversClass(ObjectFileReader::class)]
class ObjectFileReaderContractTest extends TestCase {

	/**
	 * The reader implements the published interface.
	 *
	 * @return void
	 */
	public function testTheReaderImplementsTheInterface(): void {
		$this->assertTrue(condition: is_a(ObjectFileReader::class, ObjectFileReaderInterface::class, true));
	}//end testTheReaderImplementsTheInterface()

	/**
	 * The container answers the interface with the reader, so an app can
	 * inject the interface.
	 *
	 * @return void
	 */
	public function testTheContainerBindsTheInterface(): void {
		$source = (string)file_get_contents(filename: __DIR__ . '/../../../lib/Service/ServiceRegistrar.php');

		$this->assertMatchesRegularExpression(
			pattern: '/registerService\(\s*ObjectFileReaderInterface::class,\s*function \(\$c\) \{\s*return \$c->get\(ObjectFileReader::class\);/',
			string: $source
		);
	}//end testTheContainerBindsTheInterface()

	/**
	 * Read through the interface, the object's files come back and its sub-folders do not.
	 *
	 * @return void
	 */
	public function testReadingThroughTheInterfaceKeepsOnlyFiles(): void {
		$letter = $this->createMock(originalClassName: File::class);
		$scan   = $this->createMock(originalClassName: File::class);
		$folder = $this->createMock(originalClassName: Folder::class);

		$fileService = $this->createMock(originalClassName: FileService::class);
		$fileService->expects($this->once())
			->method('getFiles')
			->with('client-1')
			->willReturn([$letter, $folder, $scan]);

		$files = $this->readThrough(reader: new ObjectFileReader(fileService: $fileService));

		$this->assertSame(expected: [$letter, $scan], actual: $files);
	}//end testReadingThroughTheInterfaceKeepsOnlyFiles()

	/**
	 * An object without files answers an empty list.
	 *
	 * @return void
	 */
	public function testAnObjectWithoutFilesAnswersAnEmptyList(): void {
		$fileService = $this->createMock(originalClassName: FileService::class);
		$fileService->method('getFiles')->willReturn([]);

		$this->assertSame(expected: [], actual: $this->readThrough(reader: new ObjectFileReader(fileService: $fileService)));
	}//end testAnObjectWithoutFilesAnswersAnEmptyList()

	/**
	 * An unknown uuid is not hidden as "no files": the not-found reaches the caller.
	 *
	 * @return void
	 */
	public function testAnUnknownObjectIsNotFound(): void {
		$fileService = $this->createMock(originalClassName: FileService::class);
		$fileService->method('getFiles')->willThrowException(new DoesNotExistException('Object not found'));

		$this->expectException(exception: DoesNotExistException::class);

		$this->readThrough(reader: new ObjectFileReader(fileService: $fileService));
	}//end testAnUnknownObjectIsNotFound()

	/**
	 * Call the reader as a consumer does: typed on the interface only.
	 *
	 * @param ObjectFileReaderInterface $reader The reader.
	 *
	 * @return array<int,File> The files.
	 */
	private function readThrough(ObjectFileReaderInterface $reader): array {
		return $reader->getObjectFiles(objectUuid: 'client-1');
	}//end readThrough()
}//end class
