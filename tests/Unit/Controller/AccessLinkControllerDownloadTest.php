<?php

/**
 * The holder of a file link downloads the file through the link.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
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
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-publication-link-opens-one-object-view-or-file-as-its-own-principal-req-abl-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use DateTime;
use OCA\OpenRegister\Controller\AccessLinkController;
use OCA\OpenRegister\Controller\Support\AccessLinkControllerServices;
use OCA\OpenRegister\Controller\Support\AccessLinkDomain;
use OCA\OpenRegister\Controller\Support\AccessLinkPlatform;
use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Service\Sharing\AccessLinkReader;
use OCA\OpenRegister\Service\Sharing\AccessLinkActs;
use OCA\OpenRegister\Service\Sharing\AccessLinkMintGuard;
use OCA\OpenRegister\Service\Sharing\AccessLinkService;
use OCA\OpenRegister\Service\Sharing\AccessLinkSubjectLinks;
use OCA\OpenRegister\Service\Sharing\AccessLinkUse;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\File;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * `download()` is admitted exactly as `open()` is, serves only the file a file
 * link names, and records the use; `open()` points the file's download address
 * at that route, because the stored one needs a session the holder lacks.
 *
 * @covers \OCA\OpenRegister\Controller\AccessLinkController
 * @uses   \OCA\OpenRegister\Controller\Support\AccessLinkControllerServices
 * @uses   \OCA\OpenRegister\Controller\Support\AccessLinkDomain
 * @uses   \OCA\OpenRegister\Controller\Support\AccessLinkPlatform
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectShareLinkThrottle
 * @uses   \OCA\OpenRegister\Db\AccessLink
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\StoredEntity
 * @uses   \OCA\OpenRegister\Service\Sharing\AccessLinkUse
 * @uses   \OCA\OpenRegister\Service\Sharing\LinkAct
 */
class AccessLinkControllerDownloadTest extends TestCase {

	/** @var AccessLinkService&MockObject */
	private AccessLinkService $links;

	/** @var AccessLinkReader&MockObject */
	private AccessLinkReader $reader;

	/** @var IThrottler&MockObject */
	private IThrottler $throttler;

	/** @var IURLGenerator&MockObject */
	private IURLGenerator $urls;

	/**
	 * Fresh collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->links     = $this->createMock(AccessLinkService::class);
		$this->reader    = $this->createMock(AccessLinkReader::class);
		$this->throttler = $this->createMock(IThrottler::class);
		$this->urls      = $this->createMock(IURLGenerator::class);
		$this->urls->method('linkToRoute')->willReturnCallback(
			static fn (string $route, array $params): string => '/route/' . $route . '/' . $params['anchor']
		);
	}//end setUp()

	/**
	 * The controller, with or without the URL generator.
	 *
	 * @param bool $withUrls Whether the platform bundle carries the URL generator.
	 *
	 * @return AccessLinkController
	 */
	private function controller(bool $withUrls = true): AccessLinkController {
		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturn('198.51.100.7');
		$request->method('getHeader')->willReturn('');
		$request->method('getParam')->willReturnCallback(static fn (string $name, mixed $default = null): mixed => $default);

		$urls = null;
		if ($withUrls === true) {
			$urls = $this->urls;
		}

		return new AccessLinkController(
			request: $request,
			services: new AccessLinkControllerServices(
				domain: new AccessLinkDomain(
					links: $this->links,
					reader: $this->reader,
					mintGuard: $this->createMock(AccessLinkMintGuard::class),
					acts: $this->createMock(AccessLinkActs::class),
					subjectLinks: $this->createMock(AccessLinkSubjectLinks::class),
				),
				platform: new AccessLinkPlatform(
					userSession: $this->createMock(IUserSession::class),
					throttler: $this->throttler,
					logger: $this->createMock(LoggerInterface::class),
				),
				urls: $urls,
			)
		);
	}//end controller()

	/**
	 * A live read link to a file of an object.
	 *
	 * @param string $subjectType The subject type.
	 *
	 * @return AccessLink
	 */
	private function link(string $subjectType = AccessLink::SUBJECT_FILE): AccessLink {
		$link = new AccessLink();
		$link->setAnchor('AnchorValueThatIsOpaque');
		$link->setSubjectType($subjectType);
		$link->setSubjectId('object-uuid/12');
		$link->setCapabilities('read');
		$link->setCreatedBy('owner');
		$link->setExpiresAt(new DateTime('+1 day'));

		return $link;
	}//end link()

	/**
	 * The link is admitted and its object resolves.
	 *
	 * @param AccessLink $link The link.
	 *
	 * @return void
	 */
	private function admitted(AccessLink $link): void {
		$object = new ObjectEntity();
		$object->setUuid('object-uuid');
		$this->links->method('resolve')->willReturn($link);
		$this->links->method('passwordAccepted')->willReturn(true);
		$this->reader->method('subjectObject')->willReturn($object);
	}//end admitted()

	/**
	 * A file node that opens to these bytes.
	 *
	 * @param string $content The bytes.
	 *
	 * @return File&MockObject
	 */
	private function file(string $content): File {
		$stream = fopen('php://memory', 'r+');
		fwrite($stream, $content);
		rewind($stream);

		$file = $this->createMock(File::class);
		$file->method('fopen')->with('rb')->willReturn($stream);
		$file->method('getName')->willReturn('besluit op bezwaar.txt');
		$file->method('getMimeType')->willReturn('text/plain');

		return $file;
	}//end file()

