<?php

/**
 * SchemaService Refactored Methods Unit Tests
 *
 * Comprehensive tests for the 9 private methods extracted during Phase 1 refactoring.
 * Tests cover complex schema comparison and type recommendation logic.
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

use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Service\SchemaService;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionClass;

/**
 * Unit tests for SchemaService refactored methods.
 *
 * Tests the 9 extracted private methods using reflection:
 * From comparePropertyWithAnalysis():
 * 1. compareType()
 * 2. compareStringConstraints()
 * 3. compareNumericConstraints()
 * 4. compareNullableConstraint()
 * 5. compareEnumConstraint()
 *
 * From recommendPropertyType():
 * 6. getTypeFromFormat()
 * 7. getTypeFromPatterns()
 * 8. normalizeSingleType()
 * 9. getDominantType()
 */
class SchemaServiceRefactoredMethodsTest extends TestCase
{
	private SchemaService $schemaService;
	private ReflectionClass $reflection;

	/** @var MockObject|SchemaMapper */
	private $schemaMapper;

	/** @var MockObject|ObjectEntityMapper */
	private $objectEntityMapper;

	/** @var MockObject|LoggerInterface */
	private $logger;

	/**
	 * Set up test environment before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();

		// Create mocks for all dependencies.
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// Create SchemaService instance.
		$this->schemaService = new SchemaService(
			schemaMapper: $this->schemaMapper,
			objectEntityMapper: $this->objectEntityMapper,
			logger: $this->logger
		);

		// Set up reflection for accessing private methods.
		$this->reflection = new ReflectionClass(SchemaService::class);
	}

	/**
	 * Helper method to invoke private methods using reflection.
	 *
	 * @param string $methodName The name of the private method.
	 * @param array  $parameters The parameters to pass to the method.
	 *
	 * @return mixed The result of the method invocation.
	 */
	private function invokePrivateMethod(string $methodName, array $parameters = []): mixed
	{
		$method = $this->reflection->getMethod($methodName);
		$method->setAccessible(true);

		return $method->invokeArgs($this->schemaService, $parameters);
	}

	// ==================== compareType() Tests ====================

	/**
	 * Test compareType with matching types.
	 *
	 * @return void
	 */
	public function testCompareTypeWithMatchingTypes(): void
	{
		$currentConfig = ['type' => 'string'];
		$recommendedType = 'string';

		$result = $this->invokePrivateMethod(
			methodName: 'compareType',
			parameters: [$currentConfig, $recommendedType]
		);

		$this->assertIsArray($result, 'Result should be an array.');
		$this->assertArrayHasKey('issues', $result, 'Result should have issues key.');
		$this->assertArrayHasKey('suggestions', $result, 'Result should have suggestions key.');
		$this->assertEmpty($result['issues'], 'No issues should be found for matching types.');
		$this->assertEmpty($result['suggestions'], 'No suggestions should be made for matching types.');
	}

	/**
	 * Test compareType with mismatched types.
	 *
	 * @return void
	 */
	public function testCompareTypeWithMismatchedTypes(): void
	{
		$currentConfig = ['type' => 'string'];
		$recommendedType = 'integer';

		$result = $this->invokePrivateMethod(
			methodName: 'compareType',
			parameters: [$currentConfig, $recommendedType]
		);

		$this->assertNotEmpty($result['issues'], 'Issues should be found for mismatched types.');
		$this->assertNotEmpty($result['suggestions'], 'Suggestions should be made for mismatched types.');
		$this->assertStringContainsString('type mismatch', strtolower($result['issues'][0]), 'Issue should mention type mismatch.');
	}

	/**
	 * Test compareType with missing type in config.
	 *
	 * @return void
	 */
	public function testCompareTypeWithMissingType(): void
	{
		$currentConfig = [];
		$recommendedType = 'string';

		$result = $this->invokePrivateMethod(
			methodName: 'compareType',
			parameters: [$currentConfig, $recommendedType]
		);

		$this->assertNotEmpty($result['suggestions'], 'Suggestions should be made when type is missing.');
	}

	// ==================== compareStringConstraints() Tests ====================

