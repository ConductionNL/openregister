<?php
/**
 * OasServiceTest
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Tests;

use OCA\OpenRegister\Service\OasService;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IURLGenerator;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Class OasServiceTest
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */
class OasServiceTest extends TestCase
{
    /**
     * @var OasService
     */
    private OasService $oasService;

    /**
     * Set up OasService with mocks
     *
     * @return void
     */
    protected function setUp(): void
    {
        // Create mocks for dependencies
        $registerMapper = $this->createMock(RegisterMapper::class);
        $schemaMapper = $this->createMock(SchemaMapper::class);
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $config = $this->createMock(IConfig::class);
        $logger = $this->createMock(LoggerInterface::class);

        // Instantiate OasService
        $this->oasService = new OasService(
            $registerMapper,
            $schemaMapper,
            $urlGenerator,
            $config,
            $logger
        );
    }

    /**
     * Test that cleanOasProperties prefixes the correct keys and removes null/false values.
     *
     * @return void
     */
    public function testCleanOasPropertiesPrefixesAndRemoves(): void
    {
        $input = [
            'deprecated' => true,
            'cascadeDelete' => false,
            '$ref' => '#/components/schemas/SomeSchema',
            'objectConfiguration' => null,
            'fileConfiguration' => ['foo' => 'bar'],
            'normal' => 'value',
            'nested' => [
                'deprecated' => null,
                'cascadeDelete' => true,
                'foo' => 'bar',
            ],
        ];

        $expected = [
            'x-openregisters-deprecated' => true,
            'x-openregisters-ref' => '#/components/schemas/SomeSchema',
            'x-openregisters-fileConfiguration' => ['foo' => 'bar'],
            'normal' => 'value',
            'nested' => [
                'x-openregisters-cascadeDelete' => true,
                'foo' => 'bar',
            ],
        ];

        // Use reflection to access the private method
        $reflection = new \ReflectionClass($this->oasService);
        $method = $reflection->getMethod('cleanOasProperties');
        $method->setAccessible(true);

        /** @var array<string, mixed> $result */
        $result = $method->invoke($this->oasService, $input);

        $this->assertEquals($expected, $result);
    }

    /**
     * Test that cleanOasProperties removes all null/false values, even deeply nested.
     *
     * @return void
     */
    public function testCleanOasPropertiesRemovesNullFalseDeep(): void
    {
        $input = [
            'foo' => null,
            'bar' => false,
            'baz' => [
                'a' => null,
                'b' => false,
                'c' => 'keep',
            ],
        ];
        $expected = [
            'baz' => [
                'c' => 'keep',
            ],
        ];

        $reflection = new \ReflectionClass($this->oasService);
        $method = $reflection->getMethod('cleanOasProperties');
        $method->setAccessible(true);
        $result = $method->invoke($this->oasService, $input);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test that cleanOasProperties leaves unrelated keys untouched.
     *
     * @return void
     */
    public function testCleanOasPropertiesLeavesUnrelated(): void
    {
        $input = [
            'foo' => 'bar',
            'baz' => 123,
        ];
        $expected = [
            'foo' => 'bar',
            'baz' => 123,
        ];
        $reflection = new \ReflectionClass($this->oasService);
        $method = $reflection->getMethod('cleanOasProperties');
        $method->setAccessible(true);
        $result = $method->invoke($this->oasService, $input);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test getSearchQueryParametersForSchema returns only string and integer properties as query parameters.
     *
     * @return void
     */
    public function testGetSearchQueryParametersForSchema(): void
    {
        // Create a mock schema object with getProperties method
        $schema = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getProperties'])
            ->getMock();
        $schema->method('getProperties')->willReturn([
            'name' => ['type' => 'string'],
            'age' => ['type' => 'integer'],
            'active' => ['type' => 'boolean'],
            'meta' => ['type' => 'object'],
            'tags' => ['type' => 'array'],
        ]);

        $reflection = new \ReflectionClass($this->oasService);
        $method = $reflection->getMethod('getSearchQueryParametersForSchema');
        $method->setAccessible(true);
        $result = $method->invoke($this->oasService, $schema);

        $expected = [
            [
                'name' => 'name',
                'in' => 'query',
                'required' => false,
                'description' => 'Exact match filter for name (string)',
                'schema' => ['type' => 'string'],
            ],
            [
                'name' => 'age',
                'in' => 'query',
                'required' => false,
                'description' => 'Exact match filter for age (integer)',
                'schema' => ['type' => 'integer'],
            ],
        ];

        $this->assertEquals($expected, $result);
    }
} 