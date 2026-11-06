<?php

declare(strict_types=1);

/**
 * ValidateObject and a reference that comes back to itself
 *
 * A reference that never moves through the data has no end. It must finish and
 * add no constraint, while a reference that descends keeps validating.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use Opis\JsonSchema\ValidationResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Controls for reference loops, through the real validator.
 */
class ValidateObjectRefLoopTest extends TestCase {
	/**
	 * Every way a reference comes back to itself at one place in the data.
	 *
	 * @return array<string, array{0: array}>
	 */
	public static function loops(): array {
		$self = ['$ref' => '#/properties/a'];

		return [
			'itself' => [['a' => $self]],
			'a nested object' => [['a' => ['type' => 'object', '$ref' => '#/properties/a', 'objectConfiguration' => ['handling' => 'nested-object']]]],
			'through another property' => [['a' => ['$ref' => '#/properties/b'], 'b' => $self]],
			'through allOf' => [['a' => ['allOf' => [$self]]]],
			'through anyOf' => [['a' => ['anyOf' => [$self]]]],
			'through oneOf' => [['a' => ['oneOf' => [$self, ['type' => 'string']]]]],
			'through not' => [['a' => ['not' => $self]]],
		];
	}

	/**
	 * A loop finishes.
	 *
	 * @param array $properties The schema's properties.
	 *
	 * @return void
	 */
	#[DataProvider('loops')]
	public function testALoopEnds(array $properties): void {
		$this->assertInstanceOf(
			ValidationResult::class,
			$this->handler()->validateObject(object: ['a' => ['x' => 1]], schema: $this->schema(), schemaObject: $this->schemaObject($properties))
		);
	}

	/**
	 * A constraint next to the loop still holds.
	 *
	 * @return void
	 */
	public function testAConstraintBesideALoopStillHolds(): void {
		$properties = ['a' => ['allOf' => [['$ref' => '#/properties/a'], ['type' => 'object']]]];

		$this->assertFalse($this->validate(properties: $properties, object: ['a' => 'text'])->isValid());
	}

	/**
	 * A reference that descends keeps validating every level.
	 *
	 * @return void
	 */
	public function testRecursionThroughTheDataStillValidatesEachLevel(): void {
		$properties = [
			'node' => [
				'type' => 'object',
				'properties' => ['size' => ['type' => 'number'], 'next' => ['$ref' => '#/properties/node']],
			],
		];

		$valid = ['node' => ['size' => 1, 'next' => ['size' => 2, 'next' => ['size' => 3]]]];
		$invalid = ['node' => ['size' => 1, 'next' => ['size' => 2, 'next' => ['size' => 'wide']]]];

		$this->assertTrue($this->validate(properties: $properties, object: $valid)->isValid());
		$this->assertFalse($this->validate(properties: $properties, object: $invalid)->isValid());
	}

	/**
	 * Two properties sharing one target are each checked, and so is a second object.
	 *
	 * @return void
	 */
	public function testAReferenceIsAskedAgainAtEveryPlace(): void {
		$properties = ['size' => ['type' => 'number'], 'a' => ['$ref' => '#/properties/size'], 'b' => ['$ref' => '#/properties/size']];
		$handler = $this->handler();
		$schema = $this->schema();
		$validate = fn (array $object): bool => $handler->validateObject(
			object: $object,
			schema: $schema,
			schemaObject: $this->schemaObject($properties)
		)->isValid();

		$this->assertFalse($validate(['a' => 1, 'b' => 'wide']));
		$this->assertFalse($validate(['a' => 'wide', 'b' => 1]));
		$this->assertTrue($validate(['a' => 1, 'b' => 2]));
	}

	/**
	 * Validate an object against properties.
	 *
	 * @param array $properties The schema's properties.
	 * @param array $object     The object to validate.
	 *
	 * @return ValidationResult
	 */
	private function validate(array $properties, array $object): ValidationResult {
		return $this->handler()->validateObject(object: $object, schema: $this->schema(), schemaObject: $this->schemaObject($properties));
	}

	/**
	 * A schema object with these properties.
	 *
	 * @param array $properties The schema's properties.
	 *
	 * @return object
	 */
	private function schemaObject(array $properties): object {
		return json_decode((string)json_encode(['type' => 'object', 'properties' => $properties]));
	}

	/**
	 * A schema entity.
	 *
	 * @return Schema
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setSlug('person');
		$schema->setTitle('Person');
		return $schema;
	}

	/**
	 * The handler with mocked collaborators.
	 *
	 * @return ValidateObject
	 */
	private function handler(): ValidateObject {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getBaseUrl')->willReturn('http://localhost:8080');

		return new ValidateObject(
			$this->createMock(IAppConfig::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(SchemaMapper::class),
			$urls,
			$this->createMock(LoggerInterface::class),
			$this->createMock(IUserManager::class)
		);
	}
}
