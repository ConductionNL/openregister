<?php

declare(strict_types=1);

/**
 * ValidateObject and a relation `$ref` on any property
 *
 * A `$ref` that names a schema records a relation. A nested object may name
 * its own schema at any depth; the reference must never reach Opis, which
 * cannot resolve it and would fail the write.
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
use Opis\JsonSchema\Exceptions\UnresolvedReferenceException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Controls for relation references, self-references included, through the real validator.
 */
class ValidateObjectRelationRefTest extends TestCase {
	private const SELF = '#/components/schemas/person';

	/**
	 * Every shape that carries a relation `$ref` on the way to Opis.
	 *
	 * @return array<string, array{0: array, 1: array}>
	 */
	public static function relationShapes(): array {
		$nested = ['type' => 'object', '$ref' => self::SELF, 'objectConfiguration' => ['handling' => 'nested-object']];
		$other = array_merge($nested, ['$ref' => '#/components/schemas/address']);
		$bySlug = array_merge($nested, ['$ref' => 'person']);
		$value = ['name' => 'Ada'];

		return [
			'self reference below the top level' => [
				['home' => ['type' => 'object', 'properties' => ['owner' => $nested]]],
				['home' => ['owner' => $value]],
			],
			'self reference below a nested object' => [
				['home' => ['type' => 'object', 'objectConfiguration' => ['handling' => 'nested-object'], 'properties' => ['owner' => $nested]]],
				['home' => ['owner' => $value]],
			],
			'another schema' => [['home' => $other], ['home' => $value]],
			'another schema below the top level' => [
				['home' => ['type' => 'object', 'properties' => ['owner' => $other]]],
				['home' => ['owner' => $value]],
			],
			'a slug' => [['parent' => $bySlug], ['parent' => $value]],
			'no handling' => [['parent' => ['type' => 'object', '$ref' => 'address']], ['parent' => $value]],
			'no type' => [['parent' => ['$ref' => '#/components/schemas/address']], ['parent' => $value]],
			'a number' => [['size' => ['type' => 'number', '$ref' => 'measure']], ['size' => 3]],
			'an array' => [['tags' => ['type' => 'array', '$ref' => 'tag']], ['tags' => ['a']]],
		];
	}

	/**
	 * A relation reference on a property never fails the write.
	 *
	 * @param array $properties The schema's properties.
	 * @param array $object     The object to validate.
	 *
	 * @return void
	 */
	#[DataProvider('relationShapes')]
	public function testARelationReferenceDoesNotReachTheValidator(array $properties, array $object): void {
		$this->assertTrue($this->validate(properties: $properties, object: $object)->isValid());
	}

	/**
	 * The property keeps its own type once its reference is gone.
	 *
	 * @return void
	 */
	public function testThePropertyStillAnswersToItsType(): void {
		$properties = ['size' => ['type' => 'object', '$ref' => 'measure']];

		$this->assertFalse($this->validate(properties: $properties, object: ['size' => 3])->isValid());
	}

	/**
	 * A `$ref` that may be a real JSON Schema reference stays and keeps constraining the data.
	 *
	 * @return void
	 */
	public function testAPointerIntoTheSchemaStillResolves(): void {
		$properties = ['size' => ['type' => 'number'], 'other' => ['$ref' => '#/properties/size']];

		$this->assertFalse($this->validate(properties: $properties, object: ['other' => 'wide'])->isValid());
	}

	/**
	 * References that are no slug and no component schema.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function unknownReferences(): array {
		return [
			'a pointer' => ['#/definitions/size'],
			'an anchor' => ['#size'],
			'a path' => ['schemas/size'],
		];
	}

	/**
	 * A reference that is neither a relation nor resolvable still names itself in the failure.
	 *
	 * @param string $reference The `$ref`.
	 *
	 * @return void
	 */
	#[DataProvider('unknownReferences')]
	public function testAnUnknownReferenceStillFails(string $reference): void {
		$this->expectException(UnresolvedReferenceException::class);

		$this->validate(properties: ['other' => ['$ref' => $reference]], object: ['other' => 1]);
	}

	/**
	 * A string property drops any `$ref`, as it holds the identifier itself.
	 *
	 * @return void
	 */
	public function testAStringPropertyDropsAnyReference(): void {
		$properties = ['other' => ['type' => 'string', '$ref' => '#/definitions/size']];

		$this->assertTrue($this->validate(properties: $properties, object: ['other' => 'a'])->isValid());
	}

	/**
	 * Validate an object against properties of the schema `person`.
	 *
	 * @param array $properties The schema's properties.
	 * @param array $object     The object to validate.
	 *
	 * @return \Opis\JsonSchema\ValidationResult
	 */
	private function validate(array $properties, array $object): \Opis\JsonSchema\ValidationResult {
		$schema = new Schema();
		$schema->setSlug('person');
		$schema->setTitle('Person');
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getBaseUrl')->willReturn('http://localhost:8080');
		$handler = new ValidateObject(
			$this->createMock(IAppConfig::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(SchemaMapper::class),
			$urls,
			$this->createMock(LoggerInterface::class),
			$this->createMock(IUserManager::class)
		);

		return $handler->validateObject(
			object: $object,
			schema: $schema,
			schemaObject: json_decode((string)json_encode(['type' => 'object', 'properties' => $properties]))
		);
	}
}
