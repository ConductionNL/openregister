<?php

/**
 * What the Files object source answers when there is no user, the user's
 * folder cannot be resolved, a folder cannot be listed, or a file cannot be
 * read: an empty answer and a warning, never an exception.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Service\ObjectSource\SourcedTable;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectSource\FilesObjectSourceProvider;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Failure-path tests for {@see FilesObjectSourceProvider}.
 */
class FilesObjectSourceProviderFailureTest extends TestCase {

	/**
	 * The root folder.
	 *
	 * @var IRootFolder&MockObject
	 */
	private IRootFolder&MockObject $root;

	/**
	 * The user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The provider under test.
	 *
	 * @var FilesObjectSourceProvider
	 */
	private FilesObjectSourceProvider $provider;

	/**
	 * Build the provider over mocked Files and session.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->root = $this->createMock(IRootFolder::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->provider = new FilesObjectSourceProvider(rootFolder: $this->root, session: new \OCA\OpenRegister\Service\LinkSessionRuntime(userSession: $this->session, logger: $this->logger));
	}//end setUp()

	/**
	 * A logged-in user `noor`.
	 *
	 * @return void
	 */
	private function signedIn(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('noor');
		$this->session->method('getUser')->willReturn($user);
	}//end signedIn()

	/**
	 * Without a user nothing is read, by id or listed.
	 *
	 * @return void
	 */
	public function testWithoutAUserNothingIsRead(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->root->expects($this->never())->method('getUserFolder');

		$this->assertNull($this->provider->find(source: new SourcedTable(new RegisterSchema(new Register(), new Schema())), id: '5'));
		$this->assertSame([], $this->provider->findAll(source: new SourcedTable(new RegisterSchema(new Register(), new Schema()))));
	}//end testWithoutAUserNothingIsRead()

	/**
	 * A non-numeric id is not looked up.
	 *
	 * @return void
	 */
	public function testANonNumericIdIsNotLookedUp(): void {
		$this->signedIn();
		$folder = $this->createMock(Folder::class);
		$folder->expects($this->never())->method('getById');
		$this->root->method('getUserFolder')->with('noor')->willReturn($folder);

		$this->assertNull($this->provider->find(source: new SourcedTable(new RegisterSchema(new Register(), new Schema())), id: 'abc'));
	}//end testANonNumericIdIsNotLookedUp()

	/**
	 * An unresolvable user folder reads as empty and is logged.
	 *
	 * @return void
	 */
	public function testAnUnresolvableUserFolderReadsEmpty(): void {
		$this->signedIn();
		$this->root->method('getUserFolder')->willThrowException(new NotPermittedException('no home'));
		$this->logger->expects($this->exactly(2))
			->method('warning')
			->with('[ObjectSource:files-source] could not resolve user folder: no home');

		$this->assertNull($this->provider->find(source: new SourcedTable(new RegisterSchema(new Register(), new Schema())), id: '5'));
		$this->assertSame([], $this->provider->findAll(source: new SourcedTable(new RegisterSchema(new Register(), new Schema()))));
	}//end testAnUnresolvableUserFolderReadsEmpty()

	/**
	 * A file that cannot be read by id reads as absent and is logged; a
	 * lookup returning only folders reads as absent too.
	 *
	 * @return void
	 */
	public function testAnUnreadableOrNonFileIdReadsAbsent(): void {
		$this->signedIn();
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturnOnConsecutiveCalls(
			$this->throwException(new RuntimeException('storage offline')),
			[$this->createMock(Folder::class)]
		);
		$this->root->method('getUserFolder')->willReturn($folder);
		$this->logger->expects($this->once())->method('warning')->with('[ObjectSource:files-source] could not read file: storage offline');

		$this->assertNull($this->provider->find(source: new SourcedTable(new RegisterSchema(new Register(), new Schema())), id: '5'));
		$this->assertNull($this->provider->find(source: new SourcedTable(new RegisterSchema(new Register(), new Schema())), id: '6'));
	}//end testAnUnreadableOrNonFileIdReadsAbsent()

	/**
	 * A folder that cannot be listed contributes nothing and is logged, while
	 * the files of the folders that can be listed are still returned.
	 *
	 * @return void
	 */
	public function testAnUnlistableFolderIsSkipped(): void {
		$this->signedIn();
		$broken = $this->createMock(Folder::class);
		$broken->method('getDirectoryListing')->willThrowException(new RuntimeException('denied'));
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(11);
		$file->method('getName')->willReturn('a.txt');
		$file->method('getPath')->willReturn('/noor/files/a.txt');
		$file->method('getMimetype')->willReturn('text/plain');
		$file->method('getSize')->willReturn(3);
		$file->method('getMTime')->willReturn(1700000000);
		$home = $this->createMock(Folder::class);
		$home->method('getDirectoryListing')->willReturn([$broken, $file]);
		$home->method('getRelativePath')->willReturn('/a.txt');
		$this->root->method('getUserFolder')->willReturn($home);
		$this->logger->expects($this->once())->method('warning')->with('[ObjectSource:files-source] could not list folder: denied');

		$objects = $this->provider->findAll(source: new SourcedTable(new RegisterSchema(new Register(), new Schema())));

		$this->assertCount(1, $objects);
		$this->assertSame('/a.txt', $objects[0]->getObject()['path']);
		$this->assertSame('a.txt', $objects[0]->getObject()['name']);
	}//end testAnUnlistableFolderIsSkipped()
}//end class