	/**
	 * Test compareStringConstraints with matching maxLength.
	 *
	 * @return void
	 */
	public function testCompareStringConstraintsWithMatchingMaxLength(): void
	{
		$currentConfig = [
			'type' => 'string',
			'maxLength' => 255
		];
		$analysis = [
			'max_length' => 200
		];
		$recommendedType = 'string';

		$result = $this->invokePrivateMethod(
			methodName: 'compareStringConstraints',
			parameters: [$currentConfig, $analysis, $recommendedType]
		);

		$this->assertIsArray($result, 'Result should be an array.');
		$this->assertEmpty($result['issues'], 'No issues when maxLength is adequate.');
	}

	/**
	 * Test compareStringConstraints with inadequate maxLength.
	 *
	 * @return void
	 */
	public function testCompareStringConstraintsWithInadequateMaxLength(): void
	{
		$currentConfig = [
			'type' => 'string',
			'maxLength' => 50
		];
		$analysis = [
			'max_length' => 200
		];
		$recommendedType = 'string';

		$result = $this->invokePrivateMethod(
			methodName: 'compareStringConstraints',
			parameters: [$currentConfig, $analysis, $recommendedType]
		);

		$this->assertNotEmpty($result['issues'], 'Issues should be found when maxLength is inadequate.');
		$this->assertNotEmpty($result['suggestions'], 'Suggestions should be made to increase maxLength.');
	}

	/**
	 * Test compareStringConstraints with format detection.
	 *
	 * @return void
	 */
	public function testCompareStringConstraintsWithFormat(): void
	{
		$currentConfig = ['type' => 'string'];
		$analysis = [
			'detected_format' => 'email'
		];
		$recommendedType = 'string';

		$result = $this->invokePrivateMethod(
			methodName: 'compareStringConstraints',
			parameters: [$currentConfig, $analysis, $recommendedType]
		);

		$this->assertNotEmpty($result['suggestions'], 'Suggestions should be made to add format constraint.');
	}

	/**
	 * Test compareStringConstraints with pattern detection.
	 *
	 * @return void
	 */
	public function testCompareStringConstraintsWithPattern(): void
	{
		$currentConfig = ['type' => 'string'];
		$analysis = [
			'string_patterns' => ['url', 'http']
		];
		$recommendedType = 'string';

		$result = $this->invokePrivateMethod(
			methodName: 'compareStringConstraints',
			parameters: [$currentConfig, $analysis, $recommendedType]
		);

		$this->assertNotEmpty($result['suggestions'], 'Suggestions should be made for detected patterns.');
	}

	// ==================== compareNumericConstraints() Tests ====================

	/**
	 * Test compareNumericConstraints with valid range.
	 *
	 * @return void
	 */
	public function testCompareNumericConstraintsWithValidRange(): void
	{
		$currentConfig = [
			'type' => 'integer',
			'minimum' => 0,
			'maximum' => 100
		];
		$analysis = [
			'numeric_range' => ['min' => 10, 'max' => 90]
		];
		$recommendedType = 'integer';

		$result = $this->invokePrivateMethod(
			methodName: 'compareNumericConstraints',
			parameters: [$currentConfig, $analysis, $recommendedType]
		);

		$this->assertEmpty($result['issues'], 'No issues when range is adequate.');
	}

	/**
	 * Test compareNumericConstraints with inadequate minimum.
	 *
	 * @return void
	 */
	public function testCompareNumericConstraintsWithInadequateMinimum(): void
	{
		$currentConfig = [
			'type' => 'integer',
			'minimum' => 50
		];
		$analysis = [
			'numeric_range' => ['min' => 10, 'max' => 90]
		];
		$recommendedType = 'integer';

		$result = $this->invokePrivateMethod(
			methodName: 'compareNumericConstraints',
			parameters: [$currentConfig, $analysis, $recommendedType]
		);

		$this->assertNotEmpty($result['issues'], 'Issues should be found when minimum is too high.');
		$this->assertNotEmpty($result['suggestions'], 'Suggestions should be made to lower minimum.');
	}

	/**
	 * Test compareNumericConstraints with inadequate maximum.
	 *
	 * @return void
	 */
	public function testCompareNumericConstraintsWithInadequateMaximum(): void
	{
		$currentConfig = [
			'type' => 'integer',
			'maximum' => 50
		];
		$analysis = [
			'numeric_range' => ['min' => 10, 'max' => 90]
		];
		$recommendedType = 'integer';

		$result = $this->invokePrivateMethod(
			methodName: 'compareNumericConstraints',
			parameters: [$currentConfig, $analysis, $recommendedType]
		);

		$this->assertNotEmpty($result['issues'], 'Issues should be found when maximum is too low.');
		$this->assertNotEmpty($result['suggestions'], 'Suggestions should be made to increase maximum.');
	}

