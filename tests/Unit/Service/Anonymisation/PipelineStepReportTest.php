<?php

/**
 * Every content-altering step of the anonymisation pipeline reports its state,
 * and every anonymisation run records each step's outcome.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md#requirement-every-content-altering-step-reports-its-state-req-adi-006
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Anonymisation;

use OCA\OpenRegister\Controller\AnonymisationPipelineController;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Files\Db\AnonymisationLog;
use OCA\OpenRegister\Files\Db\AnonymisationLogMapper;
use OCA\OpenRegister\Files\Service\File\DocumentProcessingHandler;
use OCA\OpenRegister\Files\Service\File\DocumentProcessingPlatform;
use OCA\OpenRegister\Files\Service\File\DocumentRedactionServices;
use OCA\OpenRegister\Files\Service\File\OfficeDocumentSanitizer;
use OCA\OpenRegister\Files\Service\File\SanitizationReport;
use OCA\OpenRegister\Files\Service\File\SanitizationResult;
use OCA\OpenRegister\Files\Service\File\Sanitizer\SanitizerInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\DocumentAnonymisationRequest;
use OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendServiceInterface;
use OCA\OpenRegister\Service\Anonymisation\BackendState;
use OCA\OpenRegister\Service\Anonymisation\ContentPipelineReport;
use OCA\OpenRegister\Service\Anonymisation\PipelineSteps;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Service\Anonymisation\PipelineSteps
 * @covers \OCA\OpenRegister\Service\Anonymisation\ContentPipelineReport
 * @covers \OCA\OpenRegister\Controller\AnonymisationPipelineController
 * @covers \OCA\OpenRegister\Files\Service\File\DocumentProcessingHandler
 * @covers \OCA\OpenRegister\Files\Db\AnonymisationLog
 * @uses   \OCA\OpenRegister\Service\Anonymisation\BackendState
 * @uses   \OCA\OpenRegister\Files\Service\File\DocumentProcessingPlatform
 * @uses   \OCA\OpenRegister\Files\Service\File\DocumentRedactionServices
 * @uses   \OCA\OpenRegister\Files\Service\File\DocumentReplacement
 * @uses   \OCA\OpenRegister\Files\Service\File\PlaceholderIdTranslator
 * @uses   \OCA\OpenRegister\Files\Service\File\PlaceholderLookup
 * @uses   \OCA\OpenRegister\Files\Service\File\SanitizationReport
 * @uses   \OCA\OpenRegister\Files\Service\File\SanitizationResult
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\DocumentAnonymisationOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\DocumentAnonymisationRequest
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\WordReplacementRequest
 */
final class PipelineStepReportTest extends TestCase {
	private const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

	/**
	 * The directories whose classes alter a document's content.
	 *
	 * @var list<string>
	 */
	private const STEP_DIRS = [
		'lib/Files/Service/File/Sanitizer',
		'lib/Files/Service/File/Pdf',
	];

	/**
	 * The report over a backend with entity recognition on or off.
	 *
	 * @param bool $recognitionEnabled Whether entity recognition is enabled.
	 *
	 * @return ContentPipelineReport
	 */
	private function report(bool $recognitionEnabled): ContentPipelineReport {
		$backend = $this->createMock(AnonymisationBackendServiceInterface::class);
		$backend->method('getState')->willReturn(new BackendState($recognitionEnabled, 'presidio', 'presidio', []));

		return new ContentPipelineReport(backend: $backend, steps: new PipelineSteps());
	}//end report()

	/**
	 * Every registered step has a report entry, and every sanitiser or replacer class is a registered step.
	 *
	 * @return void
	 */
	public function testEveryRegisteredStepHasAReportEntry(): void {
		$reported = array_column($this->report(recognitionEnabled: true)->report(), 'step');
		$this->assertSame(
			['anonymiser', 'pdf-text-replacer', 'pdf-metadata-sanitiser', 'docx-sanitiser', 'odt-sanitiser'],
			$reported
		);

		$steps = new PipelineSteps();
		$root = dirname(__DIR__, 4);
		$found = 0;
		foreach (self::STEP_DIRS as $dir) {
			foreach (glob($root . '/' . $dir . '/*.php') as $file) {
				$class = $this->contentStepClass(file: $file);
				if ($class === null) {
					continue;
				}

				$found++;
				$step = $steps->stepImplementedBy(class: $class);
				$this->assertNotNull($step, $class . ' alters document content but is no registered pipeline step');
				$this->assertContains($step, $reported, 'step ' . $step . ' has no report entry');
			}
		}

		// The DOCX and ODT sanitisers, the PDF text replacer and the PDF metadata sanitiser.
		$this->assertGreaterThanOrEqual(4, $found);
	}//end testEveryRegisteredStepHasAReportEntry()

	/**
	 * A switched-off step reports off and names the setting; a step without a switch is always-on.
	 *
	 * @return void
	 */
	public function testAnOffStepNamesItsSetting(): void {
		$entries = array_column($this->report(recognitionEnabled: false)->report(), null, 'step');

		$this->assertSame(['off', 'entityRecognitionEnabled'], [$entries['anonymiser']['state'], $entries['anonymiser']['setting']]);
		$this->assertSame(['always-on', null, ['application/pdf']], [$entries['pdf-metadata-sanitiser']['state'], $entries['pdf-metadata-sanitiser']['setting'], $entries['pdf-metadata-sanitiser']['appliesTo']]);
		$this->assertSame('PDF metadata sanitiser', $entries['pdf-metadata-sanitiser']['label']);

		$enabled = array_column($this->report(recognitionEnabled: true)->report(), null, 'step');
		$this->assertSame('on', $enabled['anonymiser']['state']);
	}//end testAnOffStepNamesItsSetting()

