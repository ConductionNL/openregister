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
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see WordTextReader}.
 *
 * @covers \OCA\OpenRegister\Service\TextExtraction\WordTextReader
 * @uses   \OCA\OpenRegister\Service\TextExtraction\OoxmlPackage
 */
class WordTextReaderTest extends TestCase {

	/**
	 * Read a fixture under tests/Data/Word.
	 *
	 * @param string $name   The fixture file name.
	 * @param string $format `Word2007` or `ODText`.
	 *
	 * @return string|null
	 */
	private static function read(string $name, string $format): ?string {
		$bytes = (string) file_get_contents(dirname(__DIR__, 3) . '/Data/Word/' . $name);

		return (new WordTextReader())->read(bytes: $bytes, format: $format);
	}//end read()

	/**
	 * A DOCX paragraph is one line, its runs joined, with tabs and breaks kept.
	 *
	 * @return void
	 */
	public function testDocxParagraphsAreLines(): void {
		$text = (string) self::read(name: 'word-structures.docx', format: 'Word2007');

		$this->assertStringStartsWith("Gemeente Demostad kenmerk ZK-2026-0042\n", $text);
		$this->assertStringContainsString("\nAanvrager Maria Bakker woont aan de Kerkstraat 12 in Demostad.\n", $text);
		$this->assertStringContainsString("\nNaam:\tJan Jansen\nTweede regel\n", $text);
		$this->assertStringContainsString("\nBehandelaar Jan Jansen telefoon 0612345678\n", $text);
		$this->assertStringEndsWith("Voetnoot over Maria Bakker.\n", $text);
	}//end testDocxParagraphsAreLines()

	/**
	 * Every table row ends with a line break after its cells.
	 *
	 * @return void
	 */
	public function testTableRowsEndWithLineBreak(): void {
		$this->assertStringContainsString("Rol\nNaam\n\n", (string) self::read(name: 'word-structures.docx', format: 'Word2007'));
		$this->assertStringContainsString("Rol\nNaam\n\n", (string) self::read(name: 'word-structures.odt', format: 'ODText'));
	}//end testTableRowsEndWithLineBreak()

	/**
	 * An ODT reads master-page headers and footers before the body, without tracked-change records or comments.
	 *
	 * @return void
	 */
	public function testOdtHeadersFootersAndBody(): void {
		$text = (string) self::read(name: 'word-structures.odt', format: 'ODText');

		$this->assertStringStartsWith("Gemeente Demostad kenmerk ZK-2026-0042\nBehandelaar Jan Jansen telefoon 0612345678\nBesluit op aanvraag\n", $text);
		$this->assertStringContainsString("Contact via maria.bakker@example.nl Voetnoot over Maria Bakker..\n", $text);
		$this->assertStringNotContainsString('12.000', $text);
		$this->assertStringNotContainsString('Controleer', $text);
	}//end testOdtHeadersFootersAndBody()

	/**
	 * ODT spaces, tabs and line breaks become whitespace.
	 *
	 * @return void
	 */
	public function testOdtWhitespaceElements(): void {
		$content = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
			. ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0">'
			. '<office:body><office:text><text:p>a<text:s text:c="3"/>b<text:s/>c<text:tab/>d<text:line-break/>e</text:p>'
			. '</office:text></office:body></office:document-content>';
		$path = (string) tempnam(sys_get_temp_dir(), 'or-odt');
		$zip = new \ZipArchive();
		$zip->open($path, \ZipArchive::OVERWRITE);
		$zip->addFromString('content.xml', $content);
		$zip->close();
		$bytes = (string) file_get_contents($path);
		unlink($path);

		$this->assertSame("a   b c\td\ne\n", (new WordTextReader())->read(bytes: $bytes, format: 'ODText'));
	}//end testOdtWhitespaceElements()

	/**
	 * Legacy .doc and unreadable bytes give null.
	 *
	 * @return void
	 */
	public function testUnsupportedOrUnreadableGivesNull(): void {
		$this->assertNull((new WordTextReader())->read(bytes: 'not a zip', format: 'Word2007'));
		$this->assertNull((new WordTextReader())->read(bytes: 'not a zip', format: 'ODText'));
		$this->assertNull(self::read(name: 'word-structures.docx', format: 'MsDoc'));
	}//end testUnsupportedOrUnreadableGivesNull()

	/**
	 * A zip without a main document part gives null.
	 *
	 * @return void
	 */
	public function testPackageWithoutMainPartGivesNull(): void {
		$this->assertNull(self::read(name: 'word-structures.odt', format: 'Word2007'));
		$this->assertNull(self::read(name: 'word-structures.docx', format: 'ODText'));
	}//end testPackageWithoutMainPartGivesNull()
}//end class
