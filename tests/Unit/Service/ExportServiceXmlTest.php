<?php

/**
 * ExportService XML export test.
 *
 * Pins that exportToXml() writes a well-formed document with root `<objects>`
 * and one `<object>` per record: arrays as repeated children, nested objects as
 * nested elements, the `@self` block and a property named `2e-adres` under the
 * `<property name="...">` fallback, and null as `xsi:nil`. From the change
 * export-open-formats-and-public-download (task 1.2).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
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

namespace Unit\Service;

use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectExportRequest;
use OCA\OpenRegister\Service\Export\XmlObjectWriter;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExportService::class)]
#[CoversClass(XmlObjectWriter::class)]
class ExportServiceXmlTest extends TestCase {

	private ExportService $service;

	private ObjectService $objectService;

	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);

		$this->service = new ExportService(
			$this->createMock(RegisterMapper::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IGroupManager::class),
			$this->objectService,
			$this->createMock(CacheHandler::class),
			$this->createMock(PropertyRbacHandler::class),
		);
	}//end setUp()

	private function schema(): Schema {
		$schema = new Schema();
		$schema->setSlug('meldingen');
		$schema->setProperties(['title' => ['type' => 'string']]);

		return $schema;
	}//end schema()

	/**
	 * Parse the document; loadXML() fails on anything that is not well formed.
	 *
	 * @param string $xml The document.
	 *
	 * @return DOMXPath A query over it.
	 */
	private function parse(string $xml): DOMXPath {
		$document = new DOMDocument();
		$this->assertTrue($document->loadXML($xml), 'the export is well-formed XML');

		return new DOMXPath($document);
	}//end parse()

	/**
	 * The direct child elements of a node, as name => text pairs in order.
	 *
	 * @param DOMElement $element The node.
	 *
	 * @return array<int, array{0: string, 1: string}> The children.
	 */
	private function children(DOMElement $element): array {
		$out = [];
		foreach ($element->childNodes as $child) {
			if ($child instanceof DOMElement) {
				$out[] = [$child->tagName === 'property' ? '@' . $child->getAttribute('name') : $child->tagName, trim($child->textContent)];
			}
		}

		return $out;
	}//end children()

	public function testXmlRefusesAnExportWithoutASchema(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('XML export requires a specific schema.');

		$this->service->exportToXml(request: new ObjectExportRequest(register: new Register()));
	}//end testXmlRefusesAnExportWithoutASchema()

	public function testTheExportScenarioOneObjectElementPerRecord(): void {
		$objects = [];
		for ($i = 1; $i <= 45; $i++) {
			$object = new ObjectEntity();
			$object->setUuid('uuid-' . $i);
			$object->setObject(['title' => 'Melding ' . $i]);
			$objects[] = $object;
		}

		$this->objectService->method('searchObjects')->willReturn($objects);

		$xpath = $this->parse($this->service->exportToXml(request: new ObjectExportRequest(schema: $this->schema())));

		$this->assertSame('objects', $xpath->document->documentElement->tagName);
		$this->assertSame(45, $xpath->query('/objects/object')->length);
		$this->assertSame('Melding 7', $xpath->query("/objects/object[@id='uuid-7']/title")->item(0)->textContent);
	}//end testTheExportScenarioOneObjectElementPerRecord()

	public function testArraysNestedObjectsSelfAndAnInvalidNameAreKept(): void {
		$object = new ObjectEntity();
		$object->setUuid('uuid-1');
		$object->setName('Lantaarnpaal');
		$object->setObject(
			[
				'title' => 'Lantaarnpaal kapot',
				'tags' => ['straat', 'verlichting'],
				'locatie' => ['straat' => 'Kerkstraat', 'huisnummer' => 12],
				'2e-adres' => 'Achterom 3',
				'opmerking' => null,
				'spoed' => true,
			]
		);

		$this->objectService->method('searchObjects')->willReturn([$object]);

		$xpath = $this->parse($this->service->exportToXml(request: new ObjectExportRequest(schema: $this->schema())));
		$record = $xpath->query("/objects/object[@id='uuid-1']")->item(0);
		$this->assertInstanceOf(DOMElement::class, $record);

		$this->assertSame(['straat', 'verlichting'], array_map(static fn ($node) => $node->textContent, iterator_to_array($xpath->query('tags', $record))));
		$this->assertSame('Kerkstraat', $xpath->query('locatie/straat', $record)->item(0)->textContent);
		$this->assertSame('12', $xpath->query('locatie/huisnummer', $record)->item(0)->textContent);
		$this->assertSame('Achterom 3', $xpath->query("property[@name='2e-adres']", $record)->item(0)->textContent);
		$this->assertSame('true', $xpath->query('spoed', $record)->item(0)->textContent);

		$nil = $xpath->query('opmerking', $record)->item(0);
		$this->assertSame('true', $nil->getAttributeNS(XmlObjectWriter::XSI_NAMESPACE, 'nil'));
		$this->assertSame('', $nil->textContent);

		$self = $xpath->query("property[@name='@self']", $record)->item(0);
		$this->assertInstanceOf(DOMElement::class, $self, 'the @self block is kept under the fallback element');
		$this->assertSame('uuid-1', $xpath->query('id', $self)->item(0)->textContent);
		$this->assertSame('Lantaarnpaal', $xpath->query('name', $self)->item(0)->textContent);
	}//end testArraysNestedObjectsSelfAndAnInvalidNameAreKept()
}//end class
