<?php

/**
 * Golden record of ValidateObject::validateObject() over the values it drops before validating.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\Object\ValidationLookups;
use OCA\OpenRegister\Service\Object\ValidationSchemaSource;
use Opis\JsonSchema\Errors\ErrorFormatter;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Object\ValidateObject
 *
 * @spec openspec/specs/object-validation/spec.md
 * @uses \OCA\OpenRegister\Formats\UserFormat
 */
class ValidateObjectValueFilterGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/validate-object-value-filter-golden.json';

	private static function schemaObject(): array {
		return [
			'type' => 'object',
			'required' => ['title'],
			'additionalProperties' => false,
			'properties' => [
				'title' => ['type' => 'string', 'minLength' => 1],
				'note' => ['type' => 'string', 'minLength' => 1],
				'status' => ['type' => 'string', 'enum' => ['open', 'closed']],
				'phase' => ['type' => ['string', 'null'], 'enum' => ['a', null]],
				'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
				'atLeastOne' => ['type' => 'array', 'minItems' => 1],
				'atMostTwo' => ['type' => 'array', 'maxItems' => 2],
				'unique' => ['type' => 'array', 'uniqueItems' => true],
				'zeroMin' => ['type' => 'array', 'minItems' => 0],
				'count' => ['type' => 'integer'],
				'flag' => ['type' => 'boolean'],
			],
		];
	}//end schemaObject()

	public static function cases(): array {
		$cases = [
			'valid' => ['title' => 'T'],
			'required empty string kept and fails' => ['title' => ''],
			'required missing' => ['note' => 'n'],
			'optional empty string dropped' => ['title' => 'T', 'note' => ''],
			'enum null dropped' => ['title' => 'T', 'status' => null],
			'enum null allowed kept' => ['title' => 'T', 'phase' => null],
			'enum bad value' => ['title' => 'T', 'status' => 'x'],
			'empty array dropped' => ['title' => 'T', 'tags' => []],
			'empty array with minItems kept' => ['title' => 'T', 'atLeastOne' => []],
			'empty array with maxItems kept' => ['title' => 'T', 'atMostTwo' => []],
			'empty array with uniqueItems kept' => ['title' => 'T', 'unique' => []],
			'empty array with minItems zero dropped' => ['title' => 'T', 'zeroMin' => []],
			'unknown empty array dropped' => ['title' => 'T', 'other' => []],
			'unknown empty string dropped' => ['title' => 'T', 'other' => ''],
			'null non-enum kept' => ['title' => 'T', 'count' => null],
			'zero and false kept' => ['title' => 'T', 'count' => 0, 'flag' => false],
			'wrong type' => ['title' => 'T', 'count' => 'x'],
			'extend and filters removed' => ['title' => 'T', 'extend' => 5, 'filters' => 'x'],
		];

		$out = [];
		foreach ($cases as $name => $case) {
			$out[$name] = [$case];
		}

		return $out;
	}//end cases()

	private function validator(): ValidateObject {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getBaseUrl')->willReturn('http://localhost:8080');
		return new ValidateObject(
			schemas: new ValidationSchemaSource(config: $this->createMock(IAppConfig::class), schemaMapper: $this->createMock(SchemaMapper::class), urlGenerator: $urlGenerator),
			lookups: new ValidationLookups(objectMapper: $this->createMock(MagicMapper::class), userManager: $this->createMock(IUserManager::class)),
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end validator()

	#[DataProvider('cases')]
	public function testValidationMatchesGolden(array $object): void {
		$schemaObject = json_decode((string)json_encode(self::schemaObject()));
		$result = $this->validator()->validateObject($object, null, $schemaObject);
		$errors = [];
		if ($result->isValid() === false) {
			$errors = (new ErrorFormatter())->format($result->error(), false);
		}

		$actual = ['valid' => $result->isValid(), 'errors' => $errors];
		$this->record(actual: $actual);
	}//end testValidationMatchesGolden()

	public function testAnEmptySchemaAcceptsAnything(): void {
		$result = $this->validator()->validateObject(['anything' => ''], null, json_decode('{"type":"object","properties":{}}'));
		$this->record(actual: ['valid' => $result->isValid()]);
	}//end testAnEmptySchemaAcceptsAnything()

	private function record(array $actual): void {
		$name = $this->name() . ':' . $this->dataName();
		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$actual = json_decode((string)json_encode($actual), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end record()
}//end class
