<?php

/**
 * PresentationSlideParserGoldenTest
 *
 * Golden tests that pin what
 * {@see \OCA\OpenRegister\Files\Service\TextExtraction\PresentationSlideParser}
 * reads today out of hand-built PresentationML: slide order, titles, body
 * order across groups, tables and markup-compatibility branches, pictures,
 * the group-depth cap, the hidden flag and speaker notes.
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

use DOMDocument;
use OCA\OpenRegister\Files\Service\TextExtraction\PresentationSlideParser;
use PHPUnit\Framework\TestCase;

/**
 * Pins the output of PresentationSlideParser's three public reads.
 *
 * @covers \OCA\OpenRegister\Files\Service\TextExtraction\PresentationSlideParser
 */
class PresentationSlideParserGoldenTest extends TestCase {

	private const NS = 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"'
		.' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
		.' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
		.' xmlns:s="http://purl.oclc.org/ooxml/officeDocument/relationships"'
		.' xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006"'
		.' xmlns:x="urn:other"';

	/**
	 * Parse XML into a document.
	 *
	 * @param string $xml The XML.
	 *
	 * @return DOMDocument
	 */
	private static function doc(string $xml): DOMDocument {
		$doc = new DOMDocument();
		$doc->loadXML($xml);
		return $doc;
	}//end doc()

	/**
	 * A text shape.
	 *
	 * @param string $type       Placeholder type; '' for none, '-' for an untyped placeholder.
	 * @param string $paragraphs Raw `a:p` markup.
	 *
	 * @return string
	 */
	private static function sp(string $type, string $paragraphs): string {
		$ph = '';
		if ($type === '-') {
			$ph = '<p:nvPr><p:ph idx="1"/></p:nvPr>';
		} else if ($type !== '') {
			$ph = '<p:nvPr><p:ph type="'.$type.'"/></p:nvPr>';
		}

		return '<p:sp><p:nvSpPr><p:cNvPr id="1" name="s"/>'.$ph.'</p:nvSpPr><p:txBody>'.$paragraphs.'</p:txBody></p:sp>';
	}//end sp()

	/**
	 * A paragraph made of runs.
	 *
	 * @param string ...$runs Run texts.
	 *
	 * @return string
	 */
	private static function p(string ...$runs): string {
		$xml = '<a:p>';
		foreach ($runs as $run) {
			$xml .= '<a:r><a:t>'.$run.'</a:t></a:r>';
		}

		return $xml.'</a:p>';
	}//end p()

	/**
	 * Slide ids come back in deck order, from either relationship namespace.
	 *
	 * @return void
	 */
	public function testSlideRelationshipIds(): void {
		$xml = '<p:presentation '.self::NS.'><p:sldIdLst>'
			.'<p:sldId id="258" r:id="rId3"/><p:sldId id="256" s:id="rId1"/>'
			.'<p:sldId id="257" x:id="rIdX"/><p:sldId id="259" r:id="" s:id="rId9"/>'
			.'</p:sldIdLst></p:presentation>';

		self::assertSame(['rId3', 'rId1', '', ''], (new PresentationSlideParser())->slideRelationshipIds(self::doc($xml)));
	}//end testSlideRelationshipIds()

