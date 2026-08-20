<?php

/**
 * Unit tests for FilesObjectSourceProvider.
 *
 * Covers:
 *  - isEnabled() is always true (Files is a core app)
 *  - findAll() walks the user's home folder and maps File nodes (skipping folders)
 *  - find() resolves by fileid and returns null when absent/non-file
 *  - no acting user degrades to an empty list (fail closed)
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectSource\FilesObjectSourceProvider;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Test class for FilesObjectSourceProvider.
 */
class FilesObjectSourceProviderTest extends TestCase {
	/**
	 * Build a mock File node.
	 *
	 * @param int $id The fileid.
	 * @param string $name The file name.
	 * @param string $path The absolute path.
	 * @param string $mimetype The MIME type.
	 * @param int $size The size in bytes.
	 * @param int $mtime The modification time.
	 *
	 * @return File The mock file.
	 */
	private function file(int $id, string $name, string $path, string $mimetype, int $size, int $mtime): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn($name);
		$file->method('getPath')->willReturn($path);
		$file->method('getMimetype')->willReturn($mimetype);
		$file->method('getSize')->willReturn($size);
		$file->method('getMTime')->willReturn($mtime);
		return $file;
	}//end file()

	/**
	 * Build a provider with an acting user and a stubbed home folder.
	 *
	 * @param IUser|null $acting The acting (session) user, or null.
	 * @param Folder|null $userFolder The user's home folder, or null.
	 *
	 * @return FilesObjectSourceProvider The provider under test.
	 */
	private function provider(?IUser $acting, ?Folder $userFolder): FilesObjectSourceProvider {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($acting);

		$rootFolder = $this->createMock(IRootFolder::class);
		if ($userFolder !== null) {
			$rootFolder->method('getUserFolder')->willReturn($userFolder);
		}

		return new FilesObjectSourceProvider($rootFolder, $session, new NullLogger());
	}//end provider()

	/**
	 * Build a home folder containing the given files plus one sub-folder.
	 *
	 * @param array<int, File> $files The files at the top level.
	 *
	 * @return Folder The stubbed home folder.
	 */
	private function homeFolder(array $files): Folder {
		// A nested folder that itself contains nothing — must be skipped, not mapped.
		$subFolder = $this->createMock(Folder::class);
		$subFolder->method('getDirectoryListing')->willReturn([]);

		$folder = $this->createMock(Folder::class);
		$folder->method('getDirectoryListing')->willReturn(array_merge($files, [$subFolder]));
		$folder->method('getRelativePath')->willReturnCallback(
			static fn (string $path) => ltrim(str_replace('/admin/files', '', $path), '/')
		);
		$folder->method('getById')->willReturnCallback(
			static function ($id) use ($files) {
				foreach ($files as $file) {
					if ($file->getId() === (int)$id) {
						return [$file];
					}
				}

				return [];
			}
		);
		return $folder;
	}//end homeFolder()

	/**
	 * The register/schema pair the provider is bound to.
	 *
	 * @return array{0: Register, 1: Schema} The register and schema.
	 */
	private function binding(): array {
		$register = new Register();
		$register->setId(13);
		$schema = new Schema();
		$schema->setId(130);
		return [$register, $schema];
	}//end binding()

	/**
	 * getId() is the stable provider id.
	 *
	 * @return void
	 */
	public function testGetId(): void {
		$this->assertSame('files-source', $this->provider(null, null)->getId());
	}//end testGetId()

	/**
	 * isEnabled() is always true (core Files app).
	 *
	 * @return void
	 */
	public function testIsEnabledAlwaysTrue(): void {
		$this->assertTrue($this->provider(null, null)->isEnabled());
	}//end testIsEnabledAlwaysTrue()

	/**
	 * findAll() maps files and skips folders.
	 *
	 * @return void
	 */
	public function testFindAllMapsFiles(): void {
		[$register, $schema] = $this->binding();
		$acting = $this->createMock(IUser::class);
		$acting->method('getUID')->willReturn('admin');

		$report = $this->file(42, 'report.pdf', '/admin/files/docs/report.pdf', 'application/pdf', 2048, 1700000000);
		$notes = $this->file(43, 'notes.txt', '/admin/files/notes.txt', 'text/plain', 12, 1700000100);

		$objects = $this->provider($acting, $this->homeFolder([$report, $notes]))->findAll($register, $schema);

		$this->assertCount(2, $objects);
		$data = $objects[0]->getObject();
		$this->assertSame('42', $data['id']);
		$this->assertSame('report.pdf', $data['name']);
		$this->assertSame('docs/report.pdf', $data['path']);
		$this->assertSame('application/pdf', $data['mimetype']);
		$this->assertSame(2048, $data['size']);
		$this->assertSame(1700000000, $data['mtime']);
		$this->assertSame('42', $objects[0]->getUuid());
		$this->assertSame('130', $objects[0]->getSchema());
	}//end testFindAllMapsFiles()

	/**
	 * find() resolves by fileid and returns null when absent.
	 *
	 * @return void
	 */
	public function testFindByFileId(): void {
		[$register, $schema] = $this->binding();
		$acting = $this->createMock(IUser::class);
		$acting->method('getUID')->willReturn('admin');

		$report = $this->file(42, 'report.pdf', '/admin/files/docs/report.pdf', 'application/pdf', 2048, 1700000000);
		$provider = $this->provider($acting, $this->homeFolder([$report]));

		$this->assertSame('report.pdf', $provider->find($register, $schema, '42')?->getObject()['name']);
		$this->assertNull($provider->find($register, $schema, '999'));
		$this->assertNull($provider->find($register, $schema, 'not-numeric'));
	}//end testFindByFileId()

	/**
	 * No acting user degrades findAll to an empty list.
	 *
	 * @return void
	 */
	public function testNoActingUserEmptyList(): void {
		[$register, $schema] = $this->binding();
		$this->assertCount(0, $this->provider(null, null)->findAll($register, $schema));
	}//end testNoActingUserEmptyList()
}//end class
