<?php

declare(strict_types=1);

/**
 * FileSettingsHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Settings
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Settings;

use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for FileSettingsHandler
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Comprehensive coverage requires many test methods
 */
class FileSettingsHandlerTest extends TestCase
{
    /** @var FileSettingsHandler */
    private FileSettingsHandler $handler;

    /** @var IAppConfig&MockObject */
    private IAppConfig $appConfig;

    /**
     * Set up test fixtures
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->handler = new FileSettingsHandler($this->appConfig, 'openregister');
    }

    /**
     * Test getFileSettingsOnly returns default config when empty.
     *
     * @return void
     */
    public function testGetFileSettingsReturnsDefaultWhenEmpty(): void
    {
        $this->appConfig->method('getValueString')
            ->with('openregister', 'fileManagement', '')
            ->willReturn('');

        $result = $this->handler->getFileSettingsOnly();

        $this->assertFalse($result['vectorizationEnabled']);
        $this->assertNull($result['provider']);
        $this->assertSame('RECURSIVE_CHARACTER', $result['chunkingStrategy']);
        $this->assertSame(1000, $result['chunkSize']);
        $this->assertSame(200, $result['chunkOverlap']);
        $this->assertContains('txt', $result['enabledFileTypes']);
        $this->assertContains('pdf', $result['enabledFileTypes']);
        $this->assertContains('docx', $result['enabledFileTypes']);
        $this->assertContains('xlsx', $result['enabledFileTypes']);
        $this->assertCount(11, $result['enabledFileTypes']);
        $this->assertFalse($result['ocrEnabled']);
        $this->assertSame(100, $result['maxFileSizeMB']);
        $this->assertSame('objects', $result['extractionScope']);
        $this->assertSame('llphant', $result['textExtractor']);
        $this->assertSame('background', $result['extractionMode']);
        $this->assertSame(100, $result['maxFileSize']);
        $this->assertSame(10, $result['batchSize']);
        $this->assertSame('', $result['dolphinApiEndpoint']);
        $this->assertSame('', $result['dolphinApiKey']);
        $this->assertSame('', $result['presidioApiEndpoint']);
        $this->assertSame('', $result['openAnonymiserApiEndpoint']);
        $this->assertFalse($result['entityRecognitionEnabled']);
        $this->assertSame('hybrid', $result['entityRecognitionMethod']);
    }

    /**
     * Test getFileSettingsOnly returns decoded config.
     *
     * @return void
     */
    public function testGetFileSettingsReturnsDecodedConfig(): void
    {
        $config = [
            'vectorizationEnabled' => true,
            'provider'             => 'openai',
            'chunkingStrategy'     => 'FIXED_SIZE',
            'chunkSize'            => 500,
            'chunkOverlap'         => 100,
            'enabledFileTypes'     => ['txt', 'pdf'],
            'ocrEnabled'           => true,
            'maxFileSizeMB'        => 50,
        ];

        $this->appConfig->method('getValueString')
            ->willReturn(json_encode($config));

        $result = $this->handler->getFileSettingsOnly();

        $this->assertTrue($result['vectorizationEnabled']);
        $this->assertSame('openai', $result['provider']);
        $this->assertSame('FIXED_SIZE', $result['chunkingStrategy']);
        $this->assertSame(500, $result['chunkSize']);
        $this->assertSame(100, $result['chunkOverlap']);
        $this->assertCount(2, $result['enabledFileTypes']);
        $this->assertTrue($result['ocrEnabled']);
        $this->assertSame(50, $result['maxFileSizeMB']);
    }

