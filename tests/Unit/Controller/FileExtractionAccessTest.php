<?php

/**
 * The file extraction listing, detail and statistics, and file anonymisation,
 * follow the caller's access to the file.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\FileExtractionController;
use OCA\OpenRegister\Controller\FileTextController;
use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Service\File\ManualEntityService;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\RiskLevelService;
use OCA\OpenRegister\Service\TextExtractionService;
use OCA\OpenRegister\Service\VectorizationService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FileExtractionAccessTest extends TestCase {

	private IRequest&MockObject $request;

	private IRootFolder&MockObject $rootFolder;

	private IUserSession&MockObject $session;

	private IGroupManager&MockObject $groups;

	private ChunkMapper&MockObject $chunks;

	private FileService&MockObject $files;

	protected function setUp(): void {
		$this->request    = $this->createMock(IRequest::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->session    = $this->createMock(IUserSession::class);
		$this->groups     = $this->createMock(IGroupManager::class);
		$this->chunks     = $this->createMock(ChunkMapper::class);
		$this->files      = $this->createMock(FileService::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$this->session->method('getUser')->willReturn($user);
		$this->groups->method('isAdmin')->willReturn(false);

		// Bob's home reaches file 1 only.
		$home = $this->createMock(Folder::class);
		$home->method('getById')->willReturnCallback(fn (int $id): array => ($id === 1 ? [$this->createMock(\OCP\Files\File::class)] : []));
		$this->rootFolder->method('getUserFolder')->willReturn($home);
	}//end setUp()

	private function extraction(): FileExtractionController {
		return new FileExtractionController(
			'openregister',
			$this->request,
			$this->createMock(TextExtractionService::class),
			$this->createMock(VectorizationService::class),
			$this->chunks,
			$this->createMock(EntityRelationMapper::class),
			$this->createMock(RiskLevelService::class),
			$this->rootFolder,
			$this->session,
			$this->groups
		);
	}//end extraction()

	private function text(): FileTextController {
		return new FileTextController(
			'openregister',
			$this->request,
			$this->createMock(TextExtractionService::class),
			$this->files,
			$this->createMock(EntityRelationMapper::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(ManualEntityService::class),
			$this->session,
			$this->rootFolder,
			$this->groups
		);
	}//end text()

	public function testTheDetailOfAFileTheCallerCannotOpenIsNotFound(): void {
		$this->chunks->expects($this->never())->method('findBySource');

		$this->assertSame(404, $this->extraction()->show(2)->getStatus());
	}//end testTheDetailOfAFileTheCallerCannotOpenIsNotFound()

	public function testStatisticsAreForAdministrators(): void {
		$this->assertSame(403, $this->extraction()->stats()->getStatus());
	}//end testStatisticsAreForAdministrators()

	public function testTheExtractionStatisticsRouteIsForAdministratorsToo(): void {
		$this->assertSame(403, $this->text()->getStats()->getStatus());
	}//end testTheExtractionStatisticsRouteIsForAdministratorsToo()

	public function testTheListingHoldsOnlyFilesTheCallerCanOpen(): void {
		$this->request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $default);
		$this->chunks->method('getFileSourceSummaries')->willReturn([
			['sourceId' => 1, 'chunkCount' => 1, 'lastExtracted' => null, 'owner' => 'bob', 'organisation' => null, 'language' => null, 'fileName' => 'mine.pdf', 'mimeType' => null, 'fileSize' => null],
			['sourceId' => 2, 'chunkCount' => 1, 'lastExtracted' => null, 'owner' => 'alice', 'organisation' => null, 'language' => null, 'fileName' => 'secret.pdf', 'mimeType' => null, 'fileSize' => null],
		]);
		$this->chunks->method('countFileSourceSummaries')->willReturn(2);

		$data = $this->extraction()->index()->getData();

		$this->assertSame([1], array_map(static fn (array $row): int => (int)$row['id'], $data['data']));
		$this->assertSame(1, $data['count']);
	}//end testTheListingHoldsOnlyFilesTheCallerCanOpen()

	public function testAnonymisingAFileTheCallerCannotOpenIsNotFound(): void {
		$this->files->expects($this->never())->method('getFileById');

		$this->assertSame(404, $this->text()->anonymizeFile(2)->getStatus());
	}//end testAnonymisingAFileTheCallerCannotOpenIsNotFound()
}//end class
