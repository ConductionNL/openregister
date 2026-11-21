<?php

/**
 * DocumentStore: the configurable root and the store it names.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Service\File\DocumentStore;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Mount\IMountPoint;
use OCP\Files\Storage\IStorage;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(DocumentStore::class)]
class DocumentStoreTest extends TestCase {

	private IAppConfig&MockObject $appConfig;

	private IRootFolder&MockObject $rootFolder;

	private Folder&MockObject $home;

	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->home = $this->createMock(Folder::class);
		$this->rootFolder->method('getUserFolder')->with('openregister')->willReturn($this->home);
	}//end setUp()

	private function store(?string $configured): DocumentStore {
		$this->appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($app === 'openregister' && $key === 'document_store_root' && $configured !== null) ? $configured : $default
		);
		return new DocumentStore(appConfig: $this->appConfig, rootFolder: $this->rootFolder);
	}//end store()

	private function storage(string $id): IStorage&MockObject {
		$storage = $this->createMock(IStorage::class);
		$storage->method('getId')->willReturn($id);
		return $storage;
	}//end storage()

	/**
	 * Put a root folder on a storage and mount, and the home on its own storage.
	 */
	private function wireRoot(string $name, IStorage $storage, string $mountType, string $mountPoint, int|float $free, string $homeStorage): Folder&MockObject {
		$mount = $this->createMock(IMountPoint::class);
		$mount->method('getMountType')->willReturn($mountType);
		$mount->method('getMountPoint')->willReturn($mountPoint);

		$folder = $this->createMock(Folder::class);
		$folder->method('getStorage')->willReturn($storage);
		$folder->method('getMountPoint')->willReturn($mount);
		$folder->method('getFreeSpace')->willReturn($free);

		$this->home->method('nodeExists')->with($name)->willReturn(true);
		$this->home->method('get')->with($name)->willReturn($folder);
		$this->home->method('getStorage')->willReturn($this->storage($homeStorage));
		return $folder;
	}//end wireRoot()

	public function testTheDefaultRootIsOpenRegisters(): void {
		$this->assertSame('Open Registers', $this->store(null)->root());
	}//end testTheDefaultRootIsOpenRegisters()

	public function testAConfiguredRootIsUsed(): void {
		$this->assertSame('Documents/Registers', $this->store('/Documents/Registers/')->root());
	}//end testAConfiguredRootIsUsed()

	/**
	 * @return array<string, array{string}>
	 */
	public static function unusableRoots(): array {
		return ['empty' => [''], 'slashes' => [' / '], 'leaves the home' => ['../other'], 'climbs inside' => ['Docs/../../x']];
	}//end unusableRoots()

	#[DataProvider('unusableRoots')]
	public function testAnUnusableRootFallsBackToTheDefault(string $configured): void {
		$this->assertSame('Open Registers', $this->store($configured)->root());
	}//end testAnUnusableRootFallsBackToTheDefault()

	public function testAMissingRootIsCreatedInTheHome(): void {
		$created = $this->createMock(Folder::class);
		$this->home->method('nodeExists')->with('Documents')->willReturn(false);
		$this->home->expects($this->once())->method('newFolder')->with('Documents')->willReturn($created);

		$this->assertSame($created, $this->store('Documents')->rootFolder());
	}//end testAMissingRootIsCreatedInTheHome()

	public function testARootThatIsAFileIsRefused(): void {
		$this->home->method('nodeExists')->willReturn(true);
		$this->home->method('get')->willReturn($this->createMock(File::class));

		$this->expectException(RuntimeException::class);
		$this->store(null)->rootFolder();
	}//end testARootThatIsAFileIsRefused()

	public function testDescribeOnPrimaryStorageIsNotSeparate(): void {
		$this->wireRoot('Open Registers', $this->storage('home::openregister'), '', '/openregister/', 1024, 'home::openregister');

		$this->assertSame(
			[
				'root' => 'Open Registers',
				'storageId' => 'home::openregister',
				'backend' => 'local',
				'backendName' => strtolower((new \ReflectionClass($this->storage('x')))->getShortName()),
				'mountPoint' => '/openregister/',
				'separate' => false,
				'freeBytes' => 1024,
			],
			$this->store(null)->describe()
		);
	}//end testDescribeOnPrimaryStorageIsNotSeparate()

	public function testDescribeNamesAnExternalMount(): void {
		$this->wireRoot('Documents', $this->storage('local::/srv/documents/'), 'external', '/openregister/files/Documents/', -2, 'home::openregister');

		$described = $this->store('Documents')->describe();

		$this->assertSame('external', $described['backend']);
		$this->assertSame('local::/srv/documents/', $described['storageId']);
		$this->assertSame('/openregister/files/Documents/', $described['mountPoint']);
		$this->assertTrue($described['separate']);
		$this->assertNull($described['freeBytes'], 'an unknown free space is null, not a negative number');
	}//end testDescribeNamesAnExternalMount()

	public function testDescribeNamesObjectStorage(): void {
		$this->wireRoot('Open Registers', $this->storage('object::store:amazon::bucket'), '', '/openregister/', 0, 'object::store:amazon::bucket');

		$described = $this->store(null)->describe();

		$this->assertSame('object-storage', $described['backend']);
		$this->assertFalse($described['separate']);
		$this->assertSame(0, $described['freeBytes']);
	}//end testDescribeNamesObjectStorage()
}//end class
