<?php

/**
 * SchemaService Unit Tests
 *
 * Comprehensive tests for SchemaService covering schema exploration,
 * property analysis, format detection, and suggestion generation.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Service\SchemaService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Unit tests for SchemaService.
 *
 * Tests cover:
 * - analyzePropertyValue (string, int, float, bool, null, array, object)
 * - detectStringFormat (date, datetime, email, URI, UUID, IPv4, IPv6, etc.)
 * - analyzeStringPattern (uppercase, lowercase, mixed, numeric)
 * - isInternalProperty (@self, _id, id, uuid vs normal)
 * - recommendPropertyType (type distributions)
 * - detectEnumLike (few unique vs many unique values)
 * - mergeNumericRanges (null existing, overlapping, expanding)
 * - consolidateFormatDetection (same, different, null existing)
 * - exploreSchemaProperties (integration with mocked mappers)
 * - generateSuggestions (new properties, type mismatches)
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)           Comprehensive test coverage requires many test methods
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Full coverage of SchemaService requires extensive tests
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Tests need access to multiple domain classes
 */
class SchemaServiceTest extends TestCase
{

    /**
     * The SchemaService instance under test
     *
     * @var SchemaService
     */
    private SchemaService $service;

    /**
     * Mock for SchemaMapper
     *
     * @var MockObject|SchemaMapper
     */
    private $schemaMapper;

    /**
     * Mock for MagicMapper
     *
     * @var MockObject|MagicMapper
     */
    private $objectMapper;

    /**
     * Mock for LoggerInterface
     *
     * @var MockObject|LoggerInterface
     */
    private $logger;


