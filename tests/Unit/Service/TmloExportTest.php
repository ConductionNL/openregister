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
use OCA\OpenRegister\Service\TmloService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for TMLO MDTO XML export
 *
 * @covers \OCA\OpenRegister\Service\TmloService
 */
class TmloExportTest extends TestCase
{

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
    protected function setUp(): void
    {
        $this->service = new TmloService(
            $this->createMock(RegisterMapper::class),
            $this->createMock(SchemaMapper::class),
            $this->createMock(LoggerInterface::class)
        );
    }//end setUp()


    /**
     * Test generateMdtoXml produces valid XML with all TMLO fields.
     *
     * @return void
     */
    public function testGenerateMdtoXmlFullObject(): void
    {
        $object = new ObjectEntity();
        $object->setUuid('test-uuid-123');
        $object->setName('Test Object');
        $object->setTmlo([
            'classificatie'         => '1.1',
            'archiefnominatie'      => 'blijvend_bewaren',
            'archiefactiedatum'     => '2030-01-01',
            'archiefstatus'         => 'semi_statisch',
            'bewaarTermijn'         => 'P7Y',
            'vernietigingsCategorie' => null,
        ]);

        $xml = $this->service->generateMdtoXml($object);

        $this->assertStringContainsString('<?xml', $xml);
        $this->assertStringContainsString('mdto:informatieobject', $xml);
        $this->assertStringContainsString('test-uuid-123', $xml);
        $this->assertStringContainsString('Test Object', $xml);
        $this->assertStringContainsString('1.1', $xml);
        $this->assertStringContainsString('2030-01-01', $xml);
        $this->assertStringContainsString('P7Y', $xml);
    }//end testGenerateMdtoXmlFullObject()


    /**
     * Test generateMdtoXml throws exception for object without TMLO.
     *
     * @return void
     */
    public function testGenerateMdtoXmlThrowsForMissingTmlo(): void
    {
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
    public function testGenerateMdtoXmlMapsArchiefnominatie(): void
    {
        $object = new ObjectEntity();
        $object->setUuid('test-uuid-map');
        $object->setName('Mapping Test');
        $object->setTmlo([
            'archiefnominatie' => 'blijvend_bewaren',
            'archiefstatus'    => 'actief',
        ]);

        $xml = $this->service->generateMdtoXml($object);

        $this->assertStringContainsString('bewaren', $xml);
    }//end testGenerateMdtoXmlMapsArchiefnominatie()


    /**
     * Test generateBatchMdtoXml with multiple objects.
     *
     * @return void
     */
    public function testGenerateBatchMdtoXml(): void
    {
        $object1 = new ObjectEntity();
        $object1->setUuid('uuid-1');
        $object1->setName('Object 1');
        $object1->setTmlo([
            'archiefstatus' => 'actief',
            'classificatie' => '1.1',
        ]);

        $object2 = new ObjectEntity();
        $object2->setUuid('uuid-2');
        $object2->setName('Object 2');
        $object2->setTmlo([
            'archiefstatus' => 'semi_statisch',
            'classificatie' => '2.1',
        ]);

        $xml = $this->service->generateBatchMdtoXml([$object1, $object2]);

        $this->assertStringContainsString('<?xml', $xml);
        $this->assertStringContainsString('mdto:informatieobjecten', $xml);
        $this->assertStringContainsString('uuid-1', $xml);
        $this->assertStringContainsString('uuid-2', $xml);
    }//end testGenerateBatchMdtoXml()


    /**
     * Test generateBatchMdtoXml skips objects without TMLO.
     *
     * @return void
     */
    public function testGenerateBatchMdtoXmlSkipsNoTmlo(): void
    {
        $withTmlo = new ObjectEntity();
        $withTmlo->setUuid('uuid-with');
        $withTmlo->setName('With TMLO');
        $withTmlo->setTmlo(['archiefstatus' => 'actief']);

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
    public function testGenerateBatchMdtoXmlEmpty(): void
    {
        $xml = $this->service->generateBatchMdtoXml([]);

        $this->assertStringContainsString('<?xml', $xml);
        $this->assertStringContainsString('mdto:informatieobjecten', $xml);
    }//end testGenerateBatchMdtoXmlEmpty()


    /**
     * Test generateMdtoXml handles special XML characters in data.
     *
     * @return void
     */
    public function testGenerateMdtoXmlEscapesSpecialChars(): void
    {
        $object = new ObjectEntity();
        $object->setUuid('uuid-special');
        $object->setName('Test & <Object>');
        $object->setTmlo([
            'classificatie' => '1.1 & 2.2',
            'archiefstatus' => 'actief',
        ]);

        $xml = $this->service->generateMdtoXml($object);

        // Should produce valid XML (no parse errors).
        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($xml));
    }//end testGenerateMdtoXmlEscapesSpecialChars()


}//end class