    /**
     * Test getFileSettingsOnly throws RuntimeException on error.
     *
     * @return void
     */
    public function testGetFileSettingsThrowsRuntimeExceptionOnError(): void
    {
        $this->appConfig->method('getValueString')
            ->willThrowException(new \Exception('Database error'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to retrieve File Management settings: Database error');

        $this->handler->getFileSettingsOnly();
    }

    /**
     * Test updateFileSettingsOnly with full data.
     *
     * @return void
     */
    public function testUpdateFileSettingsWithFullData(): void
    {
        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with('openregister', 'fileManagement', $this->isType('string'));

        $data = [
            'vectorizationEnabled'      => true,
            'provider'                  => 'openai',
            'chunkingStrategy'          => 'FIXED_SIZE',
            'chunkSize'                 => 500,
            'chunkOverlap'              => 50,
            'enabledFileTypes'          => ['txt', 'pdf'],
            'ocrEnabled'                => true,
            'maxFileSizeMB'             => 50,
            'extractionScope'           => 'all',
            'textExtractor'             => 'dolphin',
            'extractionMode'            => 'immediate',
            'maxFileSize'               => 200,
            'batchSize'                 => 20,
            'dolphinApiEndpoint'        => 'http://dolphin:8080',
            'dolphinApiKey'             => 'dk-123',
            'presidioApiEndpoint'       => 'http://presidio:5001',
            'openAnonymiserApiEndpoint' => 'http://anon:5002',
            'entityRecognitionEnabled'  => true,
            'entityRecognitionMethod'   => 'presidio',
        ];

        $result = $this->handler->updateFileSettingsOnly($data);

        $this->assertTrue($result['vectorizationEnabled']);
        $this->assertSame('openai', $result['provider']);
        $this->assertSame('FIXED_SIZE', $result['chunkingStrategy']);
        $this->assertSame(500, $result['chunkSize']);
        $this->assertSame(50, $result['chunkOverlap']);
        $this->assertCount(2, $result['enabledFileTypes']);
        $this->assertTrue($result['ocrEnabled']);
        $this->assertSame(50, $result['maxFileSizeMB']);
        $this->assertSame('all', $result['extractionScope']);
        $this->assertSame('dolphin', $result['textExtractor']);
        $this->assertSame('immediate', $result['extractionMode']);
        $this->assertSame(200, $result['maxFileSize']);
        $this->assertSame(20, $result['batchSize']);
        $this->assertSame('http://dolphin:8080', $result['dolphinApiEndpoint']);
        $this->assertSame('dk-123', $result['dolphinApiKey']);
        $this->assertSame('http://presidio:5001', $result['presidioApiEndpoint']);
        $this->assertSame('http://anon:5002', $result['openAnonymiserApiEndpoint']);
        $this->assertTrue($result['entityRecognitionEnabled']);
        $this->assertSame('presidio', $result['entityRecognitionMethod']);
    }

    /**
     * Test updateFileSettingsOnly with empty data uses defaults.
     *
     * @return void
     */
    public function testUpdateFileSettingsWithEmptyDataUsesDefaults(): void
    {
        $this->appConfig->expects($this->once())
            ->method('setValueString');

        $result = $this->handler->updateFileSettingsOnly([]);

        $this->assertFalse($result['vectorizationEnabled']);
        $this->assertNull($result['provider']);
        $this->assertSame('RECURSIVE_CHARACTER', $result['chunkingStrategy']);
        $this->assertSame(1000, $result['chunkSize']);
        $this->assertSame(200, $result['chunkOverlap']);
        $this->assertCount(11, $result['enabledFileTypes']);
        $this->assertFalse($result['ocrEnabled']);
        $this->assertSame(100, $result['maxFileSizeMB']);
        $this->assertSame('objects', $result['extractionScope']);
        $this->assertSame('llphant', $result['textExtractor']);
        $this->assertSame('background', $result['extractionMode']);
        $this->assertSame(100, $result['maxFileSize']);
        $this->assertSame(10, $result['batchSize']);
        $this->assertSame('', $result['dolphinApiEndpoint']);
        $this->assertSame('', $result['dolphinApiKey']);
        $this->assertSame('', $result['presidioApiEndpoint']);
        $this->assertSame('', $result['openAnonymiserApiEndpoint']);
        $this->assertFalse($result['entityRecognitionEnabled']);
        $this->assertSame('hybrid', $result['entityRecognitionMethod']);
    }

    /**
     * Test updateFileSettingsOnly with partial data.
     *
     * @return void
     */
    public function testUpdateFileSettingsWithPartialData(): void
    {
        $this->appConfig->expects($this->once())
            ->method('setValueString');

        $result = $this->handler->updateFileSettingsOnly([
            'vectorizationEnabled' => true,
            'chunkSize'            => 2000,
        ]);

        $this->assertTrue($result['vectorizationEnabled']);
        $this->assertSame(2000, $result['chunkSize']);
        // Rest should be defaults.
        $this->assertNull($result['provider']);
        $this->assertSame('RECURSIVE_CHARACTER', $result['chunkingStrategy']);
        $this->assertSame(200, $result['chunkOverlap']);
    }

    /**
     * Test updateFileSettingsOnly throws RuntimeException on error.
     *
     * @return void
     */
    public function testUpdateFileSettingsThrowsRuntimeExceptionOnError(): void
    {
        $this->appConfig->method('setValueString')
            ->willThrowException(new \Exception('Write error'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to update File Management settings: Write error');

        $this->handler->updateFileSettingsOnly(['vectorizationEnabled' => true]);
    }

    /**
     * Test updateFileSettingsOnly stores correct JSON.
     *
     * @return void
     */
    public function testUpdateFileSettingsStoresCorrectJson(): void
    {
        $storedJson = null;
        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->willReturnCallback(function ($app, $key, $value) use (&$storedJson) {
                $storedJson = $value;
                return true;
            });

        $this->handler->updateFileSettingsOnly(['vectorizationEnabled' => true, 'chunkSize' => 777]);

        $decoded = json_decode($storedJson, true);
        $this->assertTrue($decoded['vectorizationEnabled']);
        $this->assertSame(777, $decoded['chunkSize']);
        $this->assertArrayHasKey('enabledFileTypes', $decoded);
    }
}
