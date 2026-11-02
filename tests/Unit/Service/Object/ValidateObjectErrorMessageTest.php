<?php

/**
 * OpenRegister - the message ValidateObject writes for a validation error.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\ValidateObject;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Info\DataInfo;
use Opis\JsonSchema\Info\SchemaInfo;
use Opis\JsonSchema\Schema;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins the message for every keyword the formatter knows, its edge cases, and
 * errors Opis produces itself.
 *
 * Expected values live in `fixtures/validation-error-messages.php`, one entry per case.
 */
class ValidateObjectErrorMessageTest extends TestCase {

	/**
	 * Hand-built errors: keyword, data path, value, args, schema data and sub-errors.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function errorCases(): array {
		$enumSchema = (object)['enum' => ['open', 'closed']];
		$typeError  = ['keyword' => 'type', 'path' => ['a', 'b'], 'value' => 5, 'args' => ['expected' => 'string']];

		return [
			'required one'                => ['keyword' => 'required', 'args' => ['missing' => ['title']]],
			'required many'               => ['keyword' => 'required', 'args' => ['missing' => ['title', 'status', 'owner']]],
			'required none'               => ['keyword' => 'required', 'args' => ['missing' => []]],
			'required not a list'         => ['keyword' => 'required', 'args' => ['missing' => 'title']],
			'required no args'            => ['keyword' => 'required'],
			'type'                        => $typeError,
			'type at root'                => ['keyword' => 'type', 'value' => 'x', 'args' => ['expected' => 'object']],
			'type list'                   => ['keyword' => 'type', 'path' => ['p'], 'value' => 1.5, 'args' => ['expected' => ['string', 'null']]],
			'type no expected'            => ['keyword' => 'type', 'path' => ['p'], 'value' => true],
			'type empty object'           => ['keyword' => 'type', 'path' => ['p'], 'value' => [], 'args' => ['expected' => 'object']],
			'type empty array'            => ['keyword' => 'type', 'path' => ['p'], 'value' => [], 'args' => ['expected' => 'array']],
			'type empty string'           => ['keyword' => 'type', 'path' => ['p'], 'value' => '', 'args' => ['expected' => 'string']],
			'type object for array'       => ['keyword' => 'type', 'path' => ['p'], 'value' => (object)['a' => 1], 'args' => ['expected' => 'array']],
			'type filled list for object' => ['keyword' => 'type', 'path' => ['p'], 'value' => [1], 'args' => ['expected' => 'object']],
			'type null'                   => ['keyword' => 'type', 'path' => [0, 'p'], 'value' => null, 'args' => ['expected' => 'integer']],
			'minItems'                    => ['keyword' => 'minItems', 'path' => ['tags'], 'value' => [1], 'args' => ['min' => 2]],
			'minItems not a list'         => ['keyword' => 'minItems', 'path' => ['tags'], 'value' => 'x'],
			'maxItems'                    => ['keyword' => 'maxItems', 'path' => ['tags'], 'value' => [1, 2, 3], 'args' => ['max' => 2]],
			'maxItems not a list'         => ['keyword' => 'maxItems', 'path' => ['tags'], 'value' => null],
			'format'                      => ['keyword' => 'format', 'path' => ['mail'], 'value' => 'nope', 'args' => ['format' => 'email']],
			'format no args'              => ['keyword' => 'format', 'path' => ['mail'], 'value' => 'nope'],
			'minLength empty'             => ['keyword' => 'minLength', 'path' => ['t'], 'value' => '', 'args' => ['min' => 3]],
			'minLength short'             => ['keyword' => 'minLength', 'path' => ['t'], 'value' => 'ab', 'args' => ['min' => 3]],
			'minLength not a string'      => ['keyword' => 'minLength', 'path' => ['t'], 'value' => 12],
			'maxLength'                   => ['keyword' => 'maxLength', 'path' => ['t'], 'value' => 'abcdef', 'args' => ['max' => 3]],
			'maxLength not a string'      => ['keyword' => 'maxLength', 'path' => ['t'], 'value' => 12],
			'minimum'                     => ['keyword' => 'minimum', 'path' => ['n'], 'value' => 1, 'args' => ['min' => 5]],
			'minimum no args'             => ['keyword' => 'minimum', 'path' => ['n'], 'value' => -1],
			'maximum'                     => ['keyword' => 'maximum', 'path' => ['n'], 'value' => 9.5, 'args' => ['max' => 5]],
			'maximum no args'             => ['keyword' => 'maximum', 'path' => ['n'], 'value' => 9],
			'enum from args'              => ['keyword' => 'enum', 'path' => ['s'], 'value' => 'x', 'args' => ['values' => ['a', 'b']]],
			'enum from schema'            => ['keyword' => 'enum', 'path' => ['s'], 'value' => 'sent', 'schema' => $enumSchema],
			'enum schema without enum'    => ['keyword' => 'enum', 'path' => ['s'], 'value' => 'sent', 'schema' => (object)['type' => 'string']],
			'enum schema enum not a list' => ['keyword' => 'enum', 'path' => ['s'], 'value' => 'sent', 'schema' => (object)['enum' => 'open']],
			'enum schema not an object'   => ['keyword' => 'enum', 'path' => ['s'], 'value' => 'sent', 'schema' => true],
			'enum args not a list'        => ['keyword' => 'enum', 'path' => ['s'], 'value' => 'sent', 'args' => ['values' => 'a']],
			'pattern'                     => ['keyword' => 'pattern', 'path' => ['c'], 'value' => 'x1', 'args' => ['pattern' => '^[a-z]+$']],
			'pattern no args'             => ['keyword' => 'pattern', 'path' => ['c'], 'value' => 'x1'],
			'unknown keyword'             => ['keyword' => 'multipleOf', 'path' => ['n'], 'value' => 7],
			'unknown keyword at root'     => ['keyword' => 'const'],
			'sub-error used'              => ['keyword' => 'properties', 'path' => [], 'sub' => [$typeError, ['keyword' => 'required']]],
			'nested sub-errors'           => [
				'keyword' => 'anyOf',
				'sub'     => [['keyword' => 'allOf', 'sub' => [['keyword' => 'minimum', 'path' => ['x'], 'value' => 0, 'args' => ['min' => 1]]]]],
			],
		];
	}//end errorCases()

	/**
	 * Schemas and data Opis validates itself.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function opisCases(): array {
		return [
			'opis required'     => ['{"type":"object","required":["a","b"]}', '{}'],
			'opis nested type'  => ['{"type":"object","properties":{"a":{"type":"object","properties":{"b":{"type":"integer"}}}}}', '{"a":{"b":"x"}}'],
			'opis enum'         => ['{"type":"object","properties":{"s":{"enum":["open","closed"]}}}', '{"s":"sent"}'],
			'opis format'       => ['{"type":"object","properties":{"d":{"type":"string","format":"date"}}}', '{"d":"yesterday"}'],
			'opis items'        => ['{"type":"array","items":{"type":"string"},"minItems":1}', '[1]'],
			'opis min items'    => ['{"type":"array","minItems":2}', '[1]'],
			'opis one of'       => ['{"oneOf":[{"type":"string"},{"type":"integer"}]}', '1.5'],
			'opis multiple of'  => ['{"type":"number","multipleOf":2}', '3'],
			'opis valid'        => ['{"type":"string"}', '"fine"'],
		];
	}//end opisCases()

	/**
	 * Every case paired with its expected message.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
	 */
	public static function casesWithExpectations(): array {
		$expected = require __DIR__.'/fixtures/validation-error-messages.php';
		$pairs    = [];
		foreach (self::errorCases() as $name => $case) {
			$pairs[$name] = ['error', $case, ($expected[$name] ?? 'missing expectation')];
		}

		foreach (self::opisCases() as $name => $case) {
			$pairs[$name] = ['opis', $case, ($expected[$name] ?? 'missing expectation')];
		}

		return $pairs;
	}//end casesWithExpectations()