	/**
	 * A DOCX run through anonymize(): anonymiser and DOCX sanitiser ran, the PDF and ODT steps do not apply.
	 *
	 * @return void
	 */
	public function testARunRecordsEveryStepOutcome(): void {
		$fixture = dirname(__DIR__, 3) . '/Data/Word/word-structures.docx';
		$sanitised = tempnam(sys_get_temp_dir(), 'docx');
		copy($fixture, $sanitised);

		$sanitizer = $this->createMock(OfficeDocumentSanitizer::class);
		$sanitizer->method('isSanitizable')->willReturnCallback(static fn (string $mime): bool => $mime === self::DOCX);
		$sanitizer->expects($this->once())->method('sanitize')->with(17)->willReturn(new SanitizationResult(path: $sanitised, report: new SanitizationReport()));

		$logged = null;
		$logMapper = $this->createMock(AnonymisationLogMapper::class);
		$logMapper->expects($this->once())->method('insert')->willReturnCallback(
			static function (AnonymisationLog $entity) use (&$logged): AnonymisationLog {
				$logged = $entity;
				return $entity;
			}
		);

		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFile')->willReturnCallback(fn (): File => $this->createMock(File::class));

		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(17);
		$node->method('getName')->willReturn('word-structures.docx');
		$node->method('getPath')->willReturn('/word-structures.docx');
		$node->method('getMimeType')->willReturn(self::DOCX);
		$node->method('getParent')->willReturn($folder);
		$node->method('fopen')->willReturnCallback(static fn () => fopen($fixture, 'r'));
		$node->method('getContent')->willReturnCallback(static fn () => file_get_contents($fixture));

		$handler = new DocumentProcessingHandler(
			platform: new DocumentProcessingPlatform(
				rootFolder: $this->createMock(IRootFolder::class),
				userSession: $this->createMock(IUserSession::class),
				l10n: null
			),
			redaction: new DocumentRedactionServices(
				entityRelationMapper: $this->createMock(EntityRelationMapper::class),
				sanitizer: $sanitizer,
				anonymisationLogMapper: $logMapper
			),
			logger: $this->createMock(LoggerInterface::class)
		);

		$handler->anonymize(new DocumentAnonymisationRequest(
			node: $node,
			entities: [['text' => 'Jan Jansen', 'entityType' => 'PERSON', 'key' => 'p1']]
		));
		@unlink($sanitised);

		$this->assertInstanceOf(AnonymisationLog::class, $logged);
		$this->assertSame(
			[
				'anonymiser' => ['outcome' => 'ran'],
				'pdf-text-replacer' => ['outcome' => 'not-applicable'],
				'pdf-metadata-sanitiser' => ['outcome' => 'not-applicable'],
				'docx-sanitiser' => ['outcome' => 'ran'],
				'odt-sanitiser' => ['outcome' => 'not-applicable'],
			],
			$logged->getStepsArray()
		);
		$this->assertSame($logged->getStepsArray(), $logged->jsonSerialize()['steps']);
	}//end testARunRecordsEveryStepOutcome()

	/**
	 * A step that applies but did not run is skipped, with the reason.
	 *
	 * @return void
	 */
	public function testAnApplicableStepThatDidNotRunIsSkipped(): void {
		$outcomes = (new PipelineSteps())->outcomes(ran: ['anonymiser', 'pdf-text-replacer'], mimeType: 'application/pdf');

		$this->assertSame(['outcome' => 'skipped', 'reason' => 'the step did not run for this file'], $outcomes['pdf-metadata-sanitiser']);
		$this->assertSame(['outcome' => 'ran'], $outcomes['pdf-text-replacer']);
		$this->assertSame(['outcome' => 'not-applicable'], $outcomes['docx-sanitiser']);
	}//end testAnApplicableStepThatDidNotRunIsSkipped()

	/**
	 * The admin route answers the report to an admin and 403 to anyone else.
	 *
	 * @return void
	 */
	public function testTheRouteIsAdminOnly(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('jan');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->with('jan')->willReturnOnConsecutiveCalls(false, true);

		$controller = new AnonymisationPipelineController(
			request: $this->createMock(IRequest::class),
			userSession: $session,
			groupManager: $groups,
			report: $this->report(recognitionEnabled: true)
		);

		$refused = $controller->index();
		$this->assertSame(
			[403, ['error' => 'Administrator privileges are required to read the anonymisation pipeline']],
			[$refused->getStatus(), $refused->getData()]
		);

		$answered = $controller->index();
		$this->assertSame(200, $answered->getStatus());
		$this->assertSame('anonymiser', $answered->getData()['steps'][0]['step']);
		$this->assertCount(5, $answered->getData()['steps']);
	}//end testTheRouteIsAdminOnly()

	/**
	 * The class in a file when it alters content: a sanitiser strategy, or a PDF sanitiser or replacer.
	 *
	 * @param string $file The PHP file.
	 *
	 * @return string|null
	 */
	private function contentStepClass(string $file): ?string {
		$source = (string)file_get_contents($file);
		if (preg_match('/^namespace\s+([^;]+);/m', $source, $ns) !== 1
			|| preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $source, $cls) !== 1
		) {
			return null;
		}

		$class = $ns[1] . '\\' . $cls[1];
		if (class_exists($class) === false || (new ReflectionClass($class))->isAbstract() === true) {
			return null;
		}

		if (is_subclass_of($class, SanitizerInterface::class) === true
			|| preg_match('/(Sanitizer|TextReplacer)$/', $cls[1]) === 1
		) {
			return $class;
		}

		return null;
	}//end contentStepClass()
}//end class