    /**
     * Set up test environment before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->schemaMapper       = $this->createMock(SchemaMapper::class);
        $this->objectMapper = $this->createMock(MagicMapper::class);
        $this->logger             = $this->createMock(LoggerInterface::class);

        $this->service = new SchemaService(
            schemaMapper: $this->schemaMapper,
            objectMapper: $this->objectMapper,
            logger: $this->logger
        );
    }


    /**
     * Invoke a private method on the service using reflection.
     *
     * @param string $methodName Name of the private method
     * @param array  $args       Arguments to pass to the method
     *
     * @return mixed The method's return value
     */
    private function invokePrivate(string $methodName, array $args): mixed
    {
        $method = new ReflectionMethod($this->service, $methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($this->service, $args);
    }


    // =========================================================================
    // analyzePropertyValue tests
    // =========================================================================


    /**
     * Test analyzePropertyValue with a string value.
     *
     * @return void
     */
    public function testAnalyzePropertyValueWithString(): void
    {
        $result = $this->invokePrivate('analyzePropertyValue', ['hello world']);

        $this->assertContains('string', $result['types']);
        $this->assertEquals(11, $result['max_length']);
        $this->assertEquals(11, $result['min_length']);
        $this->assertNull($result['numeric_range']);
    }


    /**
     * Test analyzePropertyValue with an integer value.
     *
     * @return void
     */
    public function testAnalyzePropertyValueWithInteger(): void
    {
        $result = $this->invokePrivate('analyzePropertyValue', [42]);

        $this->assertContains('integer', $result['types']);
        $this->assertNotNull($result['numeric_range']);
        $this->assertEquals(42, $result['numeric_range']['min']);
        $this->assertEquals(42, $result['numeric_range']['max']);
        $this->assertEquals('integer', $result['numeric_range']['type']);
    }


    /**
     * Test analyzePropertyValue with a float/double value.
     *
     * @return void
     */
    public function testAnalyzePropertyValueWithFloat(): void
    {
        $result = $this->invokePrivate('analyzePropertyValue', [3.14]);

        $this->assertContains('double', $result['types']);
        $this->assertNotNull($result['numeric_range']);
        $this->assertEquals(3.14, $result['numeric_range']['min']);
        $this->assertEquals(3.14, $result['numeric_range']['max']);
        $this->assertEquals('number', $result['numeric_range']['type']);
    }


    /**
     * Test analyzePropertyValue with a boolean value.
     *
     * @return void
     */
    public function testAnalyzePropertyValueWithBoolean(): void
    {
        $result = $this->invokePrivate('analyzePropertyValue', [true]);

        $this->assertContains('boolean', $result['types']);
        $this->assertNull($result['numeric_range']);
        $this->assertNull($result['object_structure']);
        $this->assertNull($result['array_structure']);
    }


    /**
     * Test analyzePropertyValue with a list array.
     *
     * @return void
     */
    public function testAnalyzePropertyValueWithListArray(): void
    {
        $result = $this->invokePrivate('analyzePropertyValue', [['a', 'b', 'c']]);

        $this->assertContains('array', $result['types']);
        $this->assertNotNull($result['array_structure']);
        $this->assertEquals('list', $result['array_structure']['type']);
        $this->assertEquals(3, $result['array_structure']['length']);
    }


    /**
     * Test analyzePropertyValue with an associative array (object-like).
     *
     * @return void
     */
    public function testAnalyzePropertyValueWithAssociativeArray(): void
    {
        $result = $this->invokePrivate('analyzePropertyValue', [['name' => 'John', 'age' => 30]]);

        $this->assertContains('array', $result['types']);
        $this->assertNotNull($result['object_structure']);
        $this->assertEquals('object', $result['object_structure']['type']);
        $this->assertContains('name', $result['object_structure']['keys']);
        $this->assertContains('age', $result['object_structure']['keys']);
    }


    /**
     * Test analyzePropertyValue with an empty array.
     *
     * @return void
     */
    public function testAnalyzePropertyValueWithEmptyArray(): void
    {
        $result = $this->invokePrivate('analyzePropertyValue', [[]]);

        $this->assertContains('array', $result['types']);
        $this->assertNull($result['array_structure']);
        $this->assertNull($result['object_structure']);
    }


    /**
     * Test analyzePropertyValue with an object (stdClass).
     *
     * @return void
     */
    public function testAnalyzePropertyValueWithObject(): void
    {
        $obj       = new \stdClass();
        $obj->name = 'Test';
        $obj->val  = 123;

        $result = $this->invokePrivate('analyzePropertyValue', [$obj]);

        $this->assertContains('object', $result['types']);
        $this->assertNotNull($result['object_structure']);
        $this->assertEquals('object', $result['object_structure']['type']);
        $this->assertContains('name', $result['object_structure']['keys']);
        $this->assertContains('val', $result['object_structure']['keys']);
    }


    // =========================================================================
    // detectStringFormat tests
    // =========================================================================


    /**
     * Test detectStringFormat detects date format (Y-m-d).
     *
     * @return void
     */
    public function testDetectStringFormatDate(): void
    {
        $result = $this->invokePrivate('detectStringFormat', ['2024-01-15']);
        $this->assertEquals('date', $result);
    }


    /**
     * Test detectStringFormat detects datetime format (ISO 8601 with timezone offset).
     *
     * @return void
     */
    public function testDetectStringFormatDateTimeRfc3339(): void
    {
        $result = $this->invokePrivate('detectStringFormat', ['2024-01-15T10:30:00+01:00']);
        $this->assertEquals('date-time', $result);
    }


    /**
     * Test detectStringFormat detects email.
     *
     * @return void
     */
    public function testDetectStringFormatEmail(): void
    {
        $result = $this->invokePrivate('detectStringFormat', ['user@example.com']);
        $this->assertEquals('email', $result);
    }


    /**
     * Test detectStringFormat detects URL.
     *
     * @return void
     */
    public function testDetectStringFormatUrl(): void
    {
        $result = $this->invokePrivate('detectStringFormat', ['https://example.com/path']);
        $this->assertEquals('url', $result);
    }


    /**
     * Test detectStringFormat detects UUID.
     *
     * @return void
     */
    public function testDetectStringFormatUuid(): void
    {
        $result = $this->invokePrivate('detectStringFormat', ['550e8400-e29b-41d4-a716-446655440000']);
        $this->assertEquals('uuid', $result);
    }


    /**
     * Test detectStringFormat detects IPv4.
     *
     * @return void
     */
    public function testDetectStringFormatIpv4(): void
    {
        $result = $this->invokePrivate('detectStringFormat', ['192.168.1.1']);
        $this->assertEquals('ipv4', $result);
    }


    /**
     * Test detectStringFormat detects IPv6.
     *
     * @return void
     */
    public function testDetectStringFormatIpv6(): void
    {
        $result = $this->invokePrivate('detectStringFormat', ['2001:0db8:85a3:0000:0000:8a2e:0370:7334']);
        $this->assertEquals('ipv6', $result);
    }


    /**
     * Test detectStringFormat detects time format.
     *
     * @return void
     */
    public function testDetectStringFormatTime(): void
    {
        $result = $this->invokePrivate('detectStringFormat', ['14:30:00']);
        $this->assertEquals('time', $result);
    }


    /**
     * Test detectStringFormat detects hex color.
     *
     * @return void
     */
    public function testDetectStringFormatColor(): void
    {
        $result = $this->invokePrivate('detectStringFormat', ['#ff5733']);
        $this->assertEquals('color', $result);
    }


    /**
     * Test detectStringFormat detects duration (ISO 8601).
     *
     * @return void
     */
    public function testDetectStringFormatDuration(): void
    {
        $result = $this->invokePrivate('detectStringFormat', ['PT1H30M']);
        $this->assertEquals('duration', $result);
    }


    /**
     * Test detectStringFormat returns null for plain text.
     *
     * @return void
     */
    public function testDetectStringFormatReturnsNullForPlainText(): void
    {
        $result = $this->invokePrivate('detectStringFormat', ['just a normal string']);
        $this->assertNull($result);
    }


    /**
     * Test detectStringFormat detects hostname.
     *
     * @return void
     */
    public function testDetectStringFormatHostname(): void
    {
        $result = $this->invokePrivate('detectStringFormat', ['example.com']);
        $this->assertEquals('hostname', $result);
    }


    // =========================================================================
    // analyzeStringPattern tests
    // =========================================================================


    /**
     * Test analyzeStringPattern detects integer string.
     *
     * @return void
     */
    public function testAnalyzeStringPatternIntegerString(): void
    {
        $result = $this->invokePrivate('analyzeStringPattern', ['12345']);
        $this->assertContains('integer_string', $result);
    }


    /**
     * Test analyzeStringPattern detects float string.
     *
     * @return void
     */
    public function testAnalyzeStringPatternFloatString(): void
    {
        $result = $this->invokePrivate('analyzeStringPattern', ['12.34']);
        $this->assertContains('float_string', $result);
    }


    /**
     * Test analyzeStringPattern detects boolean string.
     *
     * @return void
     */
    public function testAnalyzeStringPatternBooleanString(): void
    {
        $result = $this->invokePrivate('analyzeStringPattern', ['true']);
        $this->assertContains('boolean_string', $result);
    }


    /**
     * Test analyzeStringPattern detects snake_case.
     *
     * @return void
     */
    public function testAnalyzeStringPatternSnakeCase(): void
    {
        $result = $this->invokePrivate('analyzeStringPattern', ['my_property_name']);
        $this->assertContains('snake_case', $result);
    }


    /**
     * Test analyzeStringPattern detects SCREAMING_SNAKE_CASE.
     *
     * @return void
     */
    public function testAnalyzeStringPatternScreamingSnakeCase(): void
    {
        $result = $this->invokePrivate('analyzeStringPattern', ['MY_CONSTANT']);
        $this->assertContains('SCREAMING_SNAKE_CASE', $result);
    }


    /**
     * Test analyzeStringPattern detects camelCase.
     *
     * @return void
     */
    public function testAnalyzeStringPatternCamelCase(): void
    {
        $result = $this->invokePrivate('analyzeStringPattern', ['myPropertyName']);
        $this->assertContains('camelCase', $result);
    }


    /**
     * Test analyzeStringPattern detects PascalCase.
     *
     * @return void
     */
    public function testAnalyzeStringPatternPascalCase(): void
    {
        $result = $this->invokePrivate('analyzeStringPattern', ['MyClassName']);
        $this->assertContains('PascalCase', $result);
    }


    /**
     * Test analyzeStringPattern detects path pattern.
     *
     * @return void
     */
    public function testAnalyzeStringPatternPath(): void
    {
        $result = $this->invokePrivate('analyzeStringPattern', ['/usr/local/bin']);
        $this->assertContains('path', $result);
    }


    /**
     * Test analyzeStringPattern does not detect filename due to broken regex in source.
     *
     * The filename regex in SchemaService has an unescaped '/' inside the character class
     * which terminates the regex delimiter early, so preg_match returns false (error).
     * Since the code checks === 1, 'filename' is never added to patterns.
     *
     * @return void
     */
    public function testAnalyzeStringPatternFilenameNotDetected(): void
    {
        $result = $this->invokePrivate('analyzeStringPattern', ['document.pdf']);
        $this->assertNotContains('filename', $result);
    }


    // =========================================================================
    // isInternalProperty tests
    // =========================================================================


    /**
     * Test isInternalProperty returns true for known internal properties.
     *
     * @return void
     */
    public function testIsInternalPropertyReturnsTrueForInternalNames(): void
    {
        $this->assertTrue($this->invokePrivate('isInternalProperty', ['@self']));
        $this->assertTrue($this->invokePrivate('isInternalProperty', ['_id']));
        $this->assertTrue($this->invokePrivate('isInternalProperty', ['id']));
        $this->assertTrue($this->invokePrivate('isInternalProperty', ['uuid']));
        $this->assertTrue($this->invokePrivate('isInternalProperty', ['created']));
        $this->assertTrue($this->invokePrivate('isInternalProperty', ['updated']));
        $this->assertTrue($this->invokePrivate('isInternalProperty', ['deleted']));
        $this->assertTrue($this->invokePrivate('isInternalProperty', ['$schema']));
        $this->assertTrue($this->invokePrivate('isInternalProperty', ['$id']));
    }


    /**
     * Test isInternalProperty returns false for normal properties.
     *
     * @return void
     */
    public function testIsInternalPropertyReturnsFalseForNormalProperties(): void
    {
        $this->assertFalse($this->invokePrivate('isInternalProperty', ['name']));
        $this->assertFalse($this->invokePrivate('isInternalProperty', ['title']));
        $this->assertFalse($this->invokePrivate('isInternalProperty', ['description']));
        $this->assertFalse($this->invokePrivate('isInternalProperty', ['email']));
        $this->assertFalse($this->invokePrivate('isInternalProperty', ['status']));
    }


    /**
     * Test isInternalProperty is case-insensitive.
     *
     * @return void
     */
    public function testIsInternalPropertyIsCaseInsensitive(): void
    {
        $this->assertTrue($this->invokePrivate('isInternalProperty', ['ID']));
        $this->assertTrue($this->invokePrivate('isInternalProperty', ['UUID']));
        $this->assertTrue($this->invokePrivate('isInternalProperty', ['Created']));
    }


    // =========================================================================
    // recommendPropertyType tests
    // =========================================================================


    /**
     * Test recommendPropertyType with single string type.
     *
     * @return void
     */
    public function testRecommendPropertyTypeSingleString(): void
    {
        $analysis = [
            'types'            => ['string'],
            'detected_format'  => null,
            'string_patterns'  => [],
        ];

        $result = $this->invokePrivate('recommendPropertyType', [$analysis]);
        $this->assertEquals('string', $result);
    }


    /**
     * Test recommendPropertyType with single integer type.
     *
     * @return void
     */
    public function testRecommendPropertyTypeSingleInteger(): void
    {
        $analysis = [
            'types'            => ['integer'],
            'detected_format'  => null,
            'string_patterns'  => [],
        ];

        $result = $this->invokePrivate('recommendPropertyType', [$analysis]);
        $this->assertEquals('integer', $result);
    }


    /**
     * Test recommendPropertyType with double type returns number.
     *
     * @return void
     */
    public function testRecommendPropertyTypeDoubleReturnsNumber(): void
    {
        $analysis = [
            'types'            => ['double'],
            'detected_format'  => null,
            'string_patterns'  => [],
        ];

        $result = $this->invokePrivate('recommendPropertyType', [$analysis]);
        $this->assertEquals('number', $result);
    }


    /**
     * Test recommendPropertyType with format-based detection overrides type.
     *
     * @return void
     */
    public function testRecommendPropertyTypeFormatOverridesType(): void
    {
        $analysis = [
            'types'            => ['string'],
            'detected_format'  => 'email',
            'string_patterns'  => [],
        ];

        $result = $this->invokePrivate('recommendPropertyType', [$analysis]);
        $this->assertEquals('string', $result);
    }


    /**
     * Test recommendPropertyType with integer_string pattern.
     *
     * @return void
     */
    public function testRecommendPropertyTypeIntegerStringPattern(): void
    {
        $analysis = [
            'types'            => ['string'],
            'detected_format'  => null,
            'string_patterns'  => ['integer_string'],
        ];

        $result = $this->invokePrivate('recommendPropertyType', [$analysis]);
        $this->assertEquals('integer', $result);
    }


    /**
     * Test recommendPropertyType with boolean_string pattern.
     *
     * @return void
     */
    public function testRecommendPropertyTypeBooleanStringPattern(): void
    {
        $analysis = [
            'types'            => ['string'],
            'detected_format'  => null,
            'string_patterns'  => ['boolean_string'],
        ];

        $result = $this->invokePrivate('recommendPropertyType', [$analysis]);
        $this->assertEquals('boolean', $result);
    }


    /**
     * Test recommendPropertyType with multiple types uses dominant.
     *
     * @return void
     */
    public function testRecommendPropertyTypeMultipleTypesUsesDominant(): void
    {
        $analysis = [
            'types'            => ['string', 'string', 'integer'],
            'detected_format'  => null,
            'string_patterns'  => [],
        ];

        $result = $this->invokePrivate('recommendPropertyType', [$analysis]);
        $this->assertEquals('string', $result);
    }


    // =========================================================================
    // detectEnumLike tests
    // =========================================================================


    /**
     * Test detectEnumLike returns true for few unique values with repeats.
     *
     * @return void
     */
    public function testDetectEnumLikeReturnsTrueForFewUniqueValues(): void
    {
        $analysis = [
            'types'    => ['string'],
            'examples' => ['active', 'inactive', 'active', 'pending', 'active', 'inactive'],
        ];

        $result = $this->invokePrivate('detectEnumLike', [$analysis]);
        $this->assertTrue($result);
    }


    /**
     * Test detectEnumLike returns false for many unique values.
     *
     * @return void
     */
    public function testDetectEnumLikeReturnsFalseForManyUniqueValues(): void
    {
        $analysis = [
            'types'    => ['string'],
            'examples' => ['val1', 'val2', 'val3', 'val4', 'val5'],
        ];

        $result = $this->invokePrivate('detectEnumLike', [$analysis]);
        $this->assertFalse($result);
    }


    /**
     * Test detectEnumLike returns false with too few examples.
     *
     * @return void
     */
    public function testDetectEnumLikeReturnsFalseWithTooFewExamples(): void
    {
        $analysis = [
            'types'    => ['string'],
            'examples' => ['a', 'b'],
        ];

        $result = $this->invokePrivate('detectEnumLike', [$analysis]);
        $this->assertFalse($result);
    }


    /**
     * Test detectEnumLike returns false when types are not string.
     *
     * @return void
     */
    public function testDetectEnumLikeReturnsFalseForNonStringTypes(): void
    {
        $analysis = [
            'types'    => ['integer'],
            'examples' => [1, 2, 1, 2, 1, 2],
        ];

        $result = $this->invokePrivate('detectEnumLike', [$analysis]);
        $this->assertFalse($result);
    }


    // =========================================================================
    // extractEnumValues tests
    // =========================================================================


    /**
     * Test extractEnumValues returns unique non-null values.
     *
     * @return void
     */
    public function testExtractEnumValuesReturnsUniqueValues(): void
    {
        $result = $this->invokePrivate('extractEnumValues', [['a', 'b', 'a', null, '', 'c']]);

        $this->assertCount(3, $result);
        $this->assertContains('a', $result);
        $this->assertContains('b', $result);
        $this->assertContains('c', $result);
    }


    // =========================================================================
    // mergeNumericRanges tests
    // =========================================================================


    /**
     * Test mergeNumericRanges with null existing range.
     *
     * @return void
     */
    public function testMergeNumericRangesWithNullExisting(): void
    {
        $newRange = ['min' => 5, 'max' => 10, 'type' => 'integer'];

        $result = $this->invokePrivate('mergeNumericRanges', [null, $newRange]);

        $this->assertEquals(5, $result['min']);
        $this->assertEquals(10, $result['max']);
        $this->assertEquals('integer', $result['type']);
    }


    /**
     * Test mergeNumericRanges expands range when new range is wider.
     *
     * @return void
     */
    public function testMergeNumericRangesExpandsRange(): void
    {
        $existing = ['min' => 5, 'max' => 10, 'type' => 'integer'];
        $newRange = ['min' => 1, 'max' => 20, 'type' => 'integer'];

        $result = $this->invokePrivate('mergeNumericRanges', [$existing, $newRange]);

        $this->assertEquals(1, $result['min']);
        $this->assertEquals(20, $result['max']);
    }


    /**
     * Test mergeNumericRanges promotes integer to number on type mismatch.
     *
     * @return void
     */
    public function testMergeNumericRangesTypePromotion(): void
    {
        $existing = ['min' => 5, 'max' => 10, 'type' => 'integer'];
        $newRange = ['min' => 3.5, 'max' => 15.5, 'type' => 'number'];

        $result = $this->invokePrivate('mergeNumericRanges', [$existing, $newRange]);

        $this->assertEquals('number', $result['type']);
        $this->assertEquals(3.5, $result['min']);
        $this->assertEquals(15.5, $result['max']);
    }


    /**
     * Test mergeNumericRanges keeps same type when ranges overlap.
     *
     * @return void
     */
    public function testMergeNumericRangesOverlappingRanges(): void
    {
        $existing = ['min' => 3, 'max' => 8, 'type' => 'integer'];
        $newRange = ['min' => 5, 'max' => 12, 'type' => 'integer'];

        $result = $this->invokePrivate('mergeNumericRanges', [$existing, $newRange]);

        $this->assertEquals(3, $result['min']);
        $this->assertEquals(12, $result['max']);
        $this->assertEquals('integer', $result['type']);
    }


    // =========================================================================
    // consolidateFormatDetection tests
    // =========================================================================


    /**
     * Test consolidateFormatDetection with null existing returns new format.
     *
     * @return void
     */
    public function testConsolidateFormatDetectionNullExisting(): void
    {
        $result = $this->invokePrivate('consolidateFormatDetection', [null, 'email']);
        $this->assertEquals('email', $result);
    }


    /**
     * Test consolidateFormatDetection with same format keeps it.
     *
     * @return void
     */
    public function testConsolidateFormatDetectionSameFormat(): void
    {
        $result = $this->invokePrivate('consolidateFormatDetection', ['date', 'date']);
        $this->assertEquals('date', $result);
    }


    /**
     * Test consolidateFormatDetection with different formats uses higher priority.
     *
     * @return void
     */
    public function testConsolidateFormatDetectionDifferentFormatsHigherPriorityWins(): void
    {
        // date-time has priority 10, url has priority 5
        $result = $this->invokePrivate('consolidateFormatDetection', ['url', 'date-time']);
        $this->assertEquals('date-time', $result);
    }


    /**
     * Test consolidateFormatDetection keeps existing when it has higher priority.
     *
     * @return void
     */
    public function testConsolidateFormatDetectionKeepsHigherPriorityExisting(): void
    {
        // date has priority 9, color has priority 2
        $result = $this->invokePrivate('consolidateFormatDetection', ['date', 'color']);
        $this->assertEquals('date', $result);
    }


    // =========================================================================
    // analyzezArrayStructure tests
    // =========================================================================


    /**
     * Test analyzezArrayStructure with empty array.
     *
     * @return void
     */
    public function testAnalyzezArrayStructureEmpty(): void
    {
        $result = $this->invokePrivate('analyzezArrayStructure', [[]]);
        $this->assertEquals('empty', $result['type']);
    }


    /**
     * Test analyzezArrayStructure with list array.
     *
     * @return void
     */
    public function testAnalyzezArrayStructureList(): void
    {
        $result = $this->invokePrivate('analyzezArrayStructure', [['a', 'b', 'c']]);

        $this->assertEquals('list', $result['type']);
        $this->assertEquals(3, $result['length']);
        $this->assertArrayHasKey('string', $result['item_types']);
        $this->assertEquals(3, $result['item_types']['string']);
    }


    /**
     * Test analyzezArrayStructure with associative array.
     *
     * @return void
     */
    public function testAnalyzezArrayStructureAssociative(): void
    {
        $result = $this->invokePrivate('analyzezArrayStructure', [['key' => 'val']]);

        $this->assertEquals('associative', $result['type']);
        $this->assertContains('key', $result['keys']);
    }


    // =========================================================================
    // analyzeObjectStructure tests
    // =========================================================================


    /**
     * Test analyzeObjectStructure with an stdClass object.
     *
     * @return void
     */
    public function testAnalyzeObjectStructureWithStdClass(): void
    {
        $obj      = new \stdClass();
        $obj->foo = 'bar';
        $obj->baz = 123;

        $result = $this->invokePrivate('analyzeObjectStructure', [$obj]);

        $this->assertEquals('object', $result['type']);
        $this->assertContains('foo', $result['keys']);
        $this->assertContains('baz', $result['keys']);
        $this->assertEquals(2, $result['key_count']);
    }


    /**
     * Test analyzeObjectStructure with a scalar value.
     *
     * @return void
     */
    public function testAnalyzeObjectStructureWithScalar(): void
    {
        $result = $this->invokePrivate('analyzeObjectStructure', ['just a string']);

        $this->assertEquals('scalar', $result['type']);
        $this->assertEquals('just a string', $result['value']);
    }


    // =========================================================================
    // mergePropertyAnalysis tests
    // =========================================================================


    /**
     * Test mergePropertyAnalysis merges types correctly.
     *
     * @return void
     */
    public function testMergePropertyAnalysisMergesTypes(): void
    {
        $existing = [
            'types'            => ['string'],
            'examples'         => ['hello'],
            'max_length'       => 5,
            'min_length'       => 5,
            'object_structure' => null,
            'array_structure'  => null,
            'detected_format'  => null,
            'string_patterns'  => [],
            'numeric_range'    => null,
        ];

        $new = [
            'types'            => ['integer'],
            'examples'         => [42],
            'max_length'       => 0,
            'min_length'       => PHP_INT_MAX,
            'object_structure' => null,
            'array_structure'  => null,
            'detected_format'  => null,
            'string_patterns'  => [],
            'numeric_range'    => ['min' => 42, 'max' => 42, 'type' => 'integer'],
        ];

        $method = new ReflectionMethod($this->service, 'mergePropertyAnalysis');
        $method->setAccessible(true);
        $method->invokeArgs($this->service, [&$existing, $new]);

        $this->assertContains('string', $existing['types']);
        $this->assertContains('integer', $existing['types']);
        $this->assertNotNull($existing['numeric_range']);
    }


    // =========================================================================
    // generateSuggestions tests
    // =========================================================================


    /**
     * Test generateSuggestions creates suggestions for new properties.
     *
     * @return void
     */
    public function testGenerateSuggestionsForNewProperties(): void
    {
        $discovered = [
            'email' => [
                'name'             => 'email',
                'types'            => ['string'],
                'examples'         => ['user@test.com'],
                'nullable'         => true,
                'enum_values'      => [],
                'max_length'       => 13,
                'min_length'       => 13,
                'object_structure' => null,
                'array_structure'  => null,
                'detected_format'  => 'email',
                'string_patterns'  => [],
                'numeric_range'    => null,
                'usage_count'      => 5,
                'usage_percentage' => 100,
            ],
        ];

        $existingProperties = [];

        $result = $this->invokePrivate('generateSuggestions', [$discovered, $existingProperties]);

        $this->assertNotEmpty($result);
        $this->assertEquals('email', $result[0]['property_name']);
        $this->assertEquals('high', $result[0]['confidence']);
        $this->assertEquals('string', $result[0]['recommended_type']);
    }


    /**
     * Test generateSuggestions skips already existing properties.
     *
     * @return void
     */
    public function testGenerateSuggestionsSkipsExistingProperties(): void
    {
        $discovered = [
            'name' => [
                'name'             => 'name',
                'types'            => ['string'],
                'examples'         => ['John'],
                'nullable'         => true,
                'enum_values'      => [],
                'max_length'       => 4,
                'min_length'       => 4,
                'object_structure' => null,
                'array_structure'  => null,
                'detected_format'  => null,
                'string_patterns'  => [],
                'numeric_range'    => null,
                'usage_count'      => 5,
                'usage_percentage' => 100,
            ],
        ];

        $existingProperties = ['name' => ['type' => 'string']];

        $result = $this->invokePrivate('generateSuggestions', [$discovered, $existingProperties]);

        $this->assertEmpty($result);
    }


    /**
     * Test generateSuggestions skips internal properties.
     *
     * @return void
     */
    public function testGenerateSuggestionsSkipsInternalProperties(): void
    {
        $discovered = [
            'id' => [
                'name'             => 'id',
                'types'            => ['integer'],
                'examples'         => [1],
                'nullable'         => false,
                'enum_values'      => [],
                'max_length'       => 0,
                'min_length'       => PHP_INT_MAX,
                'object_structure' => null,
                'array_structure'  => null,
                'detected_format'  => null,
                'string_patterns'  => [],
                'numeric_range'    => ['min' => 1, 'max' => 100, 'type' => 'integer'],
                'usage_count'      => 10,
                'usage_percentage' => 100,
            ],
        ];

        $existingProperties = [];

        $result = $this->invokePrivate('generateSuggestions', [$discovered, $existingProperties]);

        $this->assertEmpty($result);
    }


    /**
     * Test generateSuggestions assigns correct confidence levels.
     *
     * @return void
     */
    public function testGenerateSuggestionsConfidenceLevels(): void
    {
        $makeProperty = function (string $name, float $usage): array {
            return [
                'name'             => $name,
                'types'            => ['string'],
                'examples'         => ['val'],
                'nullable'         => true,
                'enum_values'      => [],
                'max_length'       => 3,
                'min_length'       => 3,
                'object_structure' => null,
                'array_structure'  => null,
                'detected_format'  => null,
                'string_patterns'  => [],
                'numeric_range'    => null,
                'usage_count'      => 1,
                'usage_percentage' => $usage,
            ];
        };

        $discovered = [
            'high_conf'   => $makeProperty('high_conf', 90),
            'medium_conf' => $makeProperty('medium_conf', 60),
            'low_conf'    => $makeProperty('low_conf', 30),
        ];

        $result = $this->invokePrivate('generateSuggestions', [$discovered, []]);

        $confidenceMap = [];
        foreach ($result as $suggestion) {
            $confidenceMap[$suggestion['property_name']] = $suggestion['confidence'];
        }

        $this->assertEquals('high', $confidenceMap['high_conf']);
        $this->assertEquals('medium', $confidenceMap['medium_conf']);
        $this->assertEquals('low', $confidenceMap['low_conf']);
    }


    // =========================================================================
    // exploreSchemaProperties integration test
    // =========================================================================


    /**
     * Test exploreSchemaProperties with no objects returns empty results.
     *
     * @return void
     */
    public function testExploreSchemaPropertiesNoObjects(): void
    {
        $schema = new Schema();
        $schema->setTitle('Test Schema');
        $schema->setProperties(['name' => ['type' => 'string']]);

        $this->schemaMapper->method('find')
            ->with(1)
            ->willReturn($schema);

        $this->objectMapper->method('findBySchema')
            ->with(1)
            ->willReturn([]);

        $result = $this->service->exploreSchemaProperties(schemaId: 1);

        $this->assertEquals(1, $result['schema_id']);
        $this->assertEquals('Test Schema', $result['schema_title']);
        $this->assertEquals(0, $result['total_objects']);
        $this->assertEmpty($result['discovered_properties']);
        $this->assertEquals('No objects found for analysis', $result['message']);
    }


    /**
     * Test exploreSchemaProperties throws exception for missing schema.
     *
     * @return void
     */
    public function testExploreSchemaPropertiesThrowsOnMissingSchema(): void
    {
        $this->schemaMapper->method('find')
            ->willThrowException(new \Exception('Not found'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Schema not found with ID: 999');

        $this->service->exploreSchemaProperties(schemaId: 999);
    }


    /**
     * Test exploreSchemaProperties discovers new properties from objects.
     *
     * @return void
     */
    public function testExploreSchemaPropertiesDiscoversProperties(): void
    {
        $schema = new Schema();
        $schema->setTitle('Test Schema');
        $schema->setProperties(['name' => ['type' => 'string']]);

        $object1 = new ObjectEntity();
        $object1->setObject([
            'name'  => 'Alice',
            'email' => 'alice@example.com',
            'age'   => 30,
        ]);

        $object2 = new ObjectEntity();
        $object2->setObject([
            'name'  => 'Bob',
            'email' => 'bob@example.com',
            'age'   => 25,
        ]);

        $this->schemaMapper->method('find')
            ->with(1)
            ->willReturn($schema);

        $this->objectMapper->method('findBySchema')
            ->with(1)
            ->willReturn([$object1, $object2]);

        $result = $this->service->exploreSchemaProperties(schemaId: 1);

        $this->assertEquals(2, $result['total_objects']);
        $this->assertArrayHasKey('email', $result['discovered_properties']);
        $this->assertArrayHasKey('age', $result['discovered_properties']);
        $this->assertNotEmpty($result['suggestions']);
    }


    // =========================================================================
    // updateSchemaFromExploration tests
    // =========================================================================


    /**
     * Test updateSchemaFromExploration merges properties and saves.
     *
     * @return void
     */
    public function testUpdateSchemaFromExplorationMergesAndSaves(): void
    {
        $schema = new Schema();
        $schema->setTitle('Test');
        $schema->setProperties(['name' => ['type' => 'string']]);

        $this->schemaMapper->method('find')
            ->with(1)
            ->willReturn($schema);

        $this->schemaMapper->expects($this->once())
            ->method('update')
            ->with($this->callback(function (Schema $updatedSchema) {
                $props = $updatedSchema->getProperties();
                return isset($props['name']) && isset($props['email']);
            }))
            ->willReturnCallback(function (Schema $s) {
                return $s;
            });

        $result = $this->service->updateSchemaFromExploration(
            schemaId: 1,
            propertyUpdates: ['email' => ['type' => 'string', 'format' => 'email']]
        );

        $this->assertInstanceOf(Schema::class, $result);
        $props = $result->getProperties();
        $this->assertArrayHasKey('name', $props);
        $this->assertArrayHasKey('email', $props);
    }


    /**
     * Test updateSchemaFromExploration throws on mapper failure.
     *
     * @return void
     */
    public function testUpdateSchemaFromExplorationThrowsOnFailure(): void
    {
        $this->schemaMapper->method('find')
            ->willThrowException(new \Exception('DB error'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to update schema properties');

        $this->service->updateSchemaFromExploration(
            schemaId: 1,
            propertyUpdates: ['test' => ['type' => 'string']]
        );
    }



    // =========================================================================
    // compareType tests
    // =========================================================================


    /**
     * Test compareType with missing type in config suggests type.
     *
     * @return void
     */
    public function testCompareTypeWithMissingType(): void
    {
        $currentConfig   = [];
        // No 'type' key.
        $recommendedType = 'string';

        $result = $this->invokePrivate('compareType', [$currentConfig, $recommendedType]);

        $this->assertEmpty($result['issues']);
        $this->assertNotEmpty($result['suggestions']);
        $this->assertEquals('type', $result['suggestions'][0]['type']);
        $this->assertEquals('string', $result['suggestions'][0]['recommended']);
    }


    /**
     * Test compareType with matching type produces no issues.
     *
     * @return void
     */
    public function testCompareTypeWithMatchingType(): void
    {
        $currentConfig   = ['type' => 'integer'];
        $recommendedType = 'integer';

        $result = $this->invokePrivate('compareType', [$currentConfig, $recommendedType]);

        $this->assertEmpty($result['issues']);
        $this->assertEmpty($result['suggestions']);
    }


    /**
     * Test compareType with mismatched type produces issue.
     *
     * @return void
     */
    public function testCompareTypeWithMismatchedType(): void
    {
        $currentConfig   = ['type' => 'string'];
        $recommendedType = 'integer';

        $result = $this->invokePrivate('compareType', [$currentConfig, $recommendedType]);

        $this->assertNotEmpty($result['issues']);
        $this->assertStringContainsString('string', $result['issues'][0]);
        $this->assertStringContainsString('integer', $result['issues'][0]);
    }


    // =========================================================================
    // compareStringConstraints tests
    // =========================================================================


    /**
     * Test compareStringConstraints skips non-string types.
     *
     * @return void
     */
    public function testCompareStringConstraintsSkipsNonStringTypes(): void
    {
        $currentConfig   = ['type' => 'integer'];
        $analysis        = ['max_length' => 10, 'detected_format' => 'email', 'string_patterns' => ['snake_case']];
        $recommendedType = 'integer';

        $result = $this->invokePrivate('compareStringConstraints', [$currentConfig, $analysis, $recommendedType]);

        $this->assertEmpty($result['issues']);
        $this->assertEmpty($result['suggestions']);
    }


    /**
     * Test compareStringConstraints detects missing maxLength.
     *
     * @return void
     */
    public function testCompareStringConstraintsDetectsMissingMaxLength(): void
    {
        $currentConfig   = ['type' => 'string'];
        // No maxLength.
        $analysis        = ['max_length' => 50, 'detected_format' => null, 'string_patterns' => []];
        $recommendedType = 'string';

        $result = $this->invokePrivate('compareStringConstraints', [$currentConfig, $analysis, $recommendedType]);

        $this->assertContains('missing_max_length', $result['issues']);
        $this->assertNotEmpty($result['suggestions']);
        $this->assertEquals('maxLength', $result['suggestions'][0]['field']);
    }


    /**
     * Test compareStringConstraints detects maxLength too small.
     *
     * @return void
     */
    public function testCompareStringConstraintsDetectsMaxLengthTooSmall(): void
    {
        $currentConfig   = ['type' => 'string', 'maxLength' => 10];
        $analysis        = ['max_length' => 50, 'detected_format' => null, 'string_patterns' => []];
        $recommendedType = 'string';

        $result = $this->invokePrivate('compareStringConstraints', [$currentConfig, $analysis, $recommendedType]);

        $this->assertContains('max_length_too_small', $result['issues']);
        $this->assertEquals(50, $result['suggestions'][0]['recommended']);
    }


    /**
     * Test compareStringConstraints detects missing format.
     *
     * @return void
     */
    public function testCompareStringConstraintsDetectsMissingFormat(): void
    {
        $currentConfig   = ['type' => 'string'];
        // No format.
        $analysis        = ['max_length' => 0, 'detected_format' => 'email', 'string_patterns' => []];
        $recommendedType = 'string';

        $result = $this->invokePrivate('compareStringConstraints', [$currentConfig, $analysis, $recommendedType]);

        $this->assertContains('missing_format', $result['issues']);
        $formatSuggestion = array_filter($result['suggestions'], fn($s) => $s['field'] === 'format');
        $this->assertNotEmpty($formatSuggestion);
    }


    /**
     * Test compareStringConstraints detects missing pattern.
     *
     * @return void
     */
    public function testCompareStringConstraintsDetectsMissingPattern(): void
    {
        $currentConfig   = ['type' => 'string'];
        // No pattern.
        $analysis        = ['max_length' => 0, 'detected_format' => null, 'string_patterns' => ['snake_case']];
        $recommendedType = 'string';

        $result = $this->invokePrivate('compareStringConstraints', [$currentConfig, $analysis, $recommendedType]);

        $this->assertContains('missing_pattern', $result['issues']);
    }


    // =========================================================================
    // compareNullableConstraint tests
    // =========================================================================


    /**
     * Test compareNullableConstraint with required=true and nullable analysis.
     *
     * @return void
     */
    public function testCompareNullableConstraintWithRequiredAndNullable(): void
    {
        $currentConfig = ['type' => 'string', 'required' => true];
        $analysis      = ['nullable' => true];

        $result = $this->invokePrivate('compareNullableConstraint', [$currentConfig, $analysis]);

        $this->assertNotEmpty($result['issues']);
        $this->assertStringContainsString('null values', $result['issues'][0]);
    }


    /**
     * Test compareNullableConstraint with non-required config and nullable is fine.
     *
     * @return void
     */
    public function testCompareNullableConstraintWithNonRequiredConfig(): void
    {
        $currentConfig = ['type' => 'string', 'required' => false];
        $analysis      = ['nullable' => true];

        $result = $this->invokePrivate('compareNullableConstraint', [$currentConfig, $analysis]);

        // No issues because required is false.
        $this->assertEmpty($result['issues']);
    }


    /**
     * Test compareNullableConstraint with not-nullable analysis produces no issues.
     *
     * @return void
     */
    public function testCompareNullableConstraintWithNonNullableAnalysis(): void
    {
        $currentConfig = ['type' => 'string', 'required' => true];
        $analysis      = ['nullable' => false];

        $result = $this->invokePrivate('compareNullableConstraint', [$currentConfig, $analysis]);

        $this->assertEmpty($result['issues']);
        $this->assertEmpty($result['suggestions']);
    }


    // =========================================================================
    // compareEnumConstraint tests
    // =========================================================================


    /**
     * Test compareEnumConstraint suggests adding enum values.
     *
     * @return void
     */
    public function testCompareEnumConstraintSuggestsAddingEnum(): void
    {
        $currentConfig = ['type' => 'string'];
        // No enum.
        $analysis      = ['enum_values' => ['active', 'inactive', 'pending']];

        $result = $this->invokePrivate('compareEnumConstraint', [$currentConfig, $analysis]);

        $this->assertNotEmpty($result['suggestions']);
        $this->assertEquals('enum', $result['suggestions'][0]['type']);
    }


    /**
     * Test compareEnumConstraint detects enum mismatch.
     *
     * @return void
     */
    public function testCompareEnumConstraintDetectsEnumMismatch(): void
    {
        $currentConfig = ['type' => 'string', 'enum' => ['active', 'inactive']];
        $analysis      = ['enum_values' => ['active', 'inactive', 'deleted']];

        $result = $this->invokePrivate('compareEnumConstraint', [$currentConfig, $analysis]);

        $this->assertNotEmpty($result['issues']);
        $this->assertStringContainsString('Enum values', $result['issues'][0]);
    }


    /**
     * Test compareEnumConstraint with null enum_values produces no changes.
     *
     * @return void
     */
    public function testCompareEnumConstraintWithNullEnumValues(): void
    {
        $currentConfig = ['type' => 'string'];
        $analysis      = ['enum_values' => null];

        $result = $this->invokePrivate('compareEnumConstraint', [$currentConfig, $analysis]);

        $this->assertEmpty($result['issues']);
        $this->assertEmpty($result['suggestions']);
    }


    /**
     * Test compareEnumConstraint with too many enum values (>20) skips suggestion.
     *
     * @return void
     */
    public function testCompareEnumConstraintSkipsWhenTooManyValues(): void
    {
        $currentConfig = ['type' => 'string'];
        $manyValues    = range(1, 25);
        $analysis      = ['enum_values' => $manyValues];

        $result = $this->invokePrivate('compareEnumConstraint', [$currentConfig, $analysis]);

        $this->assertEmpty($result['suggestions']);
    }


    // =========================================================================
    // analyzeExistingProperties tests
    // =========================================================================


    /**
     * Test analyzeExistingProperties returns improvements for type mismatch.
     *
     * @return void
     */
    public function testAnalyzeExistingPropertiesReturnsMismatchImprovements(): void
    {
        $existingProperties = [
            'age' => ['type' => 'string'],
            // Should be integer
        ];

        $discoveredProperties = [
            'age' => [
                'types'            => ['integer'],
                'examples'         => [30],
                'nullable'         => false,
                'enum_values'      => [],
                'max_length'       => 0,
                'min_length'       => PHP_INT_MAX,
                'object_structure' => null,
                'array_structure'  => null,
                'detected_format'  => null,
                'string_patterns'  => [],
                'numeric_range'    => ['min' => 18, 'max' => 99, 'type' => 'integer'],
                'usage_count'      => 5,
                'usage_percentage' => 100,
            ],
        ];

        $result = $this->invokePrivate('analyzeExistingProperties', [$existingProperties, $discoveredProperties, []]);

        $this->assertNotEmpty($result);
        $this->assertEquals('age', $result[0]['property_name']);
        $this->assertEquals('existing', $result[0]['improvement_status']);
    }


    /**
     * Test analyzeExistingProperties skips properties not in discovered.
     *
     * @return void
     */
    public function testAnalyzeExistingPropertiesSkipsUndiscoveredProperties(): void
    {
        $existingProperties   = ['name' => ['type' => 'string']];
        $discoveredProperties = [];
        // name not discovered.

        $result = $this->invokePrivate('analyzeExistingProperties', [$existingProperties, $discoveredProperties, []]);

        $this->assertEmpty($result);
    }


    // =========================================================================
    // generateNestedProperties tests
    // =========================================================================


    /**
     * Test generateNestedProperties creates property definitions for all keys.
     *
     * @return void
     */
    public function testGenerateNestedPropertiesCreatesDefinitions(): void
    {
        $objectStructure = ['type' => 'object', 'keys' => ['first_name', 'last_name', 'age'], 'key_count' => 3];

        $result = $this->invokePrivate('generateNestedProperties', [$objectStructure]);

        $this->assertArrayHasKey('first_name', $result);
        $this->assertArrayHasKey('last_name', $result);
        $this->assertArrayHasKey('age', $result);
        $this->assertEquals('string', $result['first_name']['type']);
    }


    /**
     * Test generateNestedProperties with null keys returns empty.
     *
     * @return void
     */
    public function testGenerateNestedPropertiesWithNullKeys(): void
    {
        $objectStructure = ['type' => 'object'];
        // No 'keys'.

        $result = $this->invokePrivate('generateNestedProperties', [$objectStructure]);

        $this->assertEmpty($result);
    }


    // =========================================================================
    // generateArrayItemType tests
    // =========================================================================


    /**
     * Test generateArrayItemType returns type from item_types.
     *
     * @return void
     */
    public function testGenerateArrayItemTypeReturnsItemType(): void
    {
        $arrayStructure = ['type' => 'list', 'item_types' => ['string' => 3], 'length' => 3];

        $result = $this->invokePrivate('generateArrayItemType', [$arrayStructure]);

        $this->assertArrayHasKey('type', $result);
        $this->assertEquals('string', $result['type']);
    }


    /**
     * Test generateArrayItemType with empty item_types returns string default.
     *
     * @return void
     */
    public function testGenerateArrayItemTypeWithEmptyItemTypesReturnsString(): void
    {
        $arrayStructure = ['type' => 'list', 'item_types' => [], 'length' => 0];

        $result = $this->invokePrivate('generateArrayItemType', [$arrayStructure]);

        $this->assertEquals('string', $result['type']);
    }


    // =========================================================================
    // normalizeSingleType tests
    // =========================================================================


    /**
     * Test normalizeSingleType handles null type (returns string fallback).
     *
     * @return void
     */
    public function testNormalizeSingleTypeHandlesNullType(): void
    {
        // 'null' maps to 'null' in the switch.
        $result = $this->invokePrivate('normalizeSingleType', ['null', []]);

        $this->assertEquals('null', $result);
    }


    /**
     * Test normalizeSingleType handles object type.
     *
     * @return void
     */
    public function testNormalizeSingleTypeHandlesObjectType(): void
    {
        $result = $this->invokePrivate('normalizeSingleType', ['object', []]);

        $this->assertEquals('object', $result);
    }


    /**
     * Test normalizeSingleType handles array type.
     *
     * @return void
     */
    public function testNormalizeSingleTypeHandlesArrayType(): void
    {
        $result = $this->invokePrivate('normalizeSingleType', ['array', []]);

        $this->assertEquals('array', $result);
    }


    /**
     * Test normalizeSingleType handles boolean type.
     *
     * @return void
     */
    public function testNormalizeSingleTypeHandlesBooleanType(): void
    {
        $result = $this->invokePrivate('normalizeSingleType', ['boolean', []]);

        $this->assertEquals('boolean', $result);
    }


    /**
     * Test normalizeSingleType handles unknown type returns string.
     *
     * @return void
     */
    public function testNormalizeSingleTypeHandlesUnknownType(): void
    {
        $result = $this->invokePrivate('normalizeSingleType', ['unknown_custom_type', []]);

        $this->assertEquals('string', $result);
    }


    /**
     * Test normalizeSingleType handles float type.
     *
     * @return void
     */
    public function testNormalizeSingleTypeHandlesFloatType(): void
    {
        $result = $this->invokePrivate('normalizeSingleType', ['float', []]);

        $this->assertEquals('number', $result);
    }


    // =========================================================================
    // getDominantType tests
    // =========================================================================


    /**
     * Test getDominantType with float_string pattern returns number.
     *
     * @return void
     */
    public function testGetDominantTypeWithFloatStringPattern(): void
    {
        $result = $this->invokePrivate('getDominantType', [['string', 'string', 'string'], ['float_string']]);

        $this->assertEquals('number', $result);
    }


    /**
     * Test getDominantType with boolean_string pattern returns boolean.
     *
     * @return void
     */
    public function testGetDominantTypeWithBooleanStringPattern(): void
    {
        $result = $this->invokePrivate('getDominantType', [['string', 'string', 'string'], ['boolean_string']]);

        $this->assertEquals('boolean', $result);
    }


    /**
     * Test getDominantType with integer dominant type.
     *
     * @return void
     */
    public function testGetDominantTypeWithIntegerDominant(): void
    {
        $result = $this->invokePrivate('getDominantType', [['integer', 'integer', 'string'], []]);

        $this->assertEquals('integer', $result);
    }


    // =========================================================================
    // mergeNumericRanges - additional edge cases
    // =========================================================================


    /**
     * Test mergeNumericRanges with number->integer keeps number.
     *
     * @return void
     */
    public function testMergeNumericRangesNumberDominatesInteger(): void
    {
        $existing = ['min' => 1.5, 'max' => 10.5, 'type' => 'number'];
        $newRange = ['min' => 2, 'max' => 8, 'type' => 'integer'];

        $result = $this->invokePrivate('mergeNumericRanges', [$existing, $newRange]);

        // number type is kept when existing is number and new is integer.
        $this->assertEquals('number', $result['type']);
    }


    /**
     * Test mergeNumericRanges with incompatible types defaults to number.
     *
     * @return void
     */
    public function testMergeNumericRangesIncompatibleTypesDefaultsToNumber(): void
    {
        $existing = ['min' => 1, 'max' => 10, 'type' => 'string'];
        // Invalid but possible edge case.
        $newRange = ['min' => 5, 'max' => 15, 'type' => 'integer'];

        $result = $this->invokePrivate('mergeNumericRanges', [$existing, $newRange]);

        $this->assertEquals('number', $result['type']);
        $this->assertEquals(1, $result['min']);
        $this->assertEquals(15, $result['max']);
    }


    // =========================================================================
    // generateSuggestions - object and array property types
    // =========================================================================


    /**
     * Test generateSuggestions creates object type suggestion for nested objects.
     *
     * @return void
     */
    public function testGenerateSuggestionsCreatesObjectTypeSuggestion(): void
    {
        $discovered = [
            'address' => [
                'name'             => 'address',
                'types'            => ['array'],
                'examples'         => [['street' => 'Main St', 'city' => 'Amsterdam']],
                'nullable'         => true,
                'enum_values'      => [],
                'max_length'       => 0,
                'min_length'       => PHP_INT_MAX,
                'object_structure' => ['type' => 'object', 'keys' => ['street', 'city'], 'key_count' => 2],
                'array_structure'  => null,
                'detected_format'  => null,
                'string_patterns'  => [],
                'numeric_range'    => null,
                'usage_count'      => 5,
                'usage_percentage' => 100,
            ],
        ];

        $result = $this->invokePrivate('generateSuggestions', [$discovered, []]);

        $this->assertNotEmpty($result);
        $objectSuggestion = $result[0];
        $this->assertEquals('object', $objectSuggestion['type']);
        $this->assertArrayHasKey('properties', $objectSuggestion);
    }


    /**
     * Test generateSuggestions creates array type suggestion for list arrays.
     *
     * @return void
     */
    public function testGenerateSuggestionsCreatesArrayTypeSuggestion(): void
    {
        $discovered = [
            'tags' => [
                'name'             => 'tags',
                'types'            => ['array'],
                'examples'         => [['php', 'nextcloud', 'api']],
                'nullable'         => true,
                'enum_values'      => [],
                'max_length'       => 0,
                'min_length'       => PHP_INT_MAX,
                'object_structure' => null,
                'array_structure'  => ['type' => 'list', 'item_types' => ['string' => 3], 'length' => 3],
                'detected_format'  => null,
                'string_patterns'  => [],
                'numeric_range'    => null,
                'usage_count'      => 5,
                'usage_percentage' => 100,
            ],
        ];

        $result = $this->invokePrivate('generateSuggestions', [$discovered, []]);

        $this->assertNotEmpty($result);
        $arraySuggestion = $result[0];
        $this->assertEquals('array', $arraySuggestion['type']);
        $this->assertArrayHasKey('items', $arraySuggestion);
    }


}//end class
