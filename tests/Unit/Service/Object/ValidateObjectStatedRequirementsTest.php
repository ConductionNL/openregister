<?php

/**
 * A refused save names the field and the rule; object-typed properties are
 * validated against their own schema (REQ-MSR-001, REQ-MSR-002).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/modelling-stated-requirements/specs/objects-crud/spec.md#REQ-MSR-001
 * @spec openspec/changes/modelling-stated-requirements/specs/objects-crud/spec.md#REQ-MSR-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\Object\ValidationLookups;
use OCA\OpenRegister\Service\Object\ValidationSchemaSource;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Validation through ValidateObject::validateObject(), messages through generateErrorMessage().
 *
 * @covers \OCA\OpenRegister\Service\Object\ValidateObject
 * @covers \OCA\OpenRegister\Service\Object\ValidationErrorMessages
 */
class ValidateObjectStatedRequirementsTest extends TestCase {

	/**
	 * The validator under test.
	 *
	 * @var ValidateObject
	 */
	private ValidateObject $validator;

	/**
	 * Set up the validator with its collaborators mocked at the boundary.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getBaseUrl')->willReturn('http://localhost:8080');

		$this->validator = new ValidateObject(
			schemas: new ValidationSchemaSource(config: $this->createMock(IAppConfig::class), schemaMapper: $this->createMock(SchemaMapper::class), urlGenerator: $urlGenerator),
			lookups: new ValidationLookups(objectMapper: $this->createMock(MagicMapper::class), userManager: $this->createMock(IUserManager::class)),
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * The `melding` schema of the scenarios, as a JSON Schema object.
	 *
	 * @return object
	 */
	private static function meldingSchema(): object {
		return json_decode(
			'{"type":"object","required":["omschrijving"],"properties":{'
			.'"omschrijving":{"type":"string","minLength":3,"maxLength":10},'
			.'"aantal":{"type":"integer"},'
			.'"mail":{"type":"string","format":"email"},'
			.'"status":{"type":"string","enum":["open","dicht"]},'
			.'"tags":{"type":"array","minItems":1,"maxItems":2},'
			.'"adres":{"type":"object","properties":{"postcode":{"type":"string","pattern":"^[1-9][0-9]{3}[A-Z]{2}$"}}},'
			.'"contactgegevens":{"type":"object","required":["email"],"properties":{"email":{"type":"string"}}},'
			.'"vrij":{"type":"object"}}}'
		);
	}//end meldingSchema()

	/**
	 * Validate an object against the melding schema.
	 *
	 * @param array<string, mixed> $object The object.
	 *
	 * @return array{valid: bool, message: string}
	 */
	private function validate(array $object): array {
		$schema = new Schema();
		$schema->setSlug('melding');
		$schema->setTitle('Melding');

		$result = $this->validator->validateObject($object, $schema, self::meldingSchema());
		if ($result->isValid() === true) {
			return ['valid' => true, 'message' => ''];
		}

		return ['valid' => false, 'message' => $this->validator->generateErrorMessage(result: $result)];
	}//end validate()

	/**
	 * One broken rule per case: the object, then the exact message naming the path and the rule.
	 * An optional property is widened to accept null, hence `integer or null`.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function brokenRules(): array {
		$ok = ['omschrijving' => 'Lamp kapot'];

		return [
			'required' => [['aantal' => 1], 'The required property (omschrijving) is missing. Please provide a value for this property or set it to null if allowed.'],
			'type' => [$ok + ['aantal' => 'drie'], "Property 'aantal' should be type 'integer or null' but is 'string'. Please provide a value of the correct type."],
			'format' => [$ok + ['mail' => 'nope'], "Property 'mail' should match format 'email' but 'nope' does not. Please provide a value in the correct format."],
			'enum' => [$ok + ['status' => 'x'], "Property 'status' should be one of: 'open', 'dicht', but is 'x'. Please choose one of the allowed values."],
			'length' => [['omschrijving' => 'ab'], "Property 'omschrijving' requires at least 3 chars, has 2. Please provide a longer string value."],
			'item count' => [$ok + ['tags' => [1, 2, 3]], "Property 'tags' allows at most 2 items, has 3. Please remove some items from the array."],
			'nested pattern' => [
				$ok + ['adres' => ['postcode' => '12AB']],
				"Property 'adres.postcode' should match pattern '^[1-9][0-9]{3}[A-Z]{2}$' but '12AB' does not. Please provide a value that matches the required pattern.",
			],
		];
	}//end brokenRules()

	/**
	 * A refused save names the property path and the rule it broke (REQ-MSR-001).
	 *
	 * @param array<string, mixed> $object  The object.
	 * @param string               $message The expected message.
	 *
	 * @return void
	 */
	#[DataProvider('brokenRules')]
	public function testARefusedSaveNamesTheFieldAndTheRule(array $object, string $message): void {
		$this->assertSame(['valid' => false, 'message' => $message], $this->validate(object: $object));
	}//end testARefusedSaveNamesTheFieldAndTheRule()

	/**
	 * An object-typed property is checked against its own schema, and a missing
	 * nested key is named by its full path (REQ-MSR-002).
	 *
	 * @return void
	 */
	public function testANestedRequiredKeyIsNamedByItsPath(): void {
		$this->assertSame(
			[
				'valid' => false,
				'message' => 'The required property (contactgegevens.email) is missing. Please provide a value for this property or set it to null if allowed.',
			],
			$this->validate(object: ['omschrijving' => 'Lamp kapot', 'contactgegevens' => ['telefoon' => '0612345678']])
		);
	}//end testANestedRequiredKeyIsNamedByItsPath()

	/**
	 * An object-typed property without its own schema accepts any JSON object (REQ-MSR-002).
	 *
	 * @return void
	 */
	public function testAFreeObjectPropertyAcceptsAnyObject(): void {
		$this->assertSame(
			['valid' => true, 'message' => ''],
			$this->validate(object: ['omschrijving' => 'Lamp kapot', 'vrij' => ['a' => [1, 2], 'b' => ['c' => true]]])
		);
	}//end testAFreeObjectPropertyAcceptsAnyObject()

	/**
	 * A conforming object, nested values included, passes.
	 *
	 * @return void
	 */
	public function testAConformingObjectPasses(): void {
		$this->assertTrue(
			$this->validate(
				object: [
					'omschrijving' => 'Lamp kapot',
					'aantal' => 2,
					'status' => 'open',
					'adres' => ['postcode' => '1234AB'],
					'contactgegevens' => ['email' => 'jan@example.nl'],
				]
			)['valid']
		);
	}//end testAConformingObjectPasses()
}//end class
