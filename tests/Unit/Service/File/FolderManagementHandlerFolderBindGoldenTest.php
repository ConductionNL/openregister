<?php

declare(strict_types=1);

/**
 * Golden tests pinning the folder-bind outcomes of FolderManagementHandler.
 *
 * Pins, per denial branch, the audit row written before the throw (action,
 * actor, folder, reason and the object context), the allowed outcome, and the
 * bookkeeping call the auto-create path makes with the folder value it read.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/self-folder-access-control/spec.md
 */

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Exception\FolderAccessDeniedException;
use OCA\OpenRegister\Files\Db\RegisterFolderRecorder;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\File\FolderAccessCheck;

/**
 * Golden tests for the folder-bind check, its denial audit and the folder bookkeeping.
 */
class FolderManagementHandlerFolderBindGoldenTest extends TestCase {

	/**
	 * @var IRootFolder&MockObject
	 */
	private IRootFolder $rootFolder;

	/**
	 * @var MagicMapper&MockObject
	 */
	private MagicMapper $objectEntityMapper;

	/**
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper $registerMapper;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * @var AuditTrailMapper&MockObject
	 */
	private AuditTrailMapper $auditTrailMapper;

	/**
	 * The audit rows written so far.
	 *
	 * @var AuditTrail[]
	 */
	private array $audits = [];

	private FolderManagementHandler $handler;

	protected function setUp(): void {
		parent::setUp();

		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->objectEntityMapper = $this->createMock(MagicMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->auditTrailMapper->method('insert')->willReturnCallback(
			function (AuditTrail $entity): AuditTrail {
				$this->audits[] = $entity;
				return $entity;
			}
		);

		$this->handler = new FolderManagementHandler(
			rootFolder: $this->rootFolder,
			objectEntityMapper: $this->objectEntityMapper,
			registerMapper: $this->registerMapper,
			userSession: $this->userSession,
			groupManager: $this->createMock(IGroupManager::class),
			logger: $this->createMock(LoggerInterface::class),
			auditTrailMapper: $this->auditTrailMapper,
			mountCache: $this->createMock(IUserMountCache::class),
			folderRecorder: $this->createMock(RegisterFolderRecorder::class)
		);

	}//end setUp()

	/**
	 * An object bound to the given folder value, in register 3 and schema 5.
	 *
	 * @param string|null $folder The folder value.
	 *
	 * @return ObjectEntity
	 */
	private function object(?string $folder): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('obj-uuid');
		$entity->setRegister('3');
		$entity->setSchema('5');
		if ($folder !== null) {
			$entity->setFolder($folder);
		}

		return $entity;
	}//end object()

	/**
	 * A user with the given uid.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser&MockObject
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	/**
	 * Have `$uid`'s user folder resolve `$folderId` to `$nodes`.
	 *
	 * @param string $uid      The uid.
	 * @param int    $folderId The looked-up id.
	 * @param array  $nodes    The nodes found.
	 *
	 * @return void
	 */
	private function lookup(string $uid, int $folderId, array $nodes): void {
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->with($folderId)->willReturn($nodes);
		$this->rootFolder->method('getUserFolder')->with($uid)->willReturn($userFolder);
	}//end lookup()

	/**
	 * The one audit row written, as the fields a forensic reader uses.
	 *
	 * @return array
	 */
	private function onlyAudit(): array {
		$this->assertCount(1, $this->audits);
		$row = $this->audits[0];
		return [
			'action'     => $row->getAction(),
			'user'       => $row->getUser(),
			'userName'   => $row->getUserName(),
			'session'    => $row->getSession(),
			'object'     => $row->getObject(),
			'objectUuid' => $row->getObjectUuid(),
			'register'   => $row->getRegister(),
			'schema'     => $row->getSchema(),
			'changed'    => $row->getChanged(),
		];
	}//end onlyAudit()

	/**
	 * The audit row expected for a denial by `$actor` with `$reason`.
	 *
	 * @param string $folder The folder id.
	 * @param string $actor  The actor uid.
	 * @param string $reason The reason.
	 *
	 * @return array
	 */
	private function denial(string $folder, string $actor, string $reason): array {
		return [
			'action'     => 'folder_access_denied',
			'user'       => $actor,
			'userName'   => $actor,
			'session'    => '',
			'object'     => 0,
			'objectUuid' => 'obj-uuid',
			'register'   => 3,
			'schema'     => 5,
			'changed'    => ['folder' => $folder, 'reason' => $reason],
		];
	}//end denial()

