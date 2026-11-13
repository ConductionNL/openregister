<?php

/**
 * Unit test for FilePublishingHandler's ZIP name: a requested name is a file
 * name in the temporary directory, never a path into another directory.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Files\Service\File\FileRestrictionGuardInterface;
use OCA\OpenRegister\Service\File\FilePublishingHandler;
use OCA\OpenRegister\Service\FileHandlerSupportInterface;
use OCP\Files\File;
use OCP\IServerContainer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\File\FilePublishingHandler
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class FilePublishingHandlerZipNameTest extends TestCase {
	use PassThroughDigestsTrait;

	/**
	 * The ZIP paths a test made, removed afterwards.
	 *
	 * @var array<int, string>
	 */
	private array $made = [];

	/**
	 * Remove the ZIPs the test made.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->made as $path) {
			if (is_file($path) === true) {
				unlink($path);
			}
		}
	}//end tearDown()

	/**
	 * The ZIP an object with one file is packed into, under the requested name.
	 *
	 * @param string $zipName The requested name.
	 *
	 * @return array{path: string, filename: string, size: int, mimeType: string}
	 */
	private function zip(string $zipName): array {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('a.txt');
		$file->method('getContent')->willReturn('alpha');
		$files = $this->createMock(FileHandlerSupportInterface::class);
		$files->method('getFiles')->willReturn([$file]);
		$restrictions = $this->createMock(FileRestrictionGuardInterface::class);
		$restrictions->method('readableNodes')->willReturnArgument(0);
		$container = $this->createMock(IServerContainer::class);
		$container->method('get')->willReturn($restrictions);
		$handler = new FilePublishingHandler(
			objectEntityMapper: $this->createMock(MagicObjectFinderInterface::class),
			fileMapper: $this->createMock(FileMapperInterface::class),
			logger: new NullLogger(),
			container: $container,
			publishedDigests: $this->passThroughDigests()
		);
		$handler->setFileService($files);
		$object = new ObjectEntity();
		$object->setUuid('obj-uuid');

		$result       = $handler->createObjectFilesZip(object: $object, zipName: $zipName);
		$this->made[] = $result['path'];
		return $result;
	}//end zip()

	/**
	 * A plain name is used as it is, in the temporary directory.
	 *
	 * @return void
	 */
	public function testAPlainNameIsUsedInTheTempDirectory(): void {
		$name   = 'n17-plain-'.bin2hex(random_bytes(4)).'.zip';
		$result = $this->zip(zipName: $name);

		$this->assertSame($name, $result['filename']);
		$this->assertSame(sys_get_temp_dir().DIRECTORY_SEPARATOR.$name, $result['path']);
	}//end testAPlainNameIsUsedInTheTempDirectory()

	/**
	 * A name with directory parts keeps only its last part, so the ZIP stays in the temporary directory.
	 *
	 * @return void
	 */
	public function testANameWithDirectoryPartsStaysInTheTempDirectory(): void {
		$base   = 'n17-nested-'.bin2hex(random_bytes(4)).'.zip';
		$result = $this->zip(zipName: 'n17-no-such-dir/../'.$base);

		$this->assertSame($base, $result['filename']);
		$this->assertSame(sys_get_temp_dir().DIRECTORY_SEPARATOR.$base, $result['path']);
		$this->assertFileExists($result['path']);
	}//end testANameWithDirectoryPartsStaysInTheTempDirectory()
}//end class
