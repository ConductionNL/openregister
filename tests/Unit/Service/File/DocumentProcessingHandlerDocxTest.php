<?php

/**
 * DocumentProcessingHandler DOCX replacement golden tests
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\File;

use DOMDocument;
use DOMXPath;
use OCA\OpenRegister\Db\AnonymisationLogMapper;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Service\File\DocumentProcessingHandler;
use OCA\OpenRegister\Service\File\OfficeDocumentSanitizer;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ZipArchive;

/**
 * What {@see DocumentProcessingHandler::replaceWords()} writes for a DOCX:
 * which parts get the replacements, and what survives the rewrite.
 */
class DocumentProcessingHandlerDocxTest extends TestCase {

	private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

	/**
	 * Bytes of the DOCX the handler wrote.
	 *
	 * @var string
	 */
	private string $written = '';

	/**
	 * Name the handler wrote the DOCX under.
	 *
	 * @var string
	 */
	private string $writtenName = '';

	/**
	 * Run replaceWords() on a fixture and keep the written bytes.
	 *
	 * @param string                $fixture      Path relative to tests/Data.
	 * @param array<string, string> $replacements Search => replacement.
	 *
	 * @return void
	 */
	private function replace(string $fixture, array $replacements): void {
		$path = dirname(__DIR__, 3) . '/Data/' . $fixture;

		$output = $this->createMock(File::class);
		$output->method('getPath')->willReturn('/out.docx');

		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFile')->willReturnCallback(
			function (string $path, $content) use ($output) {
				$this->writtenName = $path;
				$this->written = is_resource($content) === true ? (string) stream_get_contents($content) : (string) $content;
				return $output;
			}
		);

		$node = $this->createMock(File::class);
		$node->method('getName')->willReturn(basename($path));
		$node->method('getPath')->willReturn('/' . basename($path));
		$node->method('getParent')->willReturn($folder);
		$node->method('fopen')->willReturnCallback(static fn () => fopen($path, 'r'));
		$node->method('getContent')->willReturnCallback(static fn () => file_get_contents($path));

		$handler = new DocumentProcessingHandler(
			rootFolder: $this->createMock(IRootFolder::class),
			userSession: $this->createMock(IUserSession::class),
			logger: $this->createMock(LoggerInterface::class),
			entityRelationMapper: $this->createMock(EntityRelationMapper::class),
			sanitizer: $this->createMock(OfficeDocumentSanitizer::class),
			anonymisationLogMapper: $this->createMock(AnonymisationLogMapper::class),
			l10n: null
		);

