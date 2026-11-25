<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Files\Controller\FileTextController;
use OCA\OpenRegister\Files\Service\File\ManualEntityService;
use OCA\OpenRegister\Files\Service\TextExtractionService;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\FileTextControllerFactory;
use OCA\OpenRegister\Tests\Unit\Service\File\PdfAnonymiseHarness;
use OCA\OpenRegister\Tests\Unit\Service\File\PdfImageFixture;
use OCP\AppFramework\Http;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * `POST /api/files/{id}/anonymize` burns a PDF's decided image regions through the seam.
 *
 * The controller, the file service's anonymisation and the handler are real;
 * only the file lookup, the caller's (non-admin) session, the relation rows and
 * the storage are doubles.
 *
 * @covers \OCA\OpenRegister\Files\Controller\FileTextController
 *
 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-every-redaction-path-reaches-the-seam-req-ais-004
 */
final class FileTextControllerImageTest extends TestCase {
	use PdfAnonymiseHarness;

	protected function setUp(): void {
		$this->startHarness();
	}//end setUp()

	private function controller(string $pdf): FileTextController {
		$fileService = $this->getMockBuilder(FileService::class)->disableOriginalConstructor()->onlyMethods(['getFileById'])->getMock();
		$fileService->method('getFileById')->willReturn($this->pdfNode($pdf));
		(new ReflectionProperty(FileService::class, 'documentProcessingHandler'))->setValue($fileService, $this->pdfHandler());
		$this->relations->method('findEntitiesForAnonymization')->willReturn([['entity_value' => 'Jan Jansen', 'entity_type' => 'PERSON']]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('behandelaar');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->willReturn([$this->createMock(Node::class)]);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);

		return FileTextControllerFactory::create(
			'openregister',
			$this->createMock(IRequest::class),
			$this->createMock(TextExtractionService::class),
			$fileService,
			$this->relations,
			$this->createMock(LoggerInterface::class),
			$this->createMock(ManualEntityService::class),
			$session,
			$rootFolder,
			$this->createMock(IGroupManager::class)
		);
	}//end controller()

	public function testAScannedPdfWithAnOcrLocatedNameIsBurnedAndAnswered(): void {
		$this->decidedRegion(page: 1);

		$response = $this->controller(PdfImageFixture::onePage(PdfImageFixture::jpegImage()))->anonymizeFile(17);

		$this->assertSame(Http::STATUS_OK, $response->getStatus(), 'A scan is not refused for its missing text layer when it has decided regions.');
		$this->assertTrue($response->getData()['success']);
		$this->assertSame(["\0\0\0"], PdfImageFixture::pixels($this->outputSamples(), 3, [0, 0, 15, 15]));
	}//end testAScannedPdfWithAnOcrLocatedNameIsBurnedAndAnswered()

	public function testATextPdfIsReplacedAndBurnedInOneOutput(): void {
		$this->decidedRegion(page: 1);

		$response = $this->controller(PdfImageFixture::onePage(PdfImageFixture::jpegImage(), 'Geachte Jan Jansen'))->anonymizeFile(17);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['complete']);
		$this->assertStringNotContainsString('Jan Jansen', (string)$this->output);
		$this->assertSame(["\0\0\0"], PdfImageFixture::pixels($this->outputSamples(), 3, [0, 0, 15, 15]));
	}//end testATextPdfIsReplacedAndBurnedInOneOutput()

	public function testAScanWithoutDecidedRegionsStillAnswersTheMissingTextLayer(): void {
		$this->decidedRegion(page: null);

		$response = $this->controller(PdfImageFixture::onePage(PdfImageFixture::jpegImage()))->anonymizeFile(17);

		$this->assertSame(422, $response->getStatus());
		$this->assertNull($this->output);
	}//end testAScanWithoutDecidedRegionsStillAnswersTheMissingTextLayer()
}//end class
