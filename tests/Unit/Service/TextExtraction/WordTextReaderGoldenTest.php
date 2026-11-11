<?php

/**
 * WordTextReaderGoldenTest
 *
 * Golden tests that pin the exact text
 * {@see \OCA\OpenRegister\Files\Service\TextExtraction\WordTextReader}
 * produces today for the Word fixtures and for hand-built packages that
 * exercise part order, skipped relationships, note separators and ODT
 * inline elements.
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
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\TextExtraction;

use OCA\OpenRegister\Files\Service\TextExtraction\WordTextReader;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Pins the full output of WordTextReader::read().
 *
 * @covers \OCA\OpenRegister\Files\Service\TextExtraction\WordTextReader
 */
class WordTextReaderGoldenTest extends TestCase {

	private const W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';

	private const ODT_NS = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
		.' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
		.' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"'
		.' xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"';

	/**
	 * Zip the given parts into package bytes.
	 *
	 * @param array<string,string> $parts Part path => content.
	 *
	 * @return string
	 */
	private static function zip(array $parts): string {
		$path = (string) tempnam(sys_get_temp_dir(), 'or-word');
		$zip  = new ZipArchive();
		$zip->open($path, ZipArchive::OVERWRITE);
		foreach ($parts as $name => $content) {
			$zip->addFromString($name, $content);
		}

		$zip->close();
		$bytes = (string) file_get_contents($path);
		unlink($path);
		return $bytes;
	}//end zip()

	/**
	 * A relationships part.
	 *
	 * @param array<int,array{0:string,1:string,2:bool}> $rels Id-less list of [type suffix, target, external].
	 *
	 * @return string
	 */
	private static function rels(array $rels): string {
		$xml = '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
		foreach ($rels as $i => [$type, $target, $external]) {
			$mode = '';
			if ($external === true) {
				$mode = ' TargetMode="External"';
			}

			$xml .= '<Relationship Id="r'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/'.$type.'" Target="'.$target.'"'.$mode.'/>';
		}

		return $xml.'</Relationships>';
	}//end rels()

	/**
	 * Read bytes with the reader.
	 *
	 * @param string $bytes  Package bytes.
	 * @param string $format Format name.
	 *
	 * @return string|null
	 */
	private static function read(string $bytes, string $format): ?string {
		return (new WordTextReader())->read(bytes: $bytes, format: $format);
	}//end read()

	/**
	 * The fixtures read to exactly the text they read to today.
	 *
	 * @return void
	 */
	public function testFixturesReadExactly(): void {
		$dir = dirname(__DIR__, 3).'/Data/Word/';
		self::assertSame(
			"Concept besluit Demostad: subsidie buurthuis De Linde\nHet college besluit de subsidie te verlenen.\nHet bedrag is 15.000 euro.\nBezwaar kan binnen zes weken.\n",
			self::read((string) file_get_contents($dir.'concept-besluit-demostad.docx'), 'Word2007')
		);
		self::assertNull(self::read((string) file_get_contents($dir.'not-a-package.docx'), 'Word2007'));

		$table = "Rol\nNaam\n\nAanvrager\nMaria Bakker\n\nGemachtigde\nJan Jansen\nKind\nSanne Bakker\n\n\n"
			."Eerste punt voor Jan Jansen\nTweede punt\nNaam:\tJan Jansen\nTweede regel\n"
			."Slotzin met Łukasz Żółć uit Gdańsk.\nGesplitst: Jan Jansen tekent.\n";
		self::assertSame(
			"Gemeente Demostad kenmerk ZK-2026-0042\nBesluit op aanvraag\n"
			."Aanvrager Maria Bakker woont aan de Kerkstraat 12 in Demostad.\nHet bedrag is 15.000 euro.\n"
			."Contact via maria.bakker@example.nl.\n".$table
			."Behandelaar Jan Jansen telefoon 0612345678\nVoetnoot over Maria Bakker.\n",
			self::read((string) file_get_contents($dir.'word-structures.docx'), 'Word2007')
		);
		self::assertSame(
			"Gemeente Demostad kenmerk ZK-2026-0042\nBehandelaar Jan Jansen telefoon 0612345678\nBesluit op aanvraag\n"
			."Aanvrager Maria Bakker woont aan de Kerkstraat 12 in Demostad.\nHet bedrag is 15.000 euro.\n"
			."Contact via maria.bakker@example.nl Voetnoot over Maria Bakker..\n".$table,
			self::read((string) file_get_contents($dir.'word-structures.odt'), 'ODText')
		);
	}//end testFixturesReadExactly()

