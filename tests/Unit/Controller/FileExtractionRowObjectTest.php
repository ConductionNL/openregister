<?php

/**
 * The file listing names the object that carries each file, so a row can be
 * shared by link as `<object uuid>/<file id>`.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-publication-link-opens-one-object-view-or-file-as-its-own-principal-req-abl-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Controller\FileExtractionController;
use OCA\OpenRegister\Files\Controller\Support\FileExtractionControllerServices;
use OCA\OpenRegister\Files\Controller\Support\FileExtractionDomain;
use OCA\OpenRegister\Files\Controller\Support\FileExtractionMappers;
use OCA\OpenRegister\Files\Controller\Support\FileExtractionPlatform;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Files\Service\RiskLevelService;
use OCA\OpenRegister\Files\Service\TextExtractionService;
use OCA\OpenRegister\Search\Service\VectorizationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Rows of /api/files carry `objectUuid` exactly when an object carries the file.
 *
 * @covers \OCA\OpenRegister\Files\Controller\FileExtractionController
 * @uses \OCA\OpenRegister\Files\Controller\Support\FileExtractionControllerServices
 * @uses \OCA\OpenRegister\Files\Controller\Support\FileExtractionDomain
 * @uses \OCA\OpenRegister\Files\Controller\Support\FileExtractionMappers
 * @uses \OCA\OpenRegister\Files\Controller\Support\FileExtractionPlatform
 * @uses \OCA\OpenRegister\Files\Controller\Support\FileExtractionListing
 * @uses \OCA\OpenRegister\Files\Service\File\CallerFileAccess
 * @uses \OCA\OpenRegister\Service\File\ManagedFileOwnerLookup
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Files\Db\FileSourceOrder
 */
class FileExtractionRowObjectTest extends TestCase {

	private ChunkMapper&MockObject $chunkMapper;
	private IRootFolder&MockObject $rootFolder;
	private MagicObjectFinderInterface&MockObject $objectFinder;

	/**
	 * Two listed files: 42 in the folder (id 7) of object `obj-1`, 43 in a uuid-named folder no object owns.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->chunkMapper = $this->createMock(ChunkMapper::class);
		$this->chunkMapper->method('getFileSourceSummaries')->willReturn(
			[
				['sourceId' => 42, 'fileName' => 'besluit.pdf', 'mimeType' => 'application/pdf', 'fileSize' => 10, 'chunkCount' => 1, 'lastExtracted' => '2026-01-02T00:00:00Z'],
				['sourceId' => 43, 'fileName' => 'los.pdf', 'mimeType' => 'application/pdf', 'fileSize' => 10, 'chunkCount' => 1, 'lastExtracted' => '2026-01-01T00:00:00Z'],
			]
		);
		$this->chunkMapper->method('countFileSourceSummaries')->willReturn(2);

		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->rootFolder->method('getFirstNodeById')->willReturnCallback(
			fn (int $id): File => $this->fileIn(folderName: ($id === 42) ? 'obj-1' : 'stray-uuid', folderId: ($id === 42) ? 7 : 8)
		);

		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setFolder('7');
		$this->objectFinder = $this->createMock(MagicObjectFinderInterface::class);
		$this->objectFinder->method('findAcrossAllSources')->willReturnCallback(
			static function (string|int $identifier) use ($object): array {
				if ($identifier === 'obj-1') {
					return ['object' => $object];
				}

				throw new DoesNotExistException('none');
			}
		);
	}//end setUp()

	/**
	 * A file in a folder.
	 *
	 * @param string $folderName The folder's name.
	 * @param int    $folderId   The folder's id.
	 *
	 * @return File
	 */
	private function fileIn(string $folderName, int $folderId): File {
		$folder = $this->createMock(Folder::class);
		$folder->method('getName')->willReturn($folderName);
		$folder->method('getId')->willReturn($folderId);
		$file = $this->createMock(File::class);
		$file->method('getParent')->willReturn($folder);
		return $file;
	}//end fileIn()

	/**
	 * The controller, listing as an admin.
	 *
	 * @param MagicObjectFinderInterface|null $finder The object finder, or none.
	 *
	 * @return FileExtractionController
	 */
	private function controller(?MagicObjectFinderInterface $finder): FileExtractionController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $default);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);

		return new FileExtractionController(
			$request,
			new FileExtractionControllerServices(
				mappers: new FileExtractionMappers(
					chunkMapper: $this->chunkMapper,
					entityRelationMapper: $this->createMock(EntityRelationMapper::class),
					objectFinder: $finder
				),
				domain: new FileExtractionDomain(
					textExtractor: $this->createMock(TextExtractionService::class),
					vectorizationService: $this->createMock(VectorizationService::class),
					riskLevelService: $this->createMock(RiskLevelService::class)
				),
				platform: new FileExtractionPlatform(rootFolder: $this->rootFolder, userSession: $session, groupManager: $groups)
			)
		);
	}//end controller()

	/**
	 * The object's file names its object; the stray file names none.
	 *
	 * @return void
	 */
	public function testOnlyAFileAnObjectCarriesNamesItsObject(): void {
		$rows = $this->controller(finder: $this->objectFinder)->index()->getData()['data'];
		$byId = array_column($rows, null, 'id');

		self::assertSame('obj-1', $byId[42]['objectUuid']);
		self::assertArrayNotHasKey('objectUuid', $byId[43]);
	}//end testOnlyAFileAnObjectCarriesNamesItsObject()

	/**
	 * Without a finder the rows are as before.
	 *
	 * @return void
	 */
	public function testWithoutAFinderTheRowsAreUnchanged(): void {
		$this->rootFolder->expects(self::never())->method('getFirstNodeById');

		$rows = $this->controller(finder: null)->index()->getData()['data'];

		self::assertCount(2, $rows);
		foreach ($rows as $row) {
			self::assertArrayNotHasKey('objectUuid', $row);
		}
	}//end testWithoutAFinderTheRowsAreUnchanged()
}//end class
