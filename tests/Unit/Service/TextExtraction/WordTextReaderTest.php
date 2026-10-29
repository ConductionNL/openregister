<?php

declare(strict_types=1);

/**
 * WordTextReader tests
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

use OCA\OpenRegister\Service\TextExtraction\WordTextReader;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see WordTextReader}.
 */
class WordTextReaderTest extends TestCase {

	/**
	 * Write a small document with PhpWord in the given format.
	 *
	 * @param string $format `Word2007` or `ODText`.
	 *
	 * @return string The package bytes.
	 */
	private static function document(string $format): string {
		$word = new PhpWord();
		$section = $word->addSection();
		$section->addHeader()->addText('Kopregel');
		$run = $section->addTextRun();
		$run->addText('Dit is ');
		$run->addText('vet', ['bold' => true]);
		$run->addText(' gedrukt.');
		$table = $section->addTable();
		$table->addRow();
		$table->addCell(2000)->addText('Cel A');
		$table->addCell(2000)->addText('Cel B');

		$path = tempnam(sys_get_temp_dir(), 'wtr');
		IOFactory::createWriter($word, $format)->save($path);
		$bytes = (string) file_get_contents($path);
		unlink($path);

		return $bytes;
	}//end document()

	/**
	 * Runs join into one line, and the header and table cells are read, for DOCX and ODT.
	 *
	 * @return void
	 */
	public function testReadsParagraphsHeadersAndTables(): void {
		foreach (['Word2007', 'ODText'] as $format) {
			$text = (string) (new WordTextReader())->read(bytes: self::document(format: $format), format: $format);

			$this->assertStringContainsString("Dit is vet gedrukt.\n", $text, $format);
			$this->assertStringContainsString('Kopregel', $text, $format);
			$this->assertStringContainsString('Cel A', $text, $format);
			$this->assertStringContainsString('Cel B', $text, $format);
		}
	}//end testReadsParagraphsHeadersAndTables()

	/**
	 * Deleted tracked-change text is not part of the document text.
	 *
	 * @return void
	 */
	public function testSkipsDeletedTrackedChanges(): void {
		$text = (string) (new WordTextReader())->read(
			bytes: (string) file_get_contents(dirname(__DIR__, 3) . '/Comparison/fixtures/concept-besluit-demostad.docx'),
			format: 'Word2007'
		);

		$this->assertStringContainsString('Het bedrag is 15.000 euro.', $text);
		$this->assertStringNotContainsString('12.000', $text);
	}//end testSkipsDeletedTrackedChanges()

	/**
	 * Legacy .doc and unreadable bytes give null.
	 *
	 * @return void
	 */
	public function testUnsupportedOrUnreadableGivesNull(): void {
		$this->assertNull((new WordTextReader())->read(bytes: 'not a zip', format: 'Word2007'));
		$this->assertNull((new WordTextReader())->read(bytes: self::document(format: 'Word2007'), format: 'MsDoc'));
	}//end testUnsupportedOrUnreadableGivesNull()
}//end class
