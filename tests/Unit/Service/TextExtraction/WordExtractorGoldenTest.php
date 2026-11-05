<?php

declare(strict_types=1);

/**
 * WordExtractor golden tests on real DOCX and ODT fixtures
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
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The text {@see WordExtractor} returns for documents with headers, footers,
 * tables, footnotes, lists, comments and tracked changes.
 *
 * Text is compared with whitespace collapsed, so line structure does not count.
 *
 * @covers \OCA\OpenRegister\Service\TextExtraction\WordExtractor
 * @uses   \OCA\OpenRegister\Service\TextExtraction\WordTextReader
 * @uses   \OCA\OpenRegister\Service\TextExtraction\OoxmlPackage
 */
class WordExtractorGoldenTest extends TestCase {

	private const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

	private const ODT = 'application/vnd.oasis.opendocument.text';

	/**
	 * Extract a fixture and collapse its whitespace.
	 *
	 * @param string $path Fixture path relative to tests/Data.
	 * @param string $mime The MIME type to report.
	 *
	 * @return string
	 */
	private function extract(string $path, string $mime): string {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn((string) file_get_contents(dirname(__DIR__, 3) . '/Data/' . $path));
		$file->method('getMimeType')->willReturn($mime);
		$file->method('getName')->willReturn(basename($path));
		$file->method('getId')->willReturn(1);

		$text = (new WordExtractor(logger: $this->createMock(LoggerInterface::class)))->extract(file: $file);

		return trim((string) preg_replace('/\s+/u', ' ', (string) $text));
	}//end extract()

	/**
	 * A DOCX yields its header, body, table cells (nested too), list, footer and footnote.
	 *
	 * @return void
	 */
	public function testDocxStructuresAreRead(): void {
		$text = $this->extract(path: 'Word/word-structures.docx', mime: self::DOCX);

		foreach ([
			'Gemeente Demostad kenmerk ZK-2026-0042',
			'Besluit op aanvraag',
			'Aanvrager Maria Bakker woont aan de Kerkstraat 12 in Demostad.',
			'Contact via maria.bakker@example.nl',
			'Rol Naam',
			'Aanvrager Maria Bakker',
			'Gemachtigde Jan Jansen',
			'Kind Sanne Bakker',
			'Eerste punt voor Jan Jansen',
			'Tweede punt',
			'Naam: Jan Jansen Tweede regel',
			'Slotzin met Łukasz Żółć uit Gdańsk.',
			'Behandelaar Jan Jansen telefoon 0612345678',
			'Voetnoot over Maria Bakker.',
		] as $expected) {
			$this->assertStringContainsString($expected, $text);
		}

		$this->assertStringNotContainsString('Controleer de naam', $text, 'Comments are not document text.');
		$this->assertStringNotContainsString('Piet de Vries', $text, 'Change and comment authors are not document text.');
	}//end testDocxStructuresAreRead()

	/**
	 * The inserted text of a tracked change is read, in both fixtures that carry one.
	 *
	 * @return void
	 */
	public function testTrackedInsertionIsRead(): void {
		$this->assertStringContainsString('15.000', $this->extract(path: 'Word/word-structures.docx', mime: self::DOCX));
		$this->assertStringContainsString('15.000', $this->extract(path: 'Word/concept-besluit-demostad.docx', mime: self::DOCX));
	}//end testTrackedInsertionIsRead()

	/**
	 * The deleted text of a tracked change is not document text.
	 *
	 * @return void
	 */
	public function testTrackedDeletionIsSkipped(): void {
		$this->assertStringContainsString('Het bedrag is 15.000 euro.', $this->extract(path: 'Word/concept-besluit-demostad.docx', mime: self::DOCX));
		$this->assertStringNotContainsString('12.000', $this->extract(path: 'Word/concept-besluit-demostad.docx', mime: self::DOCX));
		$this->assertStringNotContainsString('12.000', $this->extract(path: 'Word/word-structures.docx', mime: self::DOCX));
	}//end testTrackedDeletionIsSkipped()

	/**
	 * A name split over two runs is read as one word.
	 *
	 * @return void
	 */
	public function testNameSplitOverRunsIsJoined(): void {
		$this->assertStringContainsString('Gesplitst: Jan Jansen tekent.', $this->extract(path: 'Word/word-structures.docx', mime: self::DOCX));
	}//end testNameSplitOverRunsIsJoined()

	/**
	 * Each footnote is read once.
	 *
	 * @return void
	 */
	public function testFootnoteIsReadOnce(): void {
		$this->assertSame(1, substr_count($this->extract(path: 'Word/word-structures.docx', mime: self::DOCX), 'Voetnoot over Maria Bakker.'));
	}//end testFootnoteIsReadOnce()

	/**
	 * An ODT yields its header, body, table cells (nested too), list, footnote and footer.
	 *
	 * @return void
	 */
	public function testOdtStructuresAreRead(): void {
		$text = $this->extract(path: 'Word/word-structures.odt', mime: self::ODT);

		foreach ([
			'Gemeente Demostad kenmerk ZK-2026-0042',
			'Behandelaar Jan Jansen telefoon 0612345678',
			'Besluit op aanvraag',
			'Aanvrager Maria Bakker woont aan de Kerkstraat 12 in Demostad.',
			'Rol Naam',
			'Gemachtigde Jan Jansen',
			'Kind Sanne Bakker',
			'Naam: Jan Jansen Tweede regel',
			'Gesplitst: Jan Jansen tekent.',
			'Het bedrag is 15.000 euro.',
			'Contact via maria.bakker@example.nl',
			'Voetnoot over Maria Bakker.',
			'Eerste punt voor Jan Jansen',
			'Slotzin met Łukasz Żółć uit Gdańsk.',
		] as $expected) {
			$this->assertStringContainsString($expected, $text);
		}

		$this->assertStringNotContainsString('12.000', $text);
		$this->assertStringNotContainsString('Controleer de naam', $text, 'Comments are not document text.');
		$this->assertStringNotContainsString('Piet de Vries', $text, 'Change and comment authors are not document text.');
	}//end testOdtStructuresAreRead()

	/**
	 * A real Word document reads the same as before.
	 *
	 * @return void
	 */
	public function testRealWordDocument(): void {
		$text = $this->extract(path: 'anonimisering_testdocument.docx', mime: self::DOCX);

		$this->assertStringStartsWith('Testdocument – Fictieve persoonsgegevens Naam: Jan de Vries E-mail: jan.devries@example.org', $text);
		$this->assertStringContainsString('Adres: Kerkstraat 12 3512 GK Utrecht Nederland', $text);
		$this->assertStringContainsString('IBAN: NL91ABNA0417164300', $text);
		$this->assertStringEndsWith('Datum ondertekening contract: 01-11-2024', $text);
	}//end testRealWordDocument()
}//end class
