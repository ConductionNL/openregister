<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\FileTextController;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Service\File\ManualEntityService;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\TextExtractionService;
use OCP\AppFramework\Http;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FileTextControllerDeepTest extends TestCase {

	private FileTextController $controller;

	private IRequest|MockObject $request;

	private TextExtractionService|MockObject $textExtractor;

	private FileService|MockObject $fileService;

	private EntityRelationMapper|MockObject $entityRelationMapper;

	private LoggerInterface|MockObject $logger;

	private IAppConfig|MockObject $config;

	private ManualEntityService|MockObject $manualEntityService;

	private IUserSession|MockObject $userSession;

	private IRootFolder|MockObject $rootFolder;

	private IGroupManager|MockObject $groupManager;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->textExtractor = $this->createMock(TextExtractionService::class);
		$this->fileService = $this->createMock(FileService::class);
		$this->entityRelationMapper = $this->createMock(EntityRelationMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->config = $this->createMock(IAppConfig::class);
		$this->manualEntityService = $this->createMock(ManualEntityService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$admin = $this->createMock(IUser::class);
		$admin->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($admin);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->willReturn([$this->createMock(Node::class)]);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);
		$this->groupManager->method('isAdmin')->willReturn(true);

		$this->controller = new FileTextController(
			'openregister',
			$this->request,
			$this->textExtractor,
			$this->fileService,
			$this->entityRelationMapper,
			$this->logger,
			$this->config,
			$this->manualEntityService,
			$this->userSession,
			$this->rootFolder,
			$this->groupManager
		);
	}//end setUp()

	public function testExtractFileTextWhenDisabled(): void {
		$this->config->method('hasKey')->willReturn(false);
		$this->config->method('getValueString')->willReturn('{}');

		$response = $this->controller->extractFileText(42);

		$this->assertEquals(Http::STATUS_NOT_IMPLEMENTED, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}//end testExtractFileTextWhenDisabled()

	public function testExtractFileTextWhenScopeNone(): void {
		$this->config->method('hasKey')->willReturn(true);
		$this->config->method('getValueString')->willReturn('{"extractionScope":"none"}');

		$response = $this->controller->extractFileText(42);

		$this->assertEquals(Http::STATUS_NOT_IMPLEMENTED, $response->getStatus());
	}//end testExtractFileTextWhenScopeNone()

	public function testExtractFileTextSuccess(): void {
		$this->config->method('hasKey')->willReturn(true);
		$this->config->method('getValueString')->willReturn('{"extractionScope":"all"}');
		$this->textExtractor->expects($this->once())
			->method('extractFile')
			->with(42, true);

		$response = $this->controller->extractFileText(42);

		$this->assertEquals(200, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}//end testExtractFileTextSuccess()

	public function testExtractFileTextException(): void {
		$this->config->method('hasKey')->willReturn(true);
		$this->config->method('getValueString')->willReturn('{"extractionScope":"all"}');
		$this->textExtractor->method('extractFile')
			->willThrowException(new \Exception('extract error'));

		$response = $this->controller->extractFileText(42);

		$this->assertEquals(500, $response->getStatus());
		$this->assertStringContainsString('extract error', $response->getData()['message']);
	}//end testExtractFileTextException()

	public function testBulkExtractException(): void {
		$this->request->method('getParam')->willReturn(100);
		$this->textExtractor->method('extractPendingFiles')
			->willThrowException(new \Exception('bulk fail'));

		$response = $this->controller->bulkExtract();

		$this->assertEquals(500, $response->getStatus());
		$this->assertStringContainsString('bulk fail', $response->getData()['message']);
	}//end testBulkExtractException()

	public function testGetStatsException(): void {
		$this->textExtractor->method('getStats')
			->willThrowException(new \Exception('stats error'));

		$response = $this->controller->getStats();

		$this->assertEquals(500, $response->getStatus());
	}//end testGetStatsException()

	public function testAnonymizeFileNotFound(): void {
		$this->fileService->method('getFileById')->willReturn(null);

		$response = $this->controller->anonymizeFile(999);

		$this->assertEquals(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAnonymizeFileNotFound()

	public function testAnonymizeFileAlreadyAnonymized(): void {
		$fileNode = $this->createMock(\OCP\Files\File::class);
		$fileNode->method('getName')->willReturn('document_anonymized.pdf');
		$this->fileService->method('getFileById')->willReturn($fileNode);

		$response = $this->controller->anonymizeFile(1);

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertEquals('File is already anonymized', $response->getData()['message']);
	}//end testAnonymizeFileAlreadyAnonymized()

	public function testAnonymizeFileNoEntities(): void {
		$fileNode = $this->createMock(\OCP\Files\File::class);
		$fileNode->method('getName')->willReturn('document.pdf');
		$this->fileService->method('getFileById')->willReturn($fileNode);
		$this->entityRelationMapper->method('findEntitiesForAnonymization')->willReturn([]);

		$response = $this->controller->anonymizeFile(1);

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testAnonymizeFileNoEntities()

	public function testAnonymizeFileException(): void {
		$this->fileService->method('getFileById')
			->willThrowException(new \Exception('anon error'));

		$response = $this->controller->anonymizeFile(1);

		$this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
	}//end testAnonymizeFileException()
}//end class
