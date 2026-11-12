<?php

/**
 * DocumentStyleMap golden test.
 *
 * Records how DocumentStyleMap classifies a set of paragraphs against a styles
 * part and a numbering part, so a refactor of the map can be shown to keep its
 * behaviour.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\TextExtraction
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
 * @spec openspec/specs/text-extraction-document/spec.md
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\TextExtraction;

use DOMDocument;
use DOMElement;
use OCA\OpenRegister\Files\Service\TextExtraction\DocumentStyleMap;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Files\Service\TextExtraction\DocumentStyleMap
 */
class DocumentStyleMapGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/document-style-map-golden.json';

	private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		return ['with-parts' => ['with-parts'], 'styles-only' => ['styles-only'], 'no-parts' => ['no-parts']];
	}//end cases()

	/**
	 * Paragraph properties (`w:pPr` content), by name.
	 *
	 * @return array<string, string>
	 */
	private static function paragraphs(): array {
		$styles = [
			'Title', 'Heading1', 'Heading9', 'Heading0', 'Heading10', 'heading1', 'MyTitle', 'MyHeading', 'Outline0',
			'Outline8', 'Outline9', 'Child', 'GrandChild', 'LoopA', 'Deep24', 'Deep5', 'Heading4', 'HeadingX', 'Normal', 'Listed', 'ListedChild',
			'ListedNoNum', 'Bulleted', 'Unknown', 'Spaced', 'Heading3', 'Title2', 'NotParagraph',
		];
		$out = ['none' => '', 'empty-ppr' => '<w:pPr/>'];
		foreach ($styles as $style) {
			$out['style-' . $style] = '<w:pPr><w:pStyle w:val="' . $style . '"/></w:pPr>';
		}

		return array_merge(
			$out,
			[
				'outline-own-0' => '<w:pPr><w:outlineLvl w:val="0"/></w:pPr>',
				'outline-own-9-on-heading' => '<w:pPr><w:pStyle w:val="Heading1"/><w:outlineLvl w:val="9"/></w:pPr>',
				'outline-own-3-on-list' => '<w:pPr><w:pStyle w:val="Listed"/><w:outlineLvl w:val="3"/></w:pPr>',
				'outline-own-text' => '<w:pPr><w:outlineLvl w:val="x"/></w:pPr>',
				'num-own' => '<w:pPr><w:numPr><w:ilvl w:val="1"/><w:numId w:val="1"/></w:numPr></w:pPr>',
				'num-own-no-level' => '<w:pPr><w:numPr><w:numId w:val="2"/></w:numPr></w:pPr>',
				'num-own-zero' => '<w:pPr><w:pStyle w:val="Listed"/><w:numPr><w:numId w:val="0"/></w:numPr></w:pPr>',
				'num-own-unknown' => '<w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="99"/></w:numPr></w:pPr>',
				'num-own-level-only-on-style' => '<w:pPr><w:pStyle w:val="Listed"/><w:numPr><w:ilvl w:val="2"/></w:numPr></w:pPr>',
				'num-own-on-heading' => '<w:pPr><w:pStyle w:val="Heading3"/><w:numPr><w:numId w:val="1"/></w:numPr></w:pPr>',
				'num-own-deep-level' => '<w:pPr><w:numPr><w:ilvl w:val="5"/><w:numId w:val="1"/></w:numPr></w:pPr>',
			]
		);
	}//end paragraphs()

	private static function stylesXml(): string {
		$style = static function (string $id, string $name, string $extra = '', string $type = 'paragraph'): string {
			return '<w:style w:type="' . $type . '" w:styleId="' . $id . '"><w:name w:val="' . $name . '"/>' . $extra . '</w:style>';
		};
		$deep = '';
		for ($i = 0; $i < 25; $i++) {
			$deep .= $style('Deep' . $i, 'deep ' . $i, '<w:basedOn w:val="Deep' . ($i - 1) . '"/>');
		}

		$deep .= $style('Deep-1', 'Heading 4');
		return '<w:styles xmlns:w="' . self::W . '">'
			. $style('Title', 'Title')
			. $style('Heading1', 'heading 1')
			. $style('Heading9', 'Heading 9')
			. $style('Heading0', 'heading 0')
			. $style('Heading10', 'heading 10')
			. $style('MyTitle', ' TITLE ')
			. $style('MyHeading', 'Heading2')
			. $style('Outline0', 'outlined', '<w:pPr><w:outlineLvl w:val="0"/></w:pPr>')
			. $style('Outline8', 'outlined', '<w:pPr><w:outlineLvl w:val="8"/></w:pPr>')
			. $style('Outline9', 'body', '<w:pPr><w:outlineLvl w:val="9"/></w:pPr>')
			. $style('Child', 'child', '<w:basedOn w:val="MyTitle"/>')
			. $style('GrandChild', 'grandchild', '<w:basedOn w:val="Outline9"/>')
			. $style('LoopA', 'loop a', '<w:basedOn w:val="LoopB"/>')
			. $style('LoopB', 'loop b', '<w:basedOn w:val="LoopA"/>')
			. $deep
			. $style('Normal', 'Normal')
			. $style('Listed', 'list', '<w:pPr><w:numPr><w:ilvl w:val="1"/><w:numId w:val="1"/></w:numPr></w:pPr>')
			. $style('ListedChild', 'list child', '<w:basedOn w:val="Listed"/><w:pPr><w:numPr><w:numId w:val="2"/></w:numPr></w:pPr>')
			. $style('ListedNoNum', 'list none', '<w:basedOn w:val="Listed"/><w:pPr><w:numPr><w:numId w:val="0"/></w:numPr></w:pPr>')
			. $style('Bulleted', 'bullets', '<w:pPr><w:numPr><w:numId w:val="2"/></w:numPr></w:pPr>')
			. $style('Spaced', 'heading  5')
			. $style('Heading3', 'Heading 3', '<w:pPr><w:outlineLvl w:val="6"/></w:pPr>')
			. $style('Title2', 'title', '<w:basedOn w:val="Heading1"/>')
			. $style('NotParagraph', 'heading 1', '', 'character')
			. '</w:styles>';
	}//end stylesXml()

	private static function numberingXml(): string {
		return '<w:numbering xmlns:w="' . self::W . '">'
			. '<w:abstractNum w:abstractNumId="10"><w:lvl w:ilvl="0"><w:numFmt w:val="decimal"/></w:lvl><w:lvl w:ilvl="1"><w:numFmt w:val="lowerLetter"/></w:lvl><w:lvl w:ilvl="2"><w:numFmt w:val="bullet"/></w:lvl><w:lvl w:ilvl="x"><w:numFmt w:val="decimal"/></w:lvl></w:abstractNum>'
			. '<w:abstractNum w:abstractNumId="20"><w:lvl w:ilvl="0"><w:numFmt w:val="bullet"/></w:lvl><w:lvl w:ilvl="1"><w:numFmt w:val="none"/></w:lvl><w:lvl w:ilvl="2"/></w:abstractNum>'
			. '<w:num w:numId="1"><w:abstractNumId w:val="10"/></w:num>'
			. '<w:num w:numId="2"><w:abstractNumId w:val="20"/></w:num>'
			. '</w:numbering>';
	}//end numberingXml()

	/**
	 * @dataProvider cases
	 */
	public function testDocumentStyleMapMatchesGolden(string $name): void {
		$recorded = $this->record(parts: $name);

		$all = [];
		if (file_exists(self::FIXTURE) === true) {
			$all = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('OR_GOLDEN_WRITE') === '1') {
			$all[$name] = $recorded;
			ksort($all);
			file_put_contents(self::FIXTURE, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $all);
		$this->assertNotEmpty($all[$name]);
		$this->assertSame($all[$name], $recorded);
	}//end testDocumentStyleMapMatchesGolden()

	/**
	 * @return array<string, mixed>
	 */
	private function record(string $parts): array {
		$styles = null;
		$numbering = null;
		if ($parts !== 'no-parts') {
			$styles = $this->xml(source: self::stylesXml());
		}

		if ($parts === 'with-parts') {
			$numbering = $this->xml(source: self::numberingXml());
		}

		$map = new DocumentStyleMap($styles, $numbering);
		$out = [];
		foreach (self::paragraphs() as $label => $properties) {
			$document = $this->xml(source: '<w:body xmlns:w="' . self::W . '"><w:p>' . $properties . '<w:r><w:t>x</w:t></w:r></w:p></w:body>');
			$paragraph = $document->getElementsByTagNameNS(self::W, 'p')->item(0);
			$this->assertInstanceOf(DOMElement::class, $paragraph);
			$out[$label] = $map->classify($paragraph);
		}

		return $out;
	}//end record()

	private function xml(string $source): DOMDocument {
		$document = new DOMDocument();
		$this->assertTrue($document->loadXML($source));
		return $document;
	}//end xml()
}//end class
