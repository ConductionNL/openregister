<?php

declare(strict_types=1);

/**
 * Index SchemaMapper Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Index
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Index;

use OCA\OpenRegister\Service\Index\SchemaMapper;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for Index\SchemaMapper
 *
 * Tests schema-to-backend field type mapping.
 */
class SchemaMapperTest extends TestCase
{
    /** @var SchemaMapper */
    private SchemaMapper $schemaMapper;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var LoggerInterface&MockObject $logger */
        $logger = $this->createMock(LoggerInterface::class);
        $this->schemaMapper = new SchemaMapper($logger);
    }

    public function testMapToBackendSchemaReturnsEmptyArray(): void
    {
        $result = $this->schemaMapper->mapToBackendSchema(['type' => 'string']);

        $this->assertSame([], $result);
    }

    public function testMapFieldTypeReturnsInputAsIs(): void
    {
        $this->assertSame('string', $this->schemaMapper->mapFieldType('string'));
        $this->assertSame('integer', $this->schemaMapper->mapFieldType('integer'));
        $this->assertSame('custom_type', $this->schemaMapper->mapFieldType('custom_type'));
    }
}