	/**
	 * DOCX parts come out as header, body, footer, footnotes, endnotes;
	 * external and unknown relationships and note separators are skipped.
	 *
	 * @return void
	 */
	public function testDocxPartOrderAndSkips(): void {
		$p     = static fn (string $t): string => '<w:p><w:r><w:t>'.$t.'</w:t></w:r></w:p>';
		$bytes = self::zip(
			[
				'_rels/.rels'                  => self::rels([['officeDocument', 'word/document.xml', false]]),
				'word/_rels/document.xml.rels' => self::rels(
					[
						['endnotes', 'endnotes.xml', false],
						['footnotes', 'footnotes.xml', false],
						['footer', 'footer1.xml', false],
						['header', 'https://example.org/h.xml', true],
						['image', 'media/x.xml', false],
						['header', 'header1.xml', false],
						['header', 'missing.xml', false],
						['header', 'header2.xml', false],
					]
				),
				'word/document.xml'            => '<w:document '.self::W.'><w:body>'.$p('Body')
					.'<w:tbl><w:tr><w:tc>'.$p('A').'</w:tc><w:tc>'.$p('B').'</w:tc></w:tr></w:tbl>'
					.'<w:p><w:r><w:t>x</w:t><w:tab/><w:t>y</w:t><w:br/><w:t>z</w:t><w:cr/></w:r></w:p>'
					.'<w:sdt><w:sdtContent>'.$p('Nested').'</w:sdtContent></w:sdt>'
					.'<other xmlns="urn:x">'.$p('Foreign').'</other></w:body></w:document>',
				'word/header1.xml'             => '<w:hdr '.self::W.'>'.$p('H1').'</w:hdr>',
				'word/header2.xml'             => '<w:hdr '.self::W.'>'.$p('H2').'</w:hdr>',
				'word/footer1.xml'             => '<w:ftr '.self::W.'>'.$p('F1').'</w:ftr>',
				'word/footnotes.xml'           => '<w:footnotes '.self::W.'>'
					.'<w:footnote w:type="separator" w:id="-1">'.$p('SEP').'</w:footnote>'
					.'<w:footnote w:type="continuationSeparator" w:id="0">'.$p('CSEP').'</w:footnote>'
					.'<w:footnote w:type="continuationNotice" w:id="2">'.$p('CNOT').'</w:footnote>'
					.'<w:footnote w:id="1">'.$p('FN').'</w:footnote></w:footnotes>',
				'word/endnotes.xml'            => '<w:endnotes '.self::W.'><w:endnote w:id="1">'.$p('EN').'</w:endnote></w:endnotes>',
				'word/media/x.xml'             => '<w:hdr '.self::W.'>'.$p('IMG').'</w:hdr>',
			]
		);

		self::assertSame("H1\nH2\nBody\nA\nB\n\nx\ty\nz\n\nNested\nF1\nFN\nEN\n", self::read($bytes, 'Word2007'));
	}//end testDocxPartOrderAndSkips()

	/**
	 * A DOCX whose main part is declared but missing reads to null; a
	 * main part with an empty body reads to the empty string.
	 *
	 * @return void
	 */
	public function testDocxMissingAndEmptyMainPart(): void {
		$rels = self::rels([['officeDocument', 'word/document.xml', false]]);
		self::assertNull(self::read(self::zip(['_rels/.rels' => $rels]), 'Word2007'));
		self::assertSame(
			'',
			self::read(self::zip(['_rels/.rels' => $rels, 'word/document.xml' => '<w:document '.self::W.'><w:body/></w:document>']), 'Word2007')
		);
	}//end testDocxMissingAndEmptyMainPart()

	/**
	 * ODT inline elements, notes, annotations, tracked changes and master-page
	 * headers/footers read exactly as today.
	 *
	 * @return void
	 */
	public function testOdtInlineAndBlocks(): void {
		$content = '<office:document-content '.self::ODT_NS.'><office:body><office:text>'
			.'<text:tracked-changes><text:changed-region><text:p>GONE</text:p></text:changed-region></text:tracked-changes>'
			.'<text:h>Kop<text:s text:c="0"/>x<text:s text:c="2"/>y</text:h>'
			.'<text:p>a<text:span>b<text:tab/>c</text:span><text:note><text:note-citation>1</text:note-citation>'
			.'<text:note-body><text:p> Noot </text:p><text:p>twee</text:p></text:note-body></text:note>'
			.'<office:annotation><text:p>COMMENT</text:p></office:annotation>d<text:p>inner</text:p><text:h>ih</text:h>e</text:p>'
			.'<text:list><text:list-item><text:p>item</text:p></text:list-item></text:list>'
			.'<table:table><table:table-row><table:table-cell><text:p>c1</text:p></table:table-cell>'
			.'<table:table-cell><text:p>c2</text:p></table:table-cell></table:table-row></table:table>'
			.'<text:section><text:p>sec</text:p></text:section>'
			.'</office:text></office:body></office:document-content>';
		$styles  = '<office:document-styles '.self::ODT_NS.'><office:master-styles><style:master-page>'
			.'<style:header><text:p>Kopregel</text:p></style:header><style:header-left><text:p>LEFT</text:p></style:header-left>'
			.'<style:footer><text:p>Voetregel</text:p></style:footer></style:master-page></office:master-styles></office:document-styles>';

		self::assertSame(
			"Kopregel\nVoetregel\nKop x  y\nab\tc Noot \ntweed\ninner\nihe\nitem\nc1\nc2\n\nsec\n",
			self::read(self::zip(['content.xml' => $content, 'styles.xml' => $styles]), 'ODText')
		);
		self::assertSame("Kop x  y\nab\tc Noot \ntweed\ninner\nihe\nitem\nc1\nc2\n\nsec\n", self::read(self::zip(['content.xml' => $content]), 'ODText'));
		self::assertSame("Kop x  y\nab\tc Noot \ntweed\ninner\nihe\nitem\nc1\nc2\n\nsec\n", self::read(self::zip(['content.xml' => $content, 'styles.xml' => 'not xml']), 'ODText'));
	}//end testOdtInlineAndBlocks()
}//end class
