<?php

declare(strict_types=1);

/**
 * MdtoXmlGenerator Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Edepot
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace Unit\Service\Edepot;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Edepot\MdtoXmlGenerator;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for MdtoXmlGenerator.
 */
class MdtoXmlGeneratorTest extends TestCase
{
    private IAppConfig&MockObject $appConfig;
    private LoggerInterface&MockObject $logger;
    private MdtoXmlGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->appConfig->method('getValueString')
            ->willReturnMap([
                ['openregister', 'organisation_identifier', '', 'ORG-001'],
                ['openregister', 'organisation_name', '', 'Test Organisation'],
                ['openregister', 'organisation_name', 'OpenRegister', 'Test Organisation'],
                ['openregister', 'organisation_identifier', 'OpenRegister', 'ORG-001'],
            ]);

        $this->generator = new MdtoXmlGenerator($this->appConfig, $this->logger);
    }

    /**
     * Test generating MDTO XML for a complete object.
     */
    public function testGenerateCompleteObject(): void
    {
        $object = $this->createObjectEntity(
            uuid: 'test-uuid-123',
            retention: [
                'archiefnominatie' => 'bewaren',
                'bewaartermijn' => 'P20Y',
                'classificatie' => 'A1',
            ],
            objectData: ['title' => 'Test Document']
        );

        $xml = $this->generator->generate($object);

        $this->assertStringContainsString('mdto:informatieobject', $xml);
        $this->assertStringContainsString('https://www.nationaalarchief.nl/mdto', $xml);
        $this->assertStringContainsString('test-uuid-123', $xml);
        $this->assertStringContainsString('Test Document', $xml);
        $this->assertStringContainsString('bewaren', $xml);
        $this->assertStringContainsString('P20Y', $xml);
        $this->assertStringContainsString('A1', $xml);
    }

    /**
     * Test generating MDTO XML with file references.
     */
    public function testGenerateWithFiles(): void
    {
        $object = $this->createObjectEntity(
            uuid: 'test-uuid-456',
            retention: [
                'archiefnominatie' => 'bewaren',
                'bewaartermijn' => 'P10Y',
                'classificatie' => 'B1',
            ]
        );

        $files = [
            [
                'name' => 'document.pdf',
                'size' => 1024,
                'format' => 'application/pdf',
                'checksum' => 'abc123def456',
            ],
        ];

        $xml = $this->generator->generate($object, $files);

        $this->assertStringContainsString('mdto:bestand', $xml);
        $this->assertStringContainsString('document.pdf', $xml);
        $this->assertStringContainsString('1024', $xml);
        $this->assertStringContainsString('application/pdf', $xml);
        $this->assertStringContainsString('SHA-256', $xml);
        $this->assertStringContainsString('abc123def456', $xml);
    }

    /**
     * Test that missing optional fields are omitted gracefully.
     */
    public function testGenerateWithMissingOptionalFields(): void
    {
        $object = $this->createObjectEntity(
            uuid: 'test-uuid-789',
            retention: [
                'archiefnominatie' => 'bewaren',
                'bewaartermijn' => 'P5Y',
            ]
        );

        $xml = $this->generator->generate($object);

        // classificatie should default to 'onbekend' when missing.
        $this->assertStringContainsString('onbekend', $xml);
        // toelichting should not appear.
        $this->assertStringNotContainsString('toelichting', $xml);
    }

    /**
     * Test that missing required fields throw an exception.
     */
    public function testGenerateMissingRequiredFieldsThrows(): void
    {
        $object = $this->createObjectEntity(
            uuid: 'test-uuid-000',
            retention: []
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/retention\.archiefnominatie/');

        $this->generator->generate($object);
    }

    /**
     * Test that missing organisation_identifier throws.
     */
    public function testGenerateMissingOrganisationThrows(): void
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')
            ->willReturn('');

        $generator = new MdtoXmlGenerator($appConfig, $this->logger);

        $object = $this->createObjectEntity(
            uuid: 'test-uuid',
            retention: [
                'archiefnominatie' => 'bewaren',
                'bewaartermijn' => 'P5Y',
            ]
        );

        $this->expectException(InvalidArgumentException::class);

        $generator->generate($object);
    }

    /**
     * Create a mock ObjectEntity with the given data.
     *
     * @param string              $uuid       The UUID.
     * @param array<string,mixed> $retention  The retention data.
     * @param array<string,mixed> $objectData The object data.
     *
     * @return ObjectEntity&MockObject The mock object entity.
     */
    private function createObjectEntity(
        string $uuid = 'test-uuid',
        array $retention = [],
        array $objectData = []
    ): ObjectEntity&MockObject {
        $object = $this->getMockBuilder(ObjectEntity::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['jsonSerialize', 'getObject'])
            ->addMethods(['getUuid', 'getRetention'])
            ->getMock();
        $object->method('getUuid')->willReturn($uuid);
        $object->method('getRetention')->willReturn($retention);
        $object->method('getObject')->willReturn($objectData);

        return $object;
    }
}