	/**
	 * Run a check that must be refused and return the audit row it wrote.
	 *
	 * @param string     $folder The folder id.
	 * @param IUser|null $user   The explicit acting user.
	 *
	 * @return array
	 */
	private function refused(string $folder, ?IUser $user): array {
		try {
			$this->handler->assertFolderIsAccessible(check: new FolderAccessCheck(folderId: $folder, currentUser: $user, objectEntity: $this->object(folder: $folder)));
			$this->fail('The bind must be refused');
		} catch (FolderAccessDeniedException $e) {
			$this->assertStringContainsString($folder, $e->getMessage());
		}

		return $this->onlyAudit();
	}//end refused()

	public function testNoActorIsRefusedAsSystem(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->assertSame(
			$this->denial('42', 'system', 'no acting user (default-deny when no IUser is present)'),
			$this->refused('42', null)
		);
	}//end testNoActorIsRefusedAsSystem()

	public function testLookupErrorIsRefusedWithTheCause(): void {
		$this->rootFolder->method('getUserFolder')->willThrowException(new \RuntimeException('mount gone'));

		try {
			$this->handler->assertFolderIsAccessible(check: new FolderAccessCheck(folderId: '42', currentUser: $this->user('bob'), objectEntity: $this->object(folder: '42')));
			$this->fail('The bind must be refused');
		} catch (FolderAccessDeniedException $e) {
			$this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
		}

		$this->assertSame($this->denial('42', 'bob', 'lookup error: mount gone'), $this->onlyAudit());
	}//end testLookupErrorIsRefusedWithTheCause()

	public function testFolderOutsideTheMountIsRefused(): void {
		$this->lookup('bob', 42, []);

		$this->assertSame($this->denial('42', 'bob', 'not found in user folder mount'), $this->refused('42', $this->user('bob')));
	}//end testFolderOutsideTheMountIsRefused()

	public function testFileIsRefused(): void {
		$this->lookup('bob', 42, [$this->createMock(File::class)]);

		$this->assertSame(
			$this->denial('42', 'bob', 'resolved node is not a Folder (file ID supplied?)'),
			$this->refused('42', $this->user('bob'))
		);
	}//end testFileIsRefused()

	public function testUnreadableFolderIsRefusedAsTheSessionUser(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('isReadable')->willReturn(false);
		$this->lookup('carol', 42, [$folder]);
		$this->userSession->method('getUser')->willReturn($this->user('carol'));

		$this->assertSame($this->denial('42', 'carol', 'folder not readable for acting user'), $this->refused('42', null));
	}//end testUnreadableFolderIsRefusedAsTheSessionUser()

	public function testStringObjectLeavesTheObjectContextEmpty(): void {
		$this->lookup('bob', 42, []);

		try {
			$this->handler->assertFolderIsAccessible(check: new FolderAccessCheck(folderId: '42', currentUser: $this->user('bob'), objectEntity: 'obj-uuid'));
			$this->fail('The bind must be refused');
		} catch (FolderAccessDeniedException) {
			$row = $this->onlyAudit();
		}

		$this->assertNull($row['objectUuid']);
		$this->assertNull($row['register']);
		$this->assertNull($row['schema']);
	}//end testStringObjectLeavesTheObjectContextEmpty()

	public function testReadableFolderIsAllowedWithoutAudit(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('isReadable')->willReturn(true);
		$this->lookup('bob', 42, [$folder]);

		$this->assertSame(
			$folder,
			$this->handler->assertFolderIsAccessible(check: new FolderAccessCheck(folderId: '42', currentUser: $this->user('bob'), objectEntity: $this->object(folder: '42')))
		);
		$this->assertSame([], $this->audits);
	}//end testReadableFolderIsAllowedWithoutAudit()

	/**
	 * The auto-create path records the new folder id against the value it read.
	 *
	 * @return array
	 */
	public static function readValues(): array {
		return [
			'no folder yet' => [null],
			'legacy path'   => ['legacy/path'],
		];
	}//end readValues()

	/**
	 * @dataProvider readValues
	 */
	public function testAutoCreateRecordsTheFolderAgainstTheValueRead(?string $read): void {
		$register = new Register();
		$register->setFolder('7');
		$this->registerMapper->method('find')->willReturn($register);

		$objectFolder = $this->createMock(Folder::class);
		$objectFolder->method('getId')->willReturn(123);
		$registerFolder = $this->createMock(Folder::class);
		$registerFolder->method('get')->with('obj-uuid')->willThrowException(new NotFoundException());
		$registerFolder->method('newFolder')->with('obj-uuid')->willReturn($objectFolder);
		$this->rootFolder->method('getById')->with(7)->willReturn([$registerFolder]);

		$object = $this->object(folder: $read);
		$this->objectEntityMapper->expects($this->once())->method('recordFolder')
			->with($object, $read, '123')->willReturn(true);

		$this->assertSame($objectFolder, $this->handler->createObjectFolderById(objectEntity: $object));
		$this->assertSame('123', $object->getFolder());
		$this->assertSame([], $this->audits);
	}//end testAutoCreateRecordsTheFolderAgainstTheValueRead()

}//end class
