<?php

/**
 * A save holding an email or web address that does not match its format is
 * refused, and the refusal names the property.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Object
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/changes/capability-rows-verified-a/specs/objects-crud/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

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
 * @covers \OCA\OpenRegister\Service\Object\ValidateObject
 * @uses   \OCA\OpenRegister\Db\MagicMapper\StatisticsScope
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Formats\FormatFactory
 * @uses   \OCA\OpenRegister\Formats\UserFormat
 * @uses   \OCA\OpenRegister\Kernel\Contracts\ListQuery
 * @uses   \OCA\OpenRegister\Service\Object\ExtendedFieldCheck
 * @uses   \OCA\OpenRegister\Service\Object\RefLoopGuardParser
 * @uses   \OCA\OpenRegister\Service\Object\ValidationErrorMessages
 * @uses   \OCA\OpenRegister\Service\Object\ValidationLookups
 * @uses   \OCA\OpenRegister\Service\Object\ValidationSchemaSource
 */
class ValidateObjectFormatRefusalTest extends TestCase {

	/**
	 * The validator under test.
	 *
	 * @var ValidateObject
	 */
	private ValidateObject $validator;

	/**
	 * Build the validator over stand-in stores.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getBaseUrl')->willReturn('http://localhost:8080');

		$this->validator = new ValidateObject(
			schemas: new ValidationSchemaSource(
				config: $this->createMock(IAppConfig::class),
				schemaMapper: $this->createMock(SchemaMapper::class),
				urlGenerator: $urlGenerator
			),
			lookups: new ValidationLookups(objectMapper: $this->createMock(MagicMapper::class), userManager: $this->createMock(IUserManager::class)),
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * The schema entity the objects are saved against.
	 *
	 * @return Schema
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setSlug('contact');
		$schema->setTitle('Contact');
		return $schema;
	}//end schema()

	/**
	 * A schema object with one string property of the given format.
	 *
	 * @param string $property The property name.
	 * @param string $format   Its format.
	 *
	 * @return object
	 */
	private function formatSchema(string $property, string $format): object {
		return json_decode(
			json_encode(
				[
					'type' => 'object',
					'properties' => [
						$property => ['type' => 'string', 'format' => $format],
					],
				]
			)
		);
	}//end formatSchema()

	/**
	 * The two checked formats, each with a value that matches and one that does not.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
	 */
	public static function formats(): array {
		return [
			'email' => ['email', 'email', 'jan@example.nl', 'jan-at-example'],
			'web address' => ['website', 'uri', 'https://example.nl/contact', 'not a web address'],
		];
	}//end formats()

	/**
	 * A wrong value is refused with a message that names the property; a
	 * right one is accepted.
	 *
	 * @param string $property The property.
	 * @param string $format   Its format.
	 * @param string $valid    A value matching the format.
	 * @param string $invalid  A value that does not.
	 *
	 * @return void
	 */
	#[DataProvider('formats')]
	public function testAValueNotMatchingItsFormatIsRefusedByName(string $property, string $format, string $valid, string $invalid): void {
		$schemaObject = $this->formatSchema(property: $property, format: $format);

		$refused = $this->validator->validateObject([$property => $invalid], $this->schema(), $schemaObject);
		$accepted = $this->validator->validateObject([$property => $valid], $this->schema(), $schemaObject);

		$this->assertFalse($refused->isValid());
		$this->assertStringContainsString($property, $this->validator->generateErrorMessage($refused));
		$this->assertTrue($accepted->isValid());
	}//end testAValueNotMatchingItsFormatIsRefusedByName()
}//end class
