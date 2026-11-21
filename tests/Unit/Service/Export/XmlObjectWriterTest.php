<?php

/**
 * XmlObjectWriter test.
 *
 * The edges of the XML export writer: names that cannot be element names,
 * lists inside lists, empty values, objects that serialise, characters XML 1.0
 * does not allow, and values that would break markup. From the change
 * export-open-formats-and-public-download (task 1.2).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Export
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
 * @spec openspec/specs/data-import-export/spec.md#requirement-the-system-must-support-structured-export-to-csv-excel-xlsx-json-xml-and-ods-formats
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace Unit\Service\Export;

use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use OCA\OpenRegister\Service\Export\XmlObjectWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(XmlObjectWriter::class)]
class XmlObjectWriterTest extends TestCase {

	private function xpath(array $rows): DOMXPath {
		$document = new DOMDocument();
		$this->assertTrue($document->loadXML((new XmlObjectWriter())->write($rows)), 'well-formed XML');

		return new DOMXPath($document);
	}//end xpath()

	public static function elementNames(): array {
		return [
			'plain' => ['title', true],
			'underscore start' => ['_id', true],
			'dash and dot inside' => ['post-code.nl', true],
			'digit start' => ['2e-adres', false],
			'at sign' => ['@self', false],
			'space' => ['eerste adres', false],
			'colon' => ['xsi:nil', false],
			'empty' => ['', false],
			'accented letter' => ['état', false],
		];
	}//end elementNames()

	#[DataProvider('elementNames')]
	public function testWhichNamesAreElementNames(string $name, bool $expected): void {
		$this->assertSame($expected, XmlObjectWriter::isElementName($name));
	}//end testWhichNamesAreElementNames()

	public function testAnEmptyExportIsAnEmptyRoot(): void {
		$xpath = $this->xpath([]);

		$this->assertSame('objects', $xpath->document->documentElement->tagName);
		$this->assertSame(0, $xpath->query('/objects/*')->length);
	}//end testAnEmptyExportIsAnEmptyRoot()

	public function testTheIdComesFromSelfThenFromTheRecord(): void {
		$xpath = $this->xpath([['@self' => ['id' => 'from-self'], 'id' => 'own'], ['id' => 42], ['title' => 'no id']]);

		$this->assertSame(['from-self', '42', ''], array_map(static fn ($node) => $node->getAttribute('id'), iterator_to_array($xpath->query('/objects/object'))));
		$this->assertFalse($xpath->query('/objects/object')->item(2)->hasAttribute('id'));
	}//end testTheIdComesFromSelfThenFromTheRecord()

	public function testAListInsideAListKeepsItsStructure(): void {
		$xpath = $this->xpath([['grid' => [[1, 2], [3]]]]);

		$this->assertSame(2, $xpath->query('/objects/object/grid')->length);
		$this->assertSame(['1', '2'], array_map(static fn ($node) => $node->textContent, iterator_to_array($xpath->query('/objects/object/grid[1]/item'))));
		$this->assertSame(['3'], array_map(static fn ($node) => $node->textContent, iterator_to_array($xpath->query('/objects/object/grid[2]/item'))));
	}//end testAListInsideAListKeepsItsStructure()

	public function testAListOfObjectsIsRepeatedNestedElements(): void {
		$xpath = $this->xpath([['bijlagen' => [['naam' => 'a.pdf'], ['naam' => 'b.pdf']]]]);

		$this->assertSame(['a.pdf', 'b.pdf'], array_map(static fn ($node) => $node->textContent, iterator_to_array($xpath->query('/objects/object/bijlagen/naam'))));
	}//end testAListOfObjectsIsRepeatedNestedElements()

	public function testAnEmptyArrayIsAnEmptyElementNotNil(): void {
		$xpath = $this->xpath([['tags' => []]]);
		$tags = $xpath->query('/objects/object/tags')->item(0);

		$this->assertNotNull($tags);
		$this->assertSame('', $tags->textContent);
		$this->assertFalse($tags->hasAttributeNS(XmlObjectWriter::XSI_NAMESPACE, 'nil'));
	}//end testAnEmptyArrayIsAnEmptyElementNotNil()

	public function testNullAndFalseAndNumbersAreDistinct(): void {
		$xpath = $this->xpath([['a' => null, 'b' => false, 'c' => 0, 'd' => 1.5, 'e' => '']]);
		$object = '/objects/object/';

		$this->assertSame('true', $xpath->query($object . 'a')->item(0)->getAttributeNS(XmlObjectWriter::XSI_NAMESPACE, 'nil'));
		$this->assertSame('false', $xpath->query($object . 'b')->item(0)->textContent);
		$this->assertSame('0', $xpath->query($object . 'c')->item(0)->textContent);
		$this->assertSame('1.5', $xpath->query($object . 'd')->item(0)->textContent);
		$this->assertFalse($xpath->query($object . 'e')->item(0)->hasAttributeNS(XmlObjectWriter::XSI_NAMESPACE, 'nil'), 'an empty string is not null');
	}//end testNullAndFalseAndNumbersAreDistinct()

	public function testAnInvalidNameInsideANestedObjectAlsoFallsBack(): void {
		$xpath = $this->xpath([['adres' => ['1e regel' => 'Kerkstraat 1']]]);

		$this->assertSame('Kerkstraat 1', $xpath->query("/objects/object/adres/property[@name='1e regel']")->item(0)->textContent);
	}//end testAnInvalidNameInsideANestedObjectAlsoFallsBack()

	public function testMarkupInValuesIsEscapedAndNoDoctypeIsWritten(): void {
		$value = '<script>alert("x")</script> & <!ENTITY e "boom">';
		$xml = (new XmlObjectWriter())->write([['title' => $value]]);

		$this->assertStringNotContainsString('<!DOCTYPE', $xml);
		$this->assertStringNotContainsString('<script>', $xml);
		$this->assertSame($value, $this->xpath([['title' => $value]])->query('/objects/object/title')->item(0)->textContent);
	}//end testMarkupInValuesIsEscapedAndNoDoctypeIsWritten()

	public function testCharactersXmlDoesNotAllowAreReplacedNotDropped(): void {
		$xpath = $this->xpath([['title' => "bell\x07 and nul\x00 end", 'bad' => "broken \xC3\x28 byte"]]);

		$this->assertSame("bell\u{FFFD} and nul\u{FFFD} end", $xpath->query('/objects/object/title')->item(0)->textContent);
		$this->assertStringStartsWith('broken ', $xpath->query('/objects/object/bad')->item(0)->textContent);
	}//end testCharactersXmlDoesNotAllowAreReplacedNotDropped()

	public function testObjectsAndDatesAreWrittenAsTheirValues(): void {
		$when = new DateTimeImmutable('2026-11-13T12:00:00+00:00');
		$xpath = $this->xpath([['when' => $when, 'thing' => (object)['kleur' => 'rood']]]);

		$this->assertSame('2026-11-13T12:00:00+00:00', $xpath->query('/objects/object/when')->item(0)->textContent);
		$this->assertSame('rood', $xpath->query('/objects/object/thing/kleur')->item(0)->textContent);
	}//end testObjectsAndDatesAreWrittenAsTheirValues()

	public function testTheDocumentIsUtf8AndKeepsUnicode(): void {
		$xml = (new XmlObjectWriter())->write([['title' => 'Café één']]);

		$this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $xml);
		$this->assertStringContainsString('Café één', $xml);
	}//end testTheDocumentIsUtf8AndKeepsUnicode()
}//end class
