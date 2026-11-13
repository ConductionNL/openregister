<?php

/**
 * Anonymising a sanitisable Office document: the sanitiser's refusals and the ODT container pass.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/office-document-sanitization/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use Exception;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Exception\SanitizationException;
use OCA\OpenRegister\Files\Service\File\DocumentProcessingHandler;
use OCA\OpenRegister\Files\Service\File\DocumentProcessingPlatform;
use OCA\OpenRegister\Files\Service\File\DocumentRedactionServices;
use OCA\OpenRegister\Files\Service\File\OfficeDocumentSanitizer;
use OCA\OpenRegister\Files\Service\File\SanitizationReport;
use OCA\OpenRegister\Files\Service\File\SanitizationResult;
use OCA\OpenRegister\Kernel\Contracts\Value\DocumentAnonymisationRequest;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ZipArchive;

/**
 * @covers \OCA\OpenRegister\Files\Service\File\DocumentProcessingHandler
 * @uses   \OCA\OpenRegister\Files\Service\File\DocumentProcessingPlatform
 * @uses   \OCA\OpenRegister\Files\Service\File\DocumentRedactionServices
 * @uses   \OCA\OpenRegister\Files\Service\File\DocumentReplacement
 * @uses   \OCA\OpenRegister\Files\Service\File\PlaceholderIdTranslator
 * @uses   \OCA\OpenRegister\Files\Service\File\PlaceholderLookup
 * @uses   \OCA\OpenRegister\Files\Service\File\SanitizationReport
 * @uses   \OCA\OpenRegister\Files\Service\File\SanitizationResult
 * @uses   \OCA\OpenRegister\Exception\SanitizationException
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\DocumentAnonymisationRequest
 * @uses   \OCA\OpenRegister\Files\Service\File\SanitizationRevisionCounts
 * @uses   \OCA\OpenRegister\Files\Service\File\SanitizationStructureCounts
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\DocumentAnonymisationOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\WordReplacementRequest
 */
class DocumentProcessingHandlerOfficeContainerTest extends TestCase {

	/**
	 * The sentence the source document and its ODT container both carry.
	 */
	private const SENTENCE = 'Jan Jansen woont in Amsterdam.';

	/**
	 * The office sanitiser.
	 *
	 * @var OfficeDocumentSanitizer&MockObject
	 */
	private OfficeDocumentSanitizer&MockObject $sanitizer;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The files written to the source's folder, by name, with their content.
	 *
	 * @var array<string, string>
	 */
	private array $written = [];

	/**
	 * The handler of the last run.
	 *
	 * @var DocumentProcessingHandler|null
	 */
	private ?DocumentProcessingHandler $handler = null;