		$handler->replaceWords(node: $node, replacements: $replacements);
	}//end replace()

	/**
	 * Text of the written parts whose names match a pattern, whitespace collapsed.
	 *
	 * @param string $pattern Part name regex.
	 * @param string $query   XPath selecting the text nodes.
	 *
	 * @return string
	 */
	private function partText(string $pattern, string $query = '//w:t'): string {
		$file = tempnam(sys_get_temp_dir(), 'docx');
		file_put_contents($file, $this->written);
		$zip = new ZipArchive();
		$this->assertTrue($zip->open($file));

		$text = '';
		for ($index = 0; $index < $zip->numFiles; $index++) {
			$name = (string) $zip->getNameIndex($index);
			if (preg_match($pattern, $name) !== 1) {
				continue;
			}

			$dom = new DOMDocument();
			$dom->loadXML((string) $zip->getFromIndex($index));
			$xpath = new DOMXPath($dom);
			$xpath->registerNamespace('w', self::W);
			foreach ($xpath->query('//w:p') as $paragraph) {
				foreach ($xpath->query('.' . str_replace('//', '/descendant::', $query), $paragraph) as $node) {
					$text .= $node->textContent;
				}

				$text .= ' ';
			}
		}//end for

		$zip->close();
		unlink($file);

		return trim((string) preg_replace('/\s+/u', ' ', $text));
	}//end partText()

	/**
	 * Replacements reach the body, tables (nested too), lists, header, footer and footnotes.
	 *
	 * @return void
	 */
	public function testReplacesInEveryTextPart(): void {
		$this->replace(fixture: 'Word/word-structures.docx', replacements: ['Maria Bakker' => '[PERSOON: 1]', 'Jan Jansen' => '[PERSOON: 2]', 'Sanne Bakker' => '[PERSOON: 3]', 'ZK-2026-0042' => '[ZAAK: 1]']);

		$this->assertSame('word-structures_replaced.docx', $this->writtenName);

		$body = $this->partText(pattern: '#^word/document\.xml$#');
		$this->assertStringContainsString('Gemachtigde [PERSOON: 2]', $body);
		$this->assertStringContainsString('Aanvrager [PERSOON: 1]', $body);
		$this->assertStringContainsString('Kind [PERSOON: 3]', $body);
		$this->assertStringContainsString('Eerste punt voor [PERSOON: 2]', $body);
		$this->assertMatchesRegularExpression('/Naam:\s*\[PERSOON: 2\]\s*Tweede regel/u', $body);

		$this->assertSame('Gemeente Demostad kenmerk [ZAAK: 1]', $this->partText(pattern: '#^word/header\d*\.xml$#'));
		$this->assertSame('Behandelaar [PERSOON: 2] telefoon 0612345678', $this->partText(pattern: '#^word/footer\d*\.xml$#'));
		$this->assertStringContainsString('Voetnoot over [PERSOON: 1].', $this->partText(pattern: '#^word/footnotes\.xml$#'));
	}//end testReplacesInEveryTextPart()

	/**
	 * A value split over several runs is replaced, in the run where it starts.
	 *
	 * @return void
	 */
	public function testValueSplitOverRunsIsReplaced(): void {
		$this->replace(fixture: 'Word/word-structures.docx', replacements: ['Maria Bakker' => '[PERSOON: 1]', 'Jan Jansen' => '[PERSOON: 2]']);

		$body = $this->partText(pattern: '#^word/document\.xml$#');
		$this->assertStringContainsString('Aanvrager [PERSOON: 1] woont', $body);
		$this->assertStringContainsString('Gesplitst: [PERSOON: 2] tekent.', $body);
		$this->assertStringContainsString('<w:b/></w:rPr><w:t xml:space="preserve">[PERSOON: 1]</w:t>', $this->part(name: 'word/document.xml'));
	}//end testValueSplitOverRunsIsReplaced()

	/**
	 * Deleted tracked-change text and comments are replaced too.
	 *
	 * @return void
	 */
	public function testDeletedTextAndCommentsAreReplaced(): void {
		$this->replace(fixture: 'Word/word-structures.docx', replacements: ['Maria Bakker' => '[PERSOON: 1]', '12.000' => '[BEDRAG: 1]']);

		$this->assertSame('[BEDRAG: 1]', $this->partText(pattern: '#^word/document\.xml$#', query: '//w:delText'));
		$this->assertSame('Controleer de naam van [PERSOON: 1].', $this->partText(pattern: '#^word/comments\.xml$#'));
	}//end testDeletedTextAndCommentsAreReplaced()

	/**
	 * Parts without a match, and the package's other files, are written back unchanged.
	 *
	 * @return void
	 */
	public function testUntouchedPartsAreByteIdentical(): void {
		$this->replace(fixture: 'Word/word-structures.docx', replacements: ['Gemeente' => 'G.']);

		$source = new ZipArchive();
		$source->open(dirname(__DIR__, 3) . '/Data/Word/word-structures.docx');
		foreach (['word/document.xml', 'word/footer1.xml', 'word/styles.xml', 'word/numbering.xml', 'docProps/core.xml', '[Content_Types].xml'] as $name) {
			$this->assertSame($source->getFromName($name), $this->part(name: $name), $name);
		}

		$source->close();
		$this->assertSame('G. Demostad kenmerk ZK-2026-0042', $this->partText(pattern: '#^word/header\d*\.xml$#'));
	}//end testUntouchedPartsAreByteIdentical()

	/**
	 * A replacement that contains its own search text is not replaced again.
	 *
	 * @return void
	 */
	public function testReplacementContainingSearchTextEnds(): void {
		$this->replace(fixture: 'Word/word-structures.docx', replacements: ['Jansen' => 'Jansen-Jansen']);

		$this->assertSame('Behandelaar Jan Jansen-Jansen telefoon 0612345678', $this->partText(pattern: '#^word/footer\d*\.xml$#'));
	}//end testReplacementContainingSearchTextEnds()

	/**
	 * Bytes that are not a DOCX package fail with an exception.
	 *
	 * @return void
	 */
	public function testNonPackageFails(): void {
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Failed to replace words in Word document');

		$this->replace(fixture: 'Word/not-a-package.docx', replacements: ['a' => 'b']);
	}//end testNonPackageFails()

	/**
	 * Matching ignores case and every occurrence is replaced.
	 *
	 * @return void
	 */
	public function testMatchingIgnoresCase(): void {
		$this->replace(fixture: 'Word/word-structures.docx', replacements: ['jan jansen' => 'X']);

		$this->assertStringContainsString('Gemachtigde X', $this->partText(pattern: '#^word/document\.xml$#'));
		$this->assertSame('Behandelaar X telefoon 0612345678', $this->partText(pattern: '#^word/footer\d*\.xml$#'));
	}//end testMatchingIgnoresCase()

	/**
	 * Text and formatting outside the matches stay as they were.
	 *
	 * @return void
	 */
	public function testUntouchedTextIsKept(): void {
		$this->replace(fixture: 'Word/word-structures.docx', replacements: ['Maria' => 'M.']);

		$body = $this->partText(pattern: '#^word/document\.xml$#');
		$this->assertStringContainsString('Besluit op aanvraag', $body);
		$this->assertStringContainsString('Aanvrager M. Bakker woont aan de Kerkstraat 12 in Demostad.', $body);
		$this->assertStringContainsString('Slotzin met Łukasz Żółć uit Gdańsk.', $body);
		$this->assertStringContainsString('Het bedrag is 15.000 euro.', $body);
		$this->assertSame(1, preg_match('#<w:b[\s/>]#', $this->part(name: 'word/document.xml')), 'The bold run keeps its formatting.');
	}//end testUntouchedTextIsKept()

	/**
	 * A real Word document gets its values replaced.
	 *
	 * @return void
	 */
	public function testRealWordDocument(): void {
		$this->replace(fixture: 'anonimisering_testdocument.docx', replacements: ['Jan de Vries' => '[PERSOON: 1]', 'NL91ABNA0417164300' => '[IBAN: 1]']);

		$body = $this->partText(pattern: '#^word/document\.xml$#');
		$this->assertStringContainsString('Naam: [PERSOON: 1]', $body);
		$this->assertStringContainsString('IBAN: [IBAN: 1]', $body);
		$this->assertStringContainsString('BSN: 123456782', $body);
	}//end testRealWordDocument()

	/**
	 * Raw XML of one written part.
	 *
	 * @param string $name The part name.
	 *
	 * @return string
	 */
	private function part(string $name): string {
		$file = tempnam(sys_get_temp_dir(), 'docx');
		file_put_contents($file, $this->written);
		$zip = new ZipArchive();
		$zip->open($file);
		$xml = (string) $zip->getFromName($name);
		$zip->close();
		unlink($file);

		return $xml;
	}//end part()
}//end class
