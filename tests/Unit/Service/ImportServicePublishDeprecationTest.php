<?php

declare(strict_types=1);

/**
 * ImportService Publish Deprecation Tests
 *
 * Tests that the deprecated $publish parameter in ImportService methods
 * logs deprecation warnings and does not inject published metadata.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <dev@conductio.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\ImportService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests that the addPublishedDateToObjects method has been removed
 * and the publish parameter no longer injects @self.published metadata.
 */
class ImportServicePublishDeprecationTest extends TestCase
{
    /**
     * Test that addPublishedDateToObjects method no longer exists.
     */
    public function testAddPublishedDateToObjectsMethodRemoved(): void
    {
        $reflection = new ReflectionClass(ImportService::class);
        $this->assertFalse(
            $reflection->hasMethod('addPublishedDateToObjects'),
            'The addPublishedDateToObjects method should be removed from ImportService'
        );
    }

    /**
     * Test that importFromExcel still accepts the publish parameter (backward compatibility).
     */
    public function testImportFromExcelStillAcceptsPublishParameter(): void
    {
        $reflection = new ReflectionClass(ImportService::class);
        $method = $reflection->getMethod('importFromExcel');
        $params = $method->getParameters();

        $publishParam = null;
        foreach ($params as $param) {
            if ($param->getName() === 'publish') {
                $publishParam = $param;
                break;
            }
        }

        $this->assertNotNull($publishParam, 'importFromExcel should still accept $publish for backward compat');
        $this->assertTrue($publishParam->isDefaultValueAvailable(), '$publish should have a default value');
        $this->assertFalse($publishParam->getDefaultValue(), '$publish default should be false');
    }

    /**
     * Test that importFromCsv still accepts the publish parameter (backward compatibility).
     */
    public function testImportFromCsvStillAcceptsPublishParameter(): void
    {
        $reflection = new ReflectionClass(ImportService::class);
        $method = $reflection->getMethod('importFromCsv');
        $params = $method->getParameters();

        $publishParam = null;
        foreach ($params as $param) {
            if ($param->getName() === 'publish') {
                $publishParam = $param;
                break;
            }
        }

        $this->assertNotNull($publishParam, 'importFromCsv should still accept $publish for backward compat');
        $this->assertTrue($publishParam->isDefaultValueAvailable(), '$publish should have a default value');
        $this->assertFalse($publishParam->getDefaultValue(), '$publish default should be false');
    }

    /**
     * Test that no method in ImportService creates @self.published data.
     */
    public function testNoMethodInjectsPublishedMetadata(): void
    {
        $reflection = new ReflectionClass(ImportService::class);
        $filePath = $reflection->getFileName();
        $source = file_get_contents($filePath);

        // The string '@self.published' or "@self']['published']" should not appear
        // as an assignment target (only in deprecation warning messages).
        $this->assertStringNotContainsString(
            "\$object['@self']['published']",
            $source,
            'ImportService should not inject @self.published into objects'
        );
    }
}