	/**
	 * Test compareNumericConstraints with missing range constraints.
	 *
	 * @return void
	 */
	public function testCompareNumericConstraintsWithMissingConstraints(): void
	{
		$currentConfig = ['type' => 'integer'];
		$analysis = [
			'numeric_range' => ['min' => 10, 'max' => 90]
		];
		$recommendedType = 'integer';

		$result = $this->invokePrivateMethod(
			methodName: 'compareNumericConstraints',
			parameters: [$currentConfig, $analysis, $recommendedType]
		);

		$this->assertNotEmpty($result['suggestions'], 'Suggestions should be made to add range constraints.');
	}

	// ==================== compareNullableConstraint() Tests ====================

	/**
	 * Test compareNullableConstraint with nullable data.
	 *
	 * @return void
	 */
	public function testCompareNullableConstraintWithNullableData(): void
	{
		$currentConfig = ['type' => 'string'];
		$analysis = ['nullable' => true];

		$result = $this->invokePrivateMethod(
			methodName: 'compareNullableConstraint',
			parameters: [$currentConfig, $analysis]
		);

		$this->assertNotEmpty($result['suggestions'], 'Suggestions should be made to make property nullable.');
	}

	/**
	 * Test compareNullableConstraint with non-nullable data.
	 *
	 * @return void
	 */
	public function testCompareNullableConstraintWithNonNullableData(): void
	{
		$currentConfig = ['type' => 'string'];
		$analysis = ['nullable' => false];

		$result = $this->invokePrivateMethod(
			methodName: 'compareNullableConstraint',
			parameters: [$currentConfig, $analysis]
		);

		$this->assertEmpty($result['suggestions'], 'No suggestions when data is not nullable.');
	}

	/**
	 * Test compareNullableConstraint when already configured as nullable.
	 *
	 * @return void
	 */
	public function testCompareNullableConstraintAlreadyNullable(): void
	{
		$currentConfig = [
			'type' => ['string', 'null']
		];
		$analysis = ['nullable' => true];

		$result = $this->invokePrivateMethod(
			methodName: 'compareNullableConstraint',
			parameters: [$currentConfig, $analysis]
		);

		$this->assertEmpty($result['issues'], 'No issues when already configured as nullable.');
	}

	// ==================== compareEnumConstraint() Tests ====================

	/**
	 * Test compareEnumConstraint with enum-like data.
	 *
	 * @return void
	 */
	public function testCompareEnumConstraintWithEnumLikeData(): void
	{
		$currentConfig = ['type' => 'string'];
		$analysis = [
			'enum_values' => ['active', 'inactive', 'pending'],
			'usage_count' => 100
		];

		$result = $this->invokePrivateMethod(
			methodName: 'compareEnumConstraint',
			parameters: [$currentConfig, $analysis]
		);

		$this->assertNotEmpty($result['suggestions'], 'Suggestions should be made to add enum constraint.');
	}

	/**
	 * Test compareEnumConstraint with too many unique values.
	 *
	 * @return void
	 */
	public function testCompareEnumConstraintWithTooManyValues(): void
	{
		$currentConfig = ['type' => 'string'];
		$analysis = [
			'enum_values' => range(1, 50), // 50 unique values.
			'usage_count' => 100
		];

		$result = $this->invokePrivateMethod(
			methodName: 'compareEnumConstraint',
			parameters: [$currentConfig, $analysis]
		);

		$this->assertEmpty($result['suggestions'], 'No enum suggestions when too many unique values.');
	}

	/**
	 * Test compareEnumConstraint when already has enum.
	 *
	 * @return void
	 */
	public function testCompareEnumConstraintAlreadyHasEnum(): void
	{
		$currentConfig = [
			'type' => 'string',
			'enum' => ['active', 'inactive']
		];
		$analysis = [
			'enum_values' => ['active', 'inactive', 'pending'],
			'usage_count' => 100
		];

		$result = $this->invokePrivateMethod(
			methodName: 'compareEnumConstraint',
			parameters: [$currentConfig, $analysis]
		);

		$this->assertNotEmpty($result['issues'], 'Issues should be found when enum values differ.');
	}

