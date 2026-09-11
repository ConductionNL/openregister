<?php

/**
 * TMLO MDTO XML Export Unit Tests
 *
 * Tests for MDTO-compliant XML export in TmloService including:
 * - Single object export
 * - Batch export
 * - Error handling for missing metadata
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Edepot\MdtoBestandGenerator;
use OCA\OpenRegister\Service\Edepot\MdtoDocumentWriter;
use OCA\OpenRegister\Service\Edepot\MdtoEventMapper;
use OCA\OpenRegister\Service\Edepot\MdtoSourceReader;
use OCA\OpenRegister\Service\Edepot\MdtoXmlGenerator;
use OCA\OpenRegister\Service\TmloService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for TMLO MDTO XML export
 *
 * @covers \OCA\OpenRegister\Service\TmloService
 */
class TmloExportTest extends TestCase {

	/**
	 * The TmloService under test
	 *
	 * @var TmloService
	 */
	private TmloService $service;

	/**
	 * Set up test fixtures
	 *
	 * @return void
	 */
	protected function setUp(): void {
		// A REAL MdtoXmlGenerator, because the point of this change is that
		// this endpoint emits the same format as the e-Depot export. A mock
		// would assert only that a string came back.
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnMap(
			[
				['openregister', 'organisation_identifier', '', 'ORG-001'],
				['openregister', 'organisation_identifier', 'OpenRegister', 'ORG-001'],
				['openregister', 'organisation_name', '', 'Gemeente Voorbeeld'],
				['openregister', 'organisation_name', 'OpenRegister', 'Gemeente Voorbeeld'],
			]
		);

		$eventMapper = $this->createMock(MdtoEventMapper::class);
		$eventMapper->method('forObject')->willReturn([]);

		$writer = new MdtoDocumentWriter();
		$generator = new MdtoXmlGenerator(
			$appConfig,
			$this->createMock(LoggerInterface::class),
			$eventMapper,
			new MdtoSourceReader(),
			$writer,
			new MdtoBestandGenerator($writer)
		);

		$this->service = new TmloService(
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(LoggerInterface::class),
			$generator
		);
	}//end setUp()

	/**
	 * Test generateMdtoXml produces valid XML with all TMLO fields.
	 *
	 * @return void
	 */
	public function testGenerateMdtoXmlFullObject(): void {
		$object = new ObjectEntity();
		$object->setUuid('test-uuid-123');
		$object->setName('Test Object');
		$object->setTmlo([
			'classification' => '1.1',
			'archiefnominatie' => 'blijvend_bewaren',
			'archiefactiedatum' => '2030-01-01',
			'archiefstatus' => 'semi_statisch',
			'bewaarTermijn' => 'P7Y',
			'vernietigingsCategorie' => null,
		]);

		$xml = $this->service->generateMdtoXml($object);

		$this->assertStringContainsString('<?xml', $xml);
		$this->assertStringContainsString('<mdto:MDTO', $xml);
		$this->assertStringContainsString('<mdto:informatieobject>', $xml);
		$this->assertStringContainsString('test-uuid-123', $xml);
		$this->assertStringContainsString('Test Object', $xml);
		// TMLO's own fields, each under the MDTO element that carries it.
		$this->assertStringContainsString('<mdto:classificatie>', $xml);
		$this->assertStringContainsString('<mdto:begripLabel>1.1</mdto:begripLabel>', $xml);
		$this->assertStringContainsString('<mdto:termijnLooptijd>P7Y</mdto:termijnLooptijd>', $xml);
		$this->assertStringContainsString('<mdto:termijnEinddatum>2030-01-01</mdto:termijnEinddatum>', $xml);
		// archiefstatus is not an MDTO element and is not smuggled in.
		$this->assertStringNotContainsString('archiefstatus', $xml);
		$this->assertStringNotContainsString('semi_statisch', $xml);
		$this->assertStringNotContainsString('vernietigingsCategorie', $xml);
	}//end testGenerateMdtoXmlFullObject()

	/**
	 * Test generateMdtoXml throws exception for object without TMLO.
	 *
	 * @return void
	 */
	public function testGenerateMdtoXmlThrowsForMissingTmlo(): void {
		$object = new ObjectEntity();
		$object->setUuid('test-uuid-no-tmlo');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('no TMLO metadata');

		$this->service->generateMdtoXml($object);
	}//end testGenerateMdtoXmlThrowsForMissingTmlo()

