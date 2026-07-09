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
     * BagIt (RFC 8493) output: content under data/, complete manifest, tag
     * files. archival-transfer-hardening OR-AD-1.
     */
    public function testBuildBagitLayoutAndManifest(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'bag') . '.zip';
        $this->tempManager->method('getTemporaryFile')->willReturn($tempFile);

        // A real payload file so the manifest can checksum it.
        $payload = tempnam(sys_get_temp_dir(), 'payload');
        file_put_contents($payload, 'hello archive');

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
                'files' => [
                    [
                        'name' => 'doc.txt',
                        'size' => filesize($payload),
                        'format' => 'text/plain',
                        'checksum' => hash_file('sha256', $payload),
                        'path' => $payload,
                        'isRendition' => false,
                    ],
                ],
            ],
        ];

        $result = $this->builder->build('transfer-bag', $objectsWithFiles, 0, 'bagit');

        $zip = new \ZipArchive();
        $zip->open($result[0]);
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = $zip->getNameIndex($i);
        }

        // Tag files at bag root.
        $this->assertContains('bagit.txt', $entries);
        $this->assertContains('bag-info.txt', $entries);
        $this->assertContains('manifest-sha256.txt', $entries);
        $this->assertContains('tagmanifest-sha256.txt', $entries);
        // Payload relocated under data/.
        $this->assertContains('data/objects/obj-uuid-1/mdto.xml', $entries);
        $this->assertContains('data/objects/obj-uuid-1/content/original/doc.txt', $entries);

        // bagit.txt declares version 1.0.
        $this->assertStringContainsString('BagIt-Version: 1.0', $zip->getFromName('bagit.txt'));
        // bag-info carries the transfer uuid as External-Identifier.
        $this->assertStringContainsString('External-Identifier: transfer-bag', $zip->getFromName('bag-info.txt'));

        // The manifest lists every payload file with a checksum, and the
        // payload file's line matches its real digest.
        $manifest = $zip->getFromName('manifest-sha256.txt');
        $this->assertStringContainsString('data/objects/obj-uuid-1/content/original/doc.txt', $manifest);
        $this->assertStringContainsString(hash('sha256', 'hello archive'), $manifest);

        $zip->close();
        unlink($result[0]);
        unlink($payload);
    }

    /**
     * BagIt refuses to ship an incomplete manifest: an unchecksummable
     * payload file fails the build.
     */
    public function testBuildBagitFailsOnUnchecksummableFile(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'bag') . '.zip';
        $this->tempManager->method('getTemporaryFile')->willReturn($tempFile);

        $object = $this->getMockBuilder(ObjectEntity::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['jsonSerialize'])
            ->addMethods(['getUuid'])
            ->getMock();
        $object->method('getUuid')->willReturn('obj-uuid-1');
        $object->method('jsonSerialize')->willReturn(['uuid' => 'obj-uuid-1']);

        // A file that passes the entry-collection file_exists() check but is
        // deleted before bag write, so hash_file() fails.
        $vanishing = tempnam(sys_get_temp_dir(), 'vanish');
        file_put_contents($vanishing, 'x');

        $builder = new SipPackageBuilder(
            $this->mdtoGenerator,
            $this->appConfig,
            $this->tempManager,
            $this->logger,
        );

        // Craft an objectsWithFiles whose file path exists at collection time.
        $objectsWithFiles = [
            [
                'object' => $object,
                'files' => [
                    [
                        'name' => 'gone.bin',
                        'size' => 1,
                        'format' => 'application/octet-stream',
                        'checksum' => 'deadbeef',
                        'path' => $vanishing,
                        'isRendition' => false,
                    ],
                ],
            ],
        ];

        // Make hash_file fail by pointing the entry at an unreadable path:
        // remove the file after the builder collects it is not possible from
        // here, so instead assert the unknown-format guard on build() and the
        // manifest-completeness contract by using a directory as the path
        // (hash_file on a directory returns false).
        $dirPath = sys_get_temp_dir() . '/sip-bagit-dir-' . uniqid();
        mkdir($dirPath);
        $objectsWithFiles[0]['files'][0]['path'] = $dirPath;

        $this->expectException(InvalidArgumentException::class);
        try {
            $builder->build('transfer-bag', $objectsWithFiles, 0, 'bagit');
        } finally {
            rmdir($dirPath);
            unlink($vanishing);
        }
    }

    /**
     * An unknown output format is rejected.
     */
    public function testBuildRejectsUnknownFormat(): void
    {
        $object = $this->getMockBuilder(ObjectEntity::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['jsonSerialize'])
            ->addMethods(['getUuid'])
            ->getMock();
        $object->method('getUuid')->willReturn('obj-uuid-1');
        $object->method('jsonSerialize')->willReturn(['uuid' => 'obj-uuid-1']);

        $this->expectException(InvalidArgumentException::class);
        $this->builder->build('transfer-1', [['object' => $object, 'files' => []]], 0, 'tar');
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
