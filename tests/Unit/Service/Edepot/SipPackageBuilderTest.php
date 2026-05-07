<?php

declare(strict_types=1);

/**
 * SipPackageBuilder Unit Tests
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
use OCA\OpenRegister\Service\Edepot\SipPackageBuilder;
use OCP\IAppConfig;
use OCP\ITempManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for SipPackageBuilder.
 */
class SipPackageBuilderTest extends TestCase
{
    private MdtoXmlGenerator&MockObject $mdtoGenerator;
    private IAppConfig&MockObject $appConfig;
    private ITempManager&MockObject $tempManager;
    private LoggerInterface&MockObject $logger;
    private SipPackageBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mdtoGenerator = $this->createMock(MdtoXmlGenerator::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->tempManager = $this->createMock(ITempManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->mdtoGenerator->method('generate')
            ->willReturn('<?xml version="1.0"?><mdto:informatieobject/>');

        $this->appConfig->method('getValueString')
            ->willReturn((string) SipPackageBuilder::DEFAULT_MAX_PACKAGE_SIZE);

        $this->builder = new SipPackageBuilder(
            $this->mdtoGenerator,
            $this->appConfig,
            $this->tempManager,
            $this->logger,
        );
    }

    /**
     * Test building with empty objects list throws.
     */
    public function testBuildEmptyObjectsThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder->build('transfer-1', []);
    }

    /**
     * Test build returns array of file paths.
     */
    public function testBuildReturnsSipFilePaths(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'sip') . '.zip';
        $this->tempManager->method('getTemporaryFile')
            ->willReturn($tempFile);

        $object = $this->getMockBuilder(ObjectEntity::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['jsonSerialize'])
            ->addMethods(['getUuid'])
            ->getMock();
        $object->method('getUuid')->willReturn('obj-uuid-1');
        $object->method('jsonSerialize')->willReturn(['uuid' => 'obj-uuid-1']);

        $objectsWithFiles = [
            [
                'object' => $object,
                'files' => [],
            ],
        ];

        $result = $this->builder->build('transfer-1', $objectsWithFiles);

        $this->assertCount(1, $result);
        $this->assertFileExists($result[0]);

        // Clean up.
        unlink($result[0]);
    }

    /**
     * Test that SIP package contains expected entries.
     */
    public function testBuildContainsExpectedEntries(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'sip') . '.zip';
        $this->tempManager->method('getTemporaryFile')
            ->willReturn($tempFile);

        $object = $this->getMockBuilder(ObjectEntity::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['jsonSerialize'])
            ->addMethods(['getUuid'])
            ->getMock();
        $object->method('getUuid')->willReturn('obj-uuid-1');
        $object->method('jsonSerialize')->willReturn(['uuid' => 'obj-uuid-1']);

        $objectsWithFiles = [
            [
                'object' => $object,
                'files' => [],
            ],
        ];

        $result = $this->builder->build('transfer-1', $objectsWithFiles);

        $zip = new \ZipArchive();
        $zip->open($result[0]);

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = $zip->getNameIndex($i);
        }

        $this->assertContains('objects/obj-uuid-1/mdto.xml', $entries);
        $this->assertContains('objects/obj-uuid-1/metadata.json', $entries);
        $this->assertContains('mets.xml', $entries);
        $this->assertContains('premis.xml', $entries);
        $this->assertContains('sip-manifest.json', $entries);

        $zip->close();
        unlink($result[0]);
    }

    /**
     * Test that package splitting produces multiple ZIPs.
     */
    public function testBuildSplitsLargePackages(): void
    {
        $callCount = 0;
        $this->tempManager->method('getTemporaryFile')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                return tempnam(sys_get_temp_dir(), 'sip') . "-part{$callCount}.zip";
            });

        $objects = [];
        for ($i = 0; $i < 3; $i++) {
            $obj = $this->getMockBuilder(ObjectEntity::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['jsonSerialize'])
                ->addMethods(['getUuid'])
                ->getMock();
            $obj->method('getUuid')->willReturn("obj-uuid-{$i}");
            $obj->method('jsonSerialize')->willReturn(['uuid' => "obj-uuid-{$i}"]);

            $objects[] = [
                'object' => $obj,
                'files' => [
                    [
                        'name' => "large-file-{$i}.bin",
                        'size' => 1073741824,
                        'format' => 'application/octet-stream',
                        'checksum' => 'abc123',
                        'path' => '/nonexistent/file.bin',
                        'isRendition' => false,
                    ],
                ],
            ];
        }

        // Set max size to 1.5 GB to force splitting.
        $result = $this->builder->build('transfer-1', $objects, 1610612736);

        $this->assertGreaterThanOrEqual(2, count($result));

        // Clean up.
        foreach ($result as $file) {
            if (file_exists($file) === true) {
                unlink($file);
            }
        }
    }
}