	// ==================== getTypeFromFormat() Tests ====================

	/**
	 * Test getTypeFromFormat with email format.
	 *
	 * @return void
	 */
	public function testGetTypeFromFormatWithEmail(): void
	{
		$result = $this->invokePrivateMethod(
			methodName: 'getTypeFromFormat',
			parameters: ['email']
		);

		$this->assertEquals('string', $result, 'Email format should return string type.');
	}

	/**
	 * Test getTypeFromFormat with date-time format.
	 *
	 * @return void
	 */
	public function testGetTypeFromFormatWithDateTime(): void
	{
		$result = $this->invokePrivateMethod(
			methodName: 'getTypeFromFormat',
			parameters: ['date-time']
		);

		$this->assertEquals('string', $result, 'Date-time format should return string type.');
	}

	/**
	 * Test getTypeFromFormat with UUID format.
	 *
	 * @return void
	 */
	public function testGetTypeFromFormatWithUuid(): void
	{
		$result = $this->invokePrivateMethod(
			methodName: 'getTypeFromFormat',
			parameters: ['uuid']
		);

		$this->assertEquals('string', $result, 'UUID format should return string type.');
	}

	/**
	 * Test getTypeFromFormat with null format.
	 *
	 * @return void
	 */
	public function testGetTypeFromFormatWithNull(): void
	{
		$result = $this->invokePrivateMethod(
			methodName: 'getTypeFromFormat',
			parameters: [null]
		);

		$this->assertNull($result, 'Null format should return null.');
	}

	/**
	 * Test getTypeFromFormat with empty format.
	 *
	 * @return void
	 */
	public function testGetTypeFromFormatWithEmpty(): void
	{
		$result = $this->invokePrivateMethod(
			methodName: 'getTypeFromFormat',
			parameters: ['']
		);

		$this->assertNull($result, 'Empty format should return null.');
	}

	// ==================== getTypeFromPatterns() Tests ====================

	/**
	 * Test getTypeFromPatterns with boolean pattern.
	 *
	 * @return void
	 */
	public function testGetTypeFromPatternsWithBoolean(): void
	{
		$result = $this->invokePrivateMethod(
			methodName: 'getTypeFromPatterns',
			parameters: [['boolean_string']]
		);

		$this->assertEquals('boolean', $result, 'Boolean pattern should return boolean type.');
	}

	/**
	 * Test getTypeFromPatterns with integer pattern.
	 *
	 * @return void
	 */
	public function testGetTypeFromPatternsWithInteger(): void
	{
		$result = $this->invokePrivateMethod(
			methodName: 'getTypeFromPatterns',
			parameters: [['integer_string']]
		);

		$this->assertEquals('integer', $result, 'Integer pattern should return integer type.');
	}

	/**
	 * Test getTypeFromPatterns with float pattern.
	 *
	 * @return void
	 */
	public function testGetTypeFromPatternsWithFloat(): void
	{
		$result = $this->invokePrivateMethod(
			methodName: 'getTypeFromPatterns',
			parameters: [['float_string']]
		);

		$this->assertEquals('number', $result, 'Float pattern should return number type.');
	}

	/**
	 * Test getTypeFromPatterns with no matching patterns.
	 *
	 * @return void
	 */
	public function testGetTypeFromPatternsWithNoMatch(): void
	{
		$result = $this->invokePrivateMethod(
			methodName: 'getTypeFromPatterns',
			parameters: [['url', 'http']]
		);

		$this->assertNull($result, 'Non-matching patterns should return null.');
	}

	// ==================== normalizeSingleType() Tests ====================

	/**
	 * Test normalizeSingleType with integer string pattern.
	 *
	 * @return void
	 */
	public function testNormalizeSingleTypeWithIntegerString(): void
	{
		$result = $this->invokePrivateMethod(
			methodName: 'normalizeSingleType',
			parameters: ['string', ['integer_string']]
		);

		$this->assertEquals('integer', $result, 'String with integer pattern should normalize to integer.');
	}

