<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\File
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterFolderRecorder;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\File\ManagedFileOwnerLookup;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The object a managed file belongs to: "no object owns it" and "the lookup failed" are different answers.
 *
 * @covers \OCA\OpenRegister\Service\File\ManagedFileOwnerLookup
 * @covers \OCA\OpenRegister\Service\File\FolderManagementHandler::getObjectForFile
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 */
class ManagedFileOwnerLookupTest extends TestCase {

	/**
	 * @var MagicMapper&MockObject
	 */
	private MagicMapper $objects;

	private FolderManagementHandler $handler;

	private ManagedFileOwnerLookup $lookup;

	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(MagicMapper::class);
		$this->lookup = new ManagedFileOwnerLookup($this->objects);
		$this->handler = new FolderManagementHandler(
			rootFolder: $this->createMock(IRootFolder::class),
			objectEntityMapper: $this->objects,
			registerMapper: $this->createMock(RegisterMapper::class),
			userSession: $this->createMock(IUserSession::class),
			groupManager: $this->createMock(IGroupManager::class),
			logger: $this->createMock(LoggerInterface::class),
			auditTrailMapper: $this->createMock(AuditTrailMapper::class),
			mountCache: $this->createMock(IUserMountCache::class),
			folderRecorder: $this->createMock(RegisterFolderRecorder::class)
		);
	}//end setUp()

	/**
	 * A file inside a folder named `$name` with id `$id`.
	 *
	 * @param string $name The folder's name.
	 * @param int    $id   The folder's id.
	 *
	 * @return File&MockObject
	 */
	private function fileIn(string $name, int $id): File {
		$parent = $this->createMock(Folder::class);
		$parent->method('getName')->willReturn($name);
		$parent->method('getId')->willReturn($id);
		$file = $this->createMock(File::class);
		$file->method('getParent')->willReturn($parent);

		return $file;
	}//end fileIn()

	/**
	 * An object that records the given folder.
	 *
	 * @param int $folder The folder id it records.
	 *
	 * @return ObjectEntity
	 */
	private function objectWithFolder(int $folder): ObjectEntity {
		$object = new ObjectEntity();
		$object->setFolder((string)$folder);

		return $object;
	}//end objectWithFolder()

	/**
	 * The owning object is found when the folder agrees with its record.
	 *
	 * @return void
	 */
	public function testTheObjectWhoseFolderItIsIsFound(): void {
		$object = $this->objectWithFolder(7);
		$this->objects->method('findAcrossAllSources')->willReturn(['object' => $object]);

		$this->assertSame($object, $this->lookup->find(file: $this->fileIn('some-uuid', 7)));
		$this->assertSame($object, $this->handler->getObjectForFile(file: $this->fileIn('some-uuid', 7)));
	}//end testTheObjectWhoseFolderItIsIsFound()

	/**
	 * A folder that merely carries an object's name, or no object's name, is "no owner", not a failure.
	 *
	 * @return void
	 */
	public function testAFileWithoutAnOwnerIsNullNotAnError(): void {
		$this->objects->method('findAcrossAllSources')->willReturnOnConsecutiveCalls(
			['object' => $this->objectWithFolder(9)],
			['object' => null],
		);
		$this->assertNull($this->lookup->find(file: $this->fileIn('some-uuid', 7)), 'another folder is recorded');
		$this->assertNull($this->lookup->find(file: $this->fileIn('some-uuid', 7)), 'no object came back');

		$missing = $this->createMock(MagicMapper::class);
		$missing->method('findAcrossAllSources')->willThrowException(new DoesNotExistException('no such object'));
		$this->assertNull((new ManagedFileOwnerLookup($missing))->find(file: $this->fileIn('some-uuid', 7)));
	}//end testAFileWithoutAnOwnerIsNullNotAnError()

	/**
	 * A failing lookup propagates from findObjectForFile and still reads as "no object" from getObjectForFile.
	 *
	 * @return void
	 */
	public function testAFailedLookupThrowsFromTheStrictReadAndIsSwallowedByTheLenientOne(): void {
		$this->objects->method('findAcrossAllSources')->willThrowException(new RuntimeException('database is gone'));

		$this->assertNull($this->handler->getObjectForFile(file: $this->fileIn('some-uuid', 7)));

		$this->expectException(RuntimeException::class);
		$this->lookup->find(file: $this->fileIn('some-uuid', 7));
	}//end testAFailedLookupThrowsFromTheStrictReadAndIsSwallowedByTheLenientOne()
}//end class