	/**
	 * Temp files to remove afterwards.
	 *
	 * @var list<string>
	 */
	private array $tempPaths = [];

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->sanitizer = $this->createMock(OfficeDocumentSanitizer::class);
		$this->sanitizer->method('isSanitizable')->willReturn(true);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->tempPaths as $path) {
			if (file_exists($path) === true) {
				unlink($path);
			}
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * Anonymise one ODT file named letter.odt.
	 *
	 * @return File The file anonymize() returned.
	 */
	private function anonymise(): File {
		$this->handler = new DocumentProcessingHandler(
			platform: new DocumentProcessingPlatform(
				rootFolder: $this->createMock(IRootFolder::class),
				userSession: $this->createMock(IUserSession::class),
				l10n: null
			),
			redaction: new DocumentRedactionServices(
				entityRelationMapper: $this->createMock(EntityRelationMapper::class),
				sanitizer: $this->sanitizer
			),
			logger: $this->logger
		);

		$parent = $this->createMock(Folder::class);
		$parent->method('nodeExists')->willReturnCallback(fn (string $name): bool => array_key_exists($name, $this->written));
		$parent->method('get')->willReturnCallback(
			function (string $name): File {
				$existing = $this->createMock(File::class);
				$existing->expects($this->once())->method('delete')->willReturnCallback(
					function () use ($name): void {
						unset($this->written[$name]);
					}
				);
				return $existing;
			}
		);
		$parent->method('newFile')->willReturnCallback(
			function (string $path, $content = null): File {
				$this->written[$path] = is_resource($content) === true ? stream_get_contents($content) : (string)$content;
				$out = $this->createMock(File::class);
				$out->method('getPath')->willReturn('/files/' . $path);
				$out->method('getName')->willReturn($path);
				return $out;
			}
		);

		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(0);
		$node->method('getName')->willReturn('letter.odt');
		$node->method('getPath')->willReturn('/files/letter.odt');
		$node->method('getMimeType')->willReturn('application/vnd.oasis.opendocument.text');
		$node->method('getContent')->willReturn(self::SENTENCE);
		$node->method('getParent')->willReturn($parent);

		return $this->handler->anonymize(
			new DocumentAnonymisationRequest(
				node: $node,
				entities: [
					['text' => 'Jan Jansen', 'entityType' => 'PERSON', 'key' => 'p1'],
					['text' => 'Amsterdam', 'entityType' => 'LOCATION', 'key' => 'l1'],
				]
			)
		);
	}//end anonymise()

	/**
	 * A sanitised ODT container on disk.
	 *
	 * @param array<string, string> $parts The container's parts.
	 *
	 * @return string Its path.
	 */
	private function container(array $parts): string {
		$path = tempnam(sys_get_temp_dir(), 'or-odt-');
		$this->tempPaths[] = $path;
		$zip = new ZipArchive();
		$zip->open($path, ZipArchive::OVERWRITE);
		foreach ($parts as $name => $content) {
			$zip->addFromString($name, $content);
		}

		$zip->close();
		return $path;
	}//end container()

	/**
	 * Run anonymize() and return the exception it raised.
	 *
	 * @return Exception The exception.
	 */
	private function failure(): Exception {
		try {
			$this->anonymise();
		} catch (Exception $e) {
			return $e;
		}

		$this->fail('anonymize() did not fail');
	}//end failure()

	public function testTheOdtContainersTextPartsGetTheSameReplacementsAsTheText(): void {
		$path = $this->container(
			[
				'mimetype' => 'application/vnd.oasis.opendocument.text',
				'content.xml' => '<p>' . self::SENTENCE . '</p>',
				'styles.xml' => '<header>jan jansen</header>',
				'meta.xml' => '<meta>Jan Jansen</meta>',
			]
		);
		$report = new SanitizationReport();
		$this->sanitizer->expects($this->once())->method('sanitize')->with(0)->willReturn(new SanitizationResult(path: $path, report: $report));

		$result = $this->anonymise();

		// The text pass wrote letter_anonymized.odt first; the container pass replaced it.
		$this->assertSame('/files/letter_anonymized.odt', $result->getPath());
		$this->assertSame(['letter_anonymized.odt'], array_keys($this->written));

		$zip = new ZipArchive();
		$out = tempnam(sys_get_temp_dir(), 'or-odt-out-');
		$this->tempPaths[] = $out;
		file_put_contents($out, $this->written['letter_anonymized.odt']);
		$this->assertTrue($zip->open($out));

		$content = (string)$zip->getFromName('content.xml');
		$this->assertStringNotContainsString('Jan Jansen', $content);
		$this->assertStringNotContainsString('Amsterdam', $content);
		$this->assertStringNotContainsStringIgnoringCase('jan jansen', (string)$zip->getFromName('styles.xml'), 'the replacement is case-insensitive');
		$this->assertSame('<meta>Jan Jansen</meta>', $zip->getFromName('meta.xml'), 'only the text parts are rewritten');
		$zip->close();

		$this->assertSame($report, $this->handler->getLastSanitizationReport(), 'the sanitiser report is kept for the caller');
	}//end testTheOdtContainersTextPartsGetTheSameReplacementsAsTheText()

	public function testAnEncryptedDocumentIsRefusedAsSuch(): void {
		$encrypted = new SanitizationException(reason: SanitizationException::REASON_ENCRYPTED);
		$this->sanitizer->method('sanitize')->willThrowException($encrypted);
		$this->logger->expects($this->never())->method('error');

		$e = $this->failure();

		$this->assertSame('Cannot anonymise an encrypted document', $e->getMessage());
		$this->assertSame($encrypted, $e->getPrevious());
	}//end testAnEncryptedDocumentIsRefusedAsSuch()

	public function testAnyOtherSanitiserRefusalIsLoggedByReasonAndRefused(): void {
		$corrupt = new SanitizationException(reason: SanitizationException::REASON_CORRUPT_ZIP);
		$this->sanitizer->method('sanitize')->willThrowException($corrupt);
		$this->logger->expects($this->once())
			->method('error')
			->with(
				'[DocumentProcessingHandler] Office document sanitisation failed: corrupt-zip',
				$this->callback(static fn (array $c): bool => $c['reason'] === 'corrupt-zip')
			);

		$e = $this->failure();

		$this->assertSame('Document sanitisation failed', $e->getMessage());
		$this->assertSame($corrupt, $e->getPrevious());
	}//end testAnyOtherSanitiserRefusalIsLoggedByReasonAndRefused()

	public function testASanitisedFileThatIsNotAZipIsRefused(): void {
		$path = tempnam(sys_get_temp_dir(), 'or-odt-');
		$this->tempPaths[] = $path;
		file_put_contents($path, 'not a zip');
		$this->sanitizer->method('sanitize')->willReturn(new SanitizationResult(path: $path, report: new SanitizationReport()));

		$this->assertSame('Failed to open sanitised Office container', $this->failure()->getMessage());
	}//end testASanitisedFileThatIsNotAZipIsRefused()
}//end class