	/**
	 * Test normalizeSingleType with float string pattern.
	 *
	 * @return void
	 */
	public function testNormalizeSingleTypeWithFloatString(): void
	{
		$result = $this->invokePrivateMethod(
			methodName: 'normalizeSingleType',
			parameters: ['string', ['float_string']]
		);

		$this->assertEquals('number', $result, 'String with float pattern should normalize to number.');
	}

	/**
	 * Test normalizeSingleType with boolean string pattern.
	 *
	 * @return void
	 */
	public function testNormalizeSingleTypeWithBooleanString(): void
	{
		$result = $this->invokePrivateMethod(
			methodName: 'normalizeSingleType',
			parameters: ['string', ['boolean_string']]
		);

		$this->assertEquals('boolean', $result, 'String with boolean pattern should normalize to boolean.');
	}

	/**
	 * Test normalizeSingleType normalizes double to number.
	 *
	 * @return void
	 */
	public function testNormalizeSingleTypeWithDouble(): void
	{
		$result = $this->invokePrivateMethod(
			methodName: 'normalizeSingleType',
			parameters: ['double', []]
		);

		$this->assertEquals('number', $result, 'Double should normalize to number.');
	}

	/**
	 * Test normalizeSingleType normalizes NULL to null.
	 *
	 * @return void
	 */
	public function testNormalizeSingleTypeWithNull(): void
	{
		$result = $this->invokePrivateMethod(
			methodName: 'normalizeSingleType',
			parameters: ['NULL', []]
		);

		$this->assertEquals('null', $result, 'NULL should normalize to null.');
	}

	/**
	 * Test normalizeSingleType preserves standard types.
	 *
	 * @return void
	 */
	public function testNormalizeSingleTypePreservesStandardTypes(): void
	{
		$standardTypes = ['string', 'integer', 'number', 'boolean', 'object', 'array'];

		foreach ($standardTypes as $type) {
			$result = $this->invokePrivateMethod(
				methodName: 'normalizeSingleType',
				parameters: [$type, []]
			);

			$this->assertEquals($type, $result, "Standard type '{$type}' should be preserved.");
		}
	}

	// ==================== getDominantType() Tests ====================

	/**
	 * Test getDominantType with clear majority.
	 *
	 * @return void
	 */
	public function testGetDominantTypeWithClearMajority(): void
	{
		$types = ['string', 'string', 'string', 'integer', 'integer'];

		$result = $this->invokePrivateMethod(
			methodName: 'getDominantType',
			parameters: [$types, []]
		);

		$this->assertEquals('string', $result, 'Dominant type should be string.');
	}

	/**
	 * Test getDominantType with mixed types favors string.
	 *
	 * @return void
	 */
	public function testGetDominantTypeWithMixedTypes(): void
	{
		$types = ['string', 'integer', 'boolean', 'string'];

		$result = $this->invokePrivateMethod(
			methodName: 'getDominantType',
			parameters: [$types, []]
		);

		$this->assertEquals('string', $result, 'String should be the dominant type.');
	}

	/**
	 * Test getDominantType with numeric types.
	 *
	 * @return void
	 */
	public function testGetDominantTypeWithNumericTypes(): void
	{
		$types = ['integer', 'integer', 'number', 'integer'];

		$result = $this->invokePrivateMethod(
			methodName: 'getDominantType',
			parameters: [$types, []]
		);

		$this->assertEquals('integer', $result, 'Integer should be the dominant numeric type.');
	}

	/**
	 * Test getDominantType with single type.
	 *
	 * @return void
	 */
	public function testGetDominantTypeWithSingleType(): void
	{
		$types = ['object', 'object', 'object'];

		$result = $this->invokePrivateMethod(
			methodName: 'getDominantType',
			parameters: [$types, []]
		);

		$this->assertEquals('object', $result, 'Single type should be dominant.');
	}

	/**
	 * Test getDominantType with boolean patterns.
	 *
	 * @return void
	 */
	public function testGetDominantTypeWithBooleanPatterns(): void
	{
		$types = ['string', 'string'];
		$patterns = ['boolean_string'];

		$result = $this->invokePrivateMethod(
			methodName: 'getDominantType',
			parameters: [$types, $patterns]
		);

		$this->assertEquals('boolean', $result, 'Boolean pattern should convert strings to boolean.');
	}
}