	public function testTheHolderDownloadsTheFileTheLinkNamesAndTheUseIsRecorded(): void {
		$link = $this->link();
		$this->admitted(link: $link);
		$this->reader->expects($this->once())->method('file')->willReturn($this->file(content: 'Besluit'));
		$this->links->expects($this->once())->method('recordUse')->with(
			$link,
			$this->callback(static fn (AccessLinkUse $use): bool => $use->act === AccessLinkService::ACT_READ && $use->ipAddress === '198.51.100.7')
		);

		$response = $this->controller()->download(anchor: 'AnchorValueThatIsOpaque');

		$this->assertInstanceOf(StreamResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$headers = $response->getHeaders();
		$this->assertSame('text/plain', $headers['Content-Type']);
		$this->assertSame('attachment; filename="besluit%20op%20bezwaar.txt"', $headers['Content-Disposition']);
		$this->assertSame('nosniff', $headers['X-Content-Type-Options']);
		$this->assertSame('private, no-store', $headers['Cache-Control']);
	}//end testTheHolderDownloadsTheFileTheLinkNamesAndTheUseIsRecorded()

	public function testALinkTheReaderHasNoFileForAnswersTheUniform404(): void {
		$this->admitted(link: $this->link(subjectType: AccessLink::SUBJECT_OBJECT));
		$this->reader->method('file')->willReturn(null);
		$this->links->expects($this->never())->method('recordUse');
		$this->throttler->expects($this->once())->method('registerAttempt');

		$response = $this->controller()->download(anchor: 'AnchorValueThatIsOpaque');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testALinkTheReaderHasNoFileForAnswersTheUniform404()

	public function testAnUnknownAnchorIs404AndNeverAsksForTheFile(): void {
		$this->links->method('resolve')->willReturn(null);
		$this->reader->expects($this->never())->method('file');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->download(anchor: 'nope')->getStatus());
	}//end testAnUnknownAnchorIs404AndNeverAsksForTheFile()

	public function testAPasswordLinkAsksForItsPasswordBeforeAnyDownload(): void {
		$this->links->method('resolve')->willReturn($this->link());
		$this->links->method('passwordAccepted')->willReturn(false);
		$this->reader->expects($this->never())->method('file');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->download(anchor: 'AnchorValueThatIsOpaque')->getStatus());
	}//end testAPasswordLinkAsksForItsPasswordBeforeAnyDownload()

	public function testAFileThatCannotBeOpenedIs404AndRecordsNoUse(): void {
		$this->admitted(link: $this->link());
		$file = $this->createMock(File::class);
		$file->method('fopen')->willReturn(false);
		$this->reader->method('file')->willReturn($file);
		$this->links->expects($this->never())->method('recordUse');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->download(anchor: 'AnchorValueThatIsOpaque')->getStatus());
	}//end testAFileThatCannotBeOpenedIs404AndRecordsNoUse()

	public function testOpenPointsTheFilesDownloadAtTheLinkAndDropsTheAccountRoutesAndThePath(): void {
		$this->admitted(link: $this->link());
		$this->reader->method('read')->willReturn(
			[
				'subject' => ['onderwerp' => 'Bezwaar'],
				'file' => [
					'id' => 12,
					'title' => 'besluit.txt',
					'path' => '/openregister/files/Open Registers/Bezwaren/object-uuid/besluit.txt',
					'downloadUrl' => '/index.php/apps/openregister/api/files/12/download',
					'accessUrl' => '/index.php/core/preview?fileId=12',
				],
				'timeline' => [],
			]
		);

		$data = $this->controller()->open(anchor: 'AnchorValueThatIsOpaque')->getData();

		$this->assertSame('/route/openregister.accessLink.download/AnchorValueThatIsOpaque', $data['file']['downloadUrl']);
		$this->assertNull($data['file']['accessUrl']);
		$this->assertArrayNotHasKey('path', $data['file']);
		$this->assertSame('besluit.txt', $data['file']['title']);
	}//end testOpenPointsTheFilesDownloadAtTheLinkAndDropsTheAccountRoutesAndThePath()

	public function testWithoutAUrlGeneratorOpenOffersNoDeadDownloadLink(): void {
		$this->admitted(link: $this->link());
		$this->reader->method('read')->willReturn(
			['subject' => [], 'file' => ['id' => 12, 'downloadUrl' => '/index.php/apps/openregister/api/files/12/download'], 'timeline' => []]
		);

		$data = $this->controller(withUrls: false)->open(anchor: 'AnchorValueThatIsOpaque')->getData();

		$this->assertNull($data['file']['downloadUrl']);
	}//end testWithoutAUrlGeneratorOpenOffersNoDeadDownloadLink()

	public function testAnObjectLinkWithoutAFileIsServedAsBefore(): void {
		$this->admitted(link: $this->link(subjectType: AccessLink::SUBJECT_OBJECT));
		$this->reader->method('read')->willReturn(['subject' => ['onderwerp' => 'Bezwaar'], 'timeline' => []]);

		$data = $this->controller()->open(anchor: 'AnchorValueThatIsOpaque')->getData();

		$this->assertArrayNotHasKey('file', $data);
		$this->assertSame('Bezwaar', $data['subject']['onderwerp']);
	}//end testAnObjectLinkWithoutAFileIsServedAsBefore()
}//end class
