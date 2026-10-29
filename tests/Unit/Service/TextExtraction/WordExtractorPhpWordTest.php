<?php

declare(strict_types=1);

/**
 * WordExtractor tests for the PhpWord path
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\TextExtraction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service\TextExtraction;

use OCA\OpenRegister\Service\TextExtraction\WordExtractor;
use OCP\Files\File;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The PhpWord path of {@see WordExtractor}, which reads legacy .doc files.
 *
 * PhpWord parses a .doc into the same element tree as a DOCX, so the DOCX
 * fixture drives that path through the reader name.
 *
 * @covers \OCA\OpenRegister\Service\TextExtraction\WordExtractor
 */
class WordExtractorPhpWordTest extends TestCase {

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	private WordExtractor $extractor;

	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->extractor = new WordExtractor(logger: $this->logger);
	}//end setUp()

	/**
	 * A mock file with the given bytes.
	 *
	 * @param string $content The file bytes.
	 * @param string $name    The file name.
	 *
	 * @return File&MockObject
	 */
	private function mockFile(string $content, string $name): File {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn($content);
		$file->method('getMimeType')->willReturn('application/msword');
		$file->method('getName')->willReturn($name);
		$file->method('getId')->willReturn(7);

		return $file;
	}//end mockFile()

	/**
	 * Run the PhpWord path on a fixture with the given reader, whitespace collapsed.
	 *
	 * @param string $fixture    Fixture name under tests/Data/Word.
	 * @param string $readerName The PhpWord reader name.
	 *
	 * @return string|null
	 */
	private function extractWithPhpWord(string $fixture, string $readerName): ?string {
		$bytes = (string) file_get_contents(dirname(__DIR__, 3) . '/Data/Word/' . $fixture);
		$method = new ReflectionMethod(WordExtractor::class, 'extractWithPhpWord');
		$text = $method->invoke($this->extractor, $this->mockFile(content: $bytes, name: $fixture), $readerName);
		if ($text === null) {
			return null;
		}

		return trim((string) preg_replace('/\s+/u', ' ', $text));
	}//end extractWithPhpWord()

	/**
	 * Walk elements through the private walker.
	 *
	 * @param iterable $elements The elements.
	 * @param int      $depth    The starting depth.
	 *
	 * @return string
	 */
	private function walk(iterable $elements, int $depth = 0): string {
		$method = new ReflectionMethod(WordExtractor::class, 'walkWordElements');

		return $method->invoke($this->extractor, $elements, $depth);
	}//end walk()

	/**
	 * Headers, body, nested tables, footers and footnotes are read through PhpWord.
	 *
	 * @return void
	 */
	public function testDocxStructuresAreReadThroughPhpWord(): void {
		$text = (string) $this->extractWithPhpWord(fixture: 'word-structures.docx', readerName: 'Word2007');

		foreach ([
			'Gemeente Demostad kenmerk ZK-2026-0042',
			'Aanvrager Maria Bakker woont aan de Kerkstraat 12 in Demostad.',
			'Gemachtigde',
			'Sanne Bakker',
			'Behandelaar Jan Jansen telefoon 0612345678',
			'Voetnoot over Maria Bakker.',
		] as $expected) {
			$this->assertStringContainsString($expected, $text);
		}
	}//end testDocxStructuresAreReadThroughPhpWord()

	/**
	 * A document without text gives null and a warning.
	 *
	 * @return void
	 */
	public function testEmptyDocumentGivesNull(): void {
		$phpWord = new PhpWord();
		$phpWord->addSection();
		$path = (string) tempnam(sys_get_temp_dir(), 'or-empty-docx');
		IOFactory::createWriter($phpWord, 'Word2007')->save($path);
		$bytes = (string) file_get_contents($path);
		unlink($path);

		$this->logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('returned empty text'));

		$method = new ReflectionMethod(WordExtractor::class, 'extractWithPhpWord');
		$this->assertNull($method->invoke($this->extractor, $this->mockFile(content: $bytes, name: 'empty.doc'), 'Word2007'));
	}//end testEmptyDocumentGivesNull()

	/**
	 * A title whose text is a run is walked into that run.
	 *
	 * @return void
	 */
	public function testTitleWithRunIsWalked(): void {
		$run = new TextRun();
		$run->addText('Kop als run');

		$this->assertSame("Kop als run\n\n", $this->walk(elements: [new Title($run)]));
	}//end testTitleWithRunIsWalked()

	/**
	 * Empty text and empty containers add nothing.
	 *
	 * @return void
	 */
	public function testEmptyTextAddsNothing(): void {
		$this->assertSame('', $this->walk(elements: [new TextRun(), new Title('')]));
	}//end testEmptyTextAddsNothing()

	/**
	 * A table row without cells only ends the row.
	 *
	 * @return void
	 */
	public function testRowWithoutCellsOnlyEndsTheRow(): void {
		$table = new class {
			/**
			 * The rows.
			 *
			 * @return array<int, object>
			 */
			public function getRows(): array {
				return [new \stdClass()];
			}
		};

		$this->assertSame('', $this->walk(elements: [$table]));
	}//end testRowWithoutCellsOnlyEndsTheRow()

	/**
	 * Descent stops past the maximum depth.
	 *
	 * @return void
	 */
	public function testDescentStopsPastMaximumDepth(): void {
		$this->logger->expects($this->once())
			->method('debug')
			->with($this->stringContains('MAX_WORD_DEPTH'));

		$this->assertSame('', $this->walk(elements: [new Title('Te diep')], depth: 51));
	}//end testDescentStopsPastMaximumDepth()
}//end class