	/**
	 * Test generateMdtoXml maps archiefnominatie to MDTO waardering.
	 *
	 * @return void
	 */
	public function testGenerateMdtoXmlMapsArchiefnominatie(): void {
		$object = new ObjectEntity();
		$object->setUuid('test-uuid-map');
		$object->setName('Mapping Test');
		$object->setTmlo([
			'archiefnominatie' => 'blijvend_bewaren',
			'archiefstatus' => 'actief',
		]);

		$xml = $this->service->generateMdtoXml($object);

		// The CLOSED Waarderingen list, not TMLO's own spelling.
		$this->assertStringContainsString('<mdto:begripLabel>Blijvend te bewaren</mdto:begripLabel>', $xml);
		$this->assertStringContainsString('<mdto:begripCode>B</mdto:begripCode>', $xml);
		$this->assertStringContainsString('<mdto:verwijzingNaam>Waarderingen</mdto:verwijzingNaam>', $xml);
		// No bewaarTermijn on this object, and MDTO allows the element to be absent.
		$this->assertStringNotContainsString('bewaartermijn', $xml);
	}//end testGenerateMdtoXmlMapsArchiefnominatie()

	/**
	 * An object whose appraisal is unknown cannot be valid MDTO, and is refused.
	 *
	 * `waardering` is minOccurs="1". The previous exporter emitted a document
	 * without it, which no e-Depot could accept.
	 *
	 * @return void
	 */
	public function testGenerateMdtoXmlRefusesAnObjectWithoutAnAppraisal(): void {
		$object = new ObjectEntity();
		$object->setUuid('uuid-no-appraisal');
		$object->setName('No appraisal');
		$object->setTmlo(['archiefstatus' => 'actief']);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/archiefnominatie \(one of: /');

		$this->service->generateMdtoXml($object);
	}//end testGenerateMdtoXmlRefusesAnObjectWithoutAnAppraisal()

	/**
	 * Test generateBatchMdtoXml with multiple objects.
	 *
	 * @return void
	 */
	public function testGenerateBatchMdtoXml(): void {
		$object1 = new ObjectEntity();
		$object1->setUuid('uuid-1');
		$object1->setName('Object 1');
		$object1->setTmlo([
			'archiefstatus' => 'actief',
			'classification' => '1.1',
			'archiefnominatie' => 'blijvend_bewaren',
		]);

		$object2 = new ObjectEntity();
		$object2->setUuid('uuid-2');
		$object2->setName('Object 2');
		$object2->setTmlo([
			'archiefstatus' => 'semi_statisch',
			'classification' => '2.1',
			'archiefnominatie' => 'vernietigen',
		]);

		$xml = $this->service->generateBatchMdtoXml([$object1, $object2]);

		$this->assertStringContainsString('<?xml', $xml);
		// The envelope is openregister's own element, not a made-up MDTO one.
		$this->assertStringContainsString('<or:mdtoExport', $xml);
		$this->assertStringContainsString('https://www.openregister.app/mdto-export', $xml);
		$this->assertStringNotContainsString('informatieobjecten', $xml);
		$this->assertSame(2, substr_count($xml, '<mdto:MDTO'));
		$this->assertStringContainsString('uuid-1', $xml);
		$this->assertStringContainsString('uuid-2', $xml);
	}//end testGenerateBatchMdtoXml()

	/**
	 * Test generateBatchMdtoXml skips objects without TMLO.
	 *
	 * @return void
	 */
	public function testGenerateBatchMdtoXmlSkipsNoTmlo(): void {
		$withTmlo = new ObjectEntity();
		$withTmlo->setUuid('uuid-with');
		$withTmlo->setName('With TMLO');
		$withTmlo->setTmlo(['archiefstatus' => 'actief', 'archiefnominatie' => 'blijvend_bewaren']);

		$withoutTmlo = new ObjectEntity();
		$withoutTmlo->setUuid('uuid-without');
		$withoutTmlo->setName('Without TMLO');

		$xml = $this->service->generateBatchMdtoXml([$withTmlo, $withoutTmlo]);

		$this->assertStringContainsString('uuid-with', $xml);
		$this->assertStringNotContainsString('uuid-without', $xml);
	}//end testGenerateBatchMdtoXmlSkipsNoTmlo()

	/**
	 * Test generateBatchMdtoXml with empty array.
	 *
	 * @return void
	 */
	public function testGenerateBatchMdtoXmlEmpty(): void {
		$xml = $this->service->generateBatchMdtoXml([]);

		$this->assertStringContainsString('<?xml', $xml);
		$this->assertStringContainsString('<or:mdtoExport', $xml);
		$this->assertStringNotContainsString('<mdto:MDTO', $xml);
	}//end testGenerateBatchMdtoXmlEmpty()

	/**
	 * Test generateMdtoXml handles special XML characters in data.
	 *
	 * @return void
	 */
	public function testGenerateMdtoXmlEscapesSpecialChars(): void {
		$object = new ObjectEntity();
		$object->setUuid('uuid-special');
		$object->setName('Test & <Object>');
		$object->setTmlo([
			'classification' => '1.1 & 2.2',
			'archiefstatus' => 'actief',
			'archiefnominatie' => 'blijvend_bewaren',
		]);

		$xml = $this->service->generateMdtoXml($object);

		// Should produce valid XML (no parse errors).
		$dom = new \DOMDocument();
		$this->assertTrue($dom->loadXML($xml));
	}//end testGenerateMdtoXmlEscapesSpecialChars()

}//end class