	/**
	 * A rich slide reads to the pinned title, body and pictures.
	 *
	 * @return void
	 */
	public function testRichSlide(): void {
		$deep = self::sp('', self::p('TooDeep'));
		for ($i = 0; $i < 21; $i++) {
			$deep = '<p:grpSp>'.$deep.'</p:grpSp>';
		}

		$edge = self::sp('', self::p('AtCap'));
		for ($i = 0; $i < 20; $i++) {
			$edge = '<p:grpSp>'.$edge.'</p:grpSp>';
		}

		$tree = self::sp('title', self::p('Eerste', ' titel').self::p('deel twee'))
			.self::sp('sldNum', self::p('7'))
			.self::sp('dt', self::p('datum'))
			.self::sp('ctrTitle', self::p('  '))
			.self::sp('ctrTitle', self::p('Tweede'))
			.self::sp('body', self::p('Body  a').'<a:p><a:r><a:t>regel</a:t></a:r><a:br/><a:r><a:t>twee </a:t></a:r></a:p><a:p/>'.self::p('   '))
			.self::sp('-', self::p('Untyped'))
			.'<p:grpSp>'.self::sp('', self::p('Groep')).'<p:grpSp>'.self::sp('title', self::p('Groepstitel')).'</p:grpSp></p:grpSp>'
			.'<p:graphicFrame><a:graphic><a:graphicData><a:tbl><a:tr><a:tc><a:txBody>'.self::p('Cel 1').'</a:txBody></a:tc>'
			.'<a:tc><a:txBody>'.self::p('Cel 2').self::p('Cel 2b').'</a:txBody></a:tc><a:tc/></a:tr></a:tbl></a:graphicData></a:graphic></p:graphicFrame>'
			.'<p:pic><p:nvPicPr><p:cNvPr id="4" name="Foto" descr="Een foto"/></p:nvPicPr><p:blipFill><a:blip r:embed="rId2"/></p:blipFill></p:pic>'
			.'<p:pic><p:nvPicPr><p:cNvPr id="5" name="Link"/></p:nvPicPr><p:blipFill><a:blip r:link="rId5"/></p:blipFill></p:pic>'
			.'<p:pic><p:nvPicPr><p:cNvPr id="6" name="Weg"/></p:nvPicPr><p:blipFill><a:blip r:embed="rIdMissing"/></p:blipFill></p:pic>'
			.'<p:pic/>'
			.'<mc:AlternateContent><mc:Choice Requires="x">'.self::sp('', self::p('Keuze')).'</mc:Choice>'
			.'<mc:Fallback>'.self::sp('', self::p('Terugval')).'</mc:Fallback></mc:AlternateContent>'
			.'<mc:AlternateContent><mc:Choice Requires="x">'.self::sp('', self::p('AlleenKeuze')).'</mc:Choice></mc:AlternateContent>'
			.'<mc:AlternateContent/>'
			.'<p:cxnSp/>'
			.$deep.$edge
			.self::sp('', self::p('Laatste'));
		$xml  = '<p:sld '.self::NS.' show="0"><p:cSld><p:spTree>'.$tree.'</p:spTree></p:cSld></p:sld>';
		$rels = [
			'rId2' => ['type' => 'image', 'target' => 'ppt/media/image1.png', 'external' => false],
			'rId5' => ['type' => 'image', 'target' => 'https://example.org/x.png', 'external' => true],
		];

		self::assertSame(
			[
				'hidden' => true,
				'title'  => 'Eerste titel deel twee Tweede Groepstitel',
				'body'   => ['Body a', 'regel twee', 'Untyped', 'Groep', 'Cel 1', 'Cel 2', 'Cel 2b', 'Terugval', 'AlleenKeuze', 'AtCap', 'Laatste'],
				'images' => [
					['target' => 'ppt/media/image1.png', 'external' => false, 'name' => 'Foto', 'description' => 'Een foto'],
					['target' => 'https://example.org/x.png', 'external' => true, 'name' => 'Link', 'description' => ''],
					['target' => '', 'external' => false, 'name' => 'Weg', 'description' => ''],
					['target' => '', 'external' => false, 'name' => '', 'description' => ''],
				],
			],
			(new PresentationSlideParser())->parseSlide(self::doc($xml), $rels)
		);
	}//end testRichSlide()

	/**
	 * The hidden flag and a slide without a shape tree.
	 *
	 * @return void
	 */
	public function testHiddenFlagAndEmptySlide(): void {
		$parser = new PresentationSlideParser();
		$empty  = ['hidden' => false, 'title' => '', 'body' => [], 'images' => []];
		self::assertSame($empty, $parser->parseSlide(self::doc('<p:sld '.self::NS.'/>'), []));
		self::assertSame($empty, $parser->parseSlide(self::doc('<p:sld '.self::NS.' show="1"><p:cSld><p:spTree/></p:cSld></p:sld>'), []));
		self::assertTrue($parser->parseSlide(self::doc('<p:sld '.self::NS.' show="false"/>'), [])['hidden']);
		self::assertFalse($parser->parseSlide(self::doc('<p:sld '.self::NS.' show="no"/>'), [])['hidden']);
	}//end testHiddenFlagAndEmptySlide()

	/**
	 * Notes read every non-furniture text shape, paragraphs joined by newlines.
	 *
	 * @return void
	 */
	public function testNotes(): void {
		$xml = '<p:notes '.self::NS.'><p:cSld><p:spTree>'
			.self::sp('sldImg', self::p('plaatje'))
			.self::sp('body', self::p('Eerste notitie').self::p('tweede', ' regel'))
			.self::sp('hdr', self::p('kop'))
			.self::sp('ftr', self::p('voet'))
			.self::sp('', self::p('Los tekstvak'))
			.'<p:sp><p:nvSpPr><p:cNvPr id="9" name="leeg"/></p:nvSpPr></p:sp>'
			.'<p:grpSp>'.self::sp('title', self::p('In groep')).'</p:grpSp>'
			.'</p:spTree></p:cSld></p:notes>';

		$parser = new PresentationSlideParser();
		self::assertSame("Eerste notitie\ntweede regel\nLos tekstvak\nIn groep", $parser->parseNotes(self::doc($xml)));
		self::assertSame('', $parser->parseNotes(self::doc('<p:notes '.self::NS.'/>')));
	}//end testNotes()
}//end class