	/**
	 * The formatter writes the recorded message.
	 *
	 * @param string $kind     `error` or `opis`
	 * @param array  $case     The case
	 * @param string $expected The recorded message
	 *
	 * @return void
	 */
	#[DataProvider('casesWithExpectations')]
	public function testErrorGetsTheRecordedMessage(string $kind, array $case, string $expected): void {
		$this->assertSame(expected: $expected, actual: $this->runCase(kind: $kind, case: $case));
	}//end testErrorGetsTheRecordedMessage()

	/**
	 * Every case has a recorded expectation and every expectation a case.
	 *
	 * @return void
	 */
	public function testEveryCaseIsRecorded(): void {
		$expected = require __DIR__.'/fixtures/validation-error-messages.php';
		$names    = array_merge(array_keys(self::errorCases()), array_keys(self::opisCases()));
		$this->assertSame(expected: $names, actual: array_keys($expected));
	}//end testEveryCaseIsRecorded()

	/**
	 * The message for one case.
	 *
	 * @param string $kind `error` or `opis`
	 * @param array  $case The case
	 *
	 * @return string
	 */
	public function runCase(string $kind, array $case): string {
		$validator = (new ReflectionClass(objectOrClass: ValidateObject::class))->newInstanceWithoutConstructor();

		if ($kind === 'opis') {
			$result = (new Validator())->validate(json_decode($case[1]), $case[0]);
			return $validator->generateErrorMessage(result: $result);
		}

		return $validator->generateErrorMessage(result: new ValidationResult($this->error(case: $case)));
	}//end runCase()

	/**
	 * A validation error built from a case.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return ValidationError
	 */
	private function error(array $case): ValidationError {
		$schema = $this->createMock(originalClassName: Schema::class);
		$schema->method('info')->willReturn(new SchemaInfo(($case['schema'] ?? (object)[]), null));

		$path = ($case['path'] ?? []);
		$data = new DataInfo(($case['value'] ?? null), null, null, $path);

		$subErrors = [];
		foreach (($case['sub'] ?? []) as $sub) {
			$subErrors[] = $this->error(case: $sub);
		}

		return new ValidationError($case['keyword'], $schema, $data, 'message', ($case['args'] ?? []), $subErrors);
	}//end error()
}//end class
