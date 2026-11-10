<?php

/**
 * ValidateObject's write constraints, unique-field check and validation response match the record.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\Object\ValidationLookups;
use OCA\OpenRegister\Service\Object\ValidationSchemaSource;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Throwable;
use OCA\OpenRegister\Service\Object\WriteConstraintCheck;

/**
 * Golden record of validateReadOnlyConstraints() and validateImmutableConstraints() over omitted,
 * equal, changed, loosely equal, null and empty values and odd property definitions; of
 * validateUniqueFields() with no, one and several unique fields, a hit and a miss, recording the
 * filters it counts with and what it throws; and of handleValidationException() for both exception
 * kinds, with and without a formatted error. Set OR_GOLDEN_RECORD=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Object\ValidateObject
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Exception\CustomValidationException
 * @uses   \OCA\OpenRegister\Exception\ValidationException
 */
class ValidateObjectWriteConstraintsGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/validate-object-write-constraints-golden.json';

	/**
	 * Filters the mapper was last asked to count with.
	 *
	 * @var array|null
	 */
	private ?array $counted = null;

	/**
	 * Every scenario matches the recorded golden.
	 *
	 * @return void
	 */
	public function testScenariosMatchTheGolden(): void {
		$actual = [];
		foreach ($this->constraintScenarios() as $name => [$properties, $incoming, $existing]) {
			$schema = new Schema();
			$schema->setProperties($properties);
			$validator = $this->validator(count: 0);
			$actual['readOnly: ' . $name]  = $this->outcome(fn () => $validator->validateReadOnlyConstraints(new WriteConstraintCheck($incoming, $existing), $schema));
			$actual['immutable: ' . $name] = $this->outcome(fn () => $validator->validateImmutableConstraints(new WriteConstraintCheck($incoming, $existing), $schema));
		}

		foreach ($this->uniqueScenarios() as $name => [$unique, $object, $count]) {
			$schema = new Schema();
			$schema->setConfiguration(['unique' => $unique]);
			$this->counted = null;
			$method = new ReflectionMethod(ValidateObject::class, 'validateUniqueFields');
			$validator = $this->validator(count: $count);
			$outcome = $this->outcome(fn () => $method->invoke($validator, $object, $schema));
			$actual['unique: ' . $name] = ['outcome' => $outcome, 'filters' => $this->counted];
		}

		foreach ($this->exceptionScenarios() as $name => $exception) {
			$response = $this->validator(count: 0)->handleValidationException($exception);
			$actual['response: ' . $name] = [$response->getStatus(), $response->getData()];
		}

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(expected: $expected, actual: json_decode((string) json_encode($actual), true));
	}//end testScenariosMatchTheGolden()

	/**
	 * Property definitions, incoming object and stored object.
	 *
	 * @return array<string, array{0: array, 1: array, 2: array}>
	 */
	private function constraintScenarios(): array {
		$both = ['a' => ['readOnly' => true, 'immutable' => true], 'b' => ['type' => 'string'], 'c' => ['readOnly' => true, 'immutable' => true]];
		return [
			'create'              => [$both, ['a' => 1], []],
			'no properties'       => [[], ['a' => 1], ['a' => 2]],
			'none declared'       => [['a' => ['type' => 'string']], ['a' => 1], ['a' => 2]],
			'omitted'             => [$both, ['b' => 'x'], ['a' => 1, 'c' => 2]],
			'equal'               => [$both, ['a' => 1, 'c' => 'z'], ['a' => 1, 'c' => 'z']],
			'changed'             => [$both, ['a' => 2, 'c' => 'y'], ['a' => 1, 'c' => 'z']],
			'second changed'      => [$both, ['a' => 1, 'c' => 'y'], ['a' => 1, 'c' => 'z']],
			'loosely equal'       => [$both, ['a' => '1'], ['a' => 1]],
			'stored null'         => [$both, ['a' => 5], ['a' => null, 'b' => 1]],
			'stored missing'      => [$both, ['a' => 5], ['b' => 1]],
			'stored empty string' => [$both, ['a' => 5], ['a' => '']],
			'stored zero'         => [$both, ['a' => 5], ['a' => 0]],
			'stored false'        => [$both, ['a' => true], ['a' => false]],
			'incoming null'       => [$both, ['a' => null], ['a' => 3]],
			'both null'           => [$both, ['a' => null], ['a' => null, 'b' => 1]],
			'array values'        => [$both, ['a' => [1, 2]], ['a' => [2, 1]]],
			'truthy markers'      => [['a' => ['readOnly' => 1, 'immutable' => 'yes'], 'b' => 'readOnly'], ['a' => 9, 'b' => 9], ['a' => 1, 'b' => 1]],
			'numeric names'       => [[0 => ['readOnly' => true, 'immutable' => true]], [0 => 'n'], [0 => 'o']],
			'only immutable'      => [['a' => ['immutable' => true]], ['a' => 2], ['a' => 1]],
			'only readOnly'       => [['a' => ['readOnly' => true]], ['a' => 2], ['a' => 1]],
		];
	}//end constraintScenarios()

	/**
	 * Unique configuration, object and the count the mapper answers.
	 *
	 * @return array<string, array{0: mixed, 1: array, 2: int}>
	 */
	private function uniqueScenarios(): array {
		return [
			'not configured'      => [null, ['a' => 1], 1],
			'empty list'          => [[], ['a' => 1], 1],
			'empty string'        => ['', ['a' => 1], 1],
			'one field, miss'     => ['a', ['a' => 1], 0],
			'one field, hit'      => ['a', ['a' => 1], 1],
			'one field, null hit' => ['a', ['a' => null], 2],
			'list, miss'          => [['a', 'b'], ['a' => 1, 'b' => 'x'], 0],
			'list, hit'           => [['a', 'b'], ['a' => 1, 'b' => 'x'], 1],
			'list, null value'    => [['a', 'b'], ['a' => 1, 'b' => null], 1],
			'one-item list, hit'  => [['b'], ['b' => 'x'], 3],
			'numeric field, hit'  => [7, [7 => 'v'], 1],
		];
	}//end uniqueScenarios()

	/**
	 * Exceptions handed to handleValidationException().
	 *
	 * @return array<string, Throwable>
	 */
	private function exceptionScenarios(): array {
		$error = (new Validator())->validate(1, (object) ['type' => 'string'])->error();
		return [
			'custom, no errors'      => new CustomValidationException('bad', []),
			'custom, errors'         => new CustomValidationException('bad', ['a' => 'A is bad', 'list' => ['x', 'y']]),
			'validation, no error'   => new ValidationException('read only'),
			'validation, with error' => new ValidationException('Invalid object', 0, null, $error),
		];
	}//end exceptionScenarios()

	/**
	 * What the call returns, or the class, message and errors of what it throws.
	 *
	 * @param callable $call The call.
	 *
	 * @return mixed The recorded outcome.
	 */
	private function outcome(callable $call): mixed {
		try {
			return ['returned' => $call()];
		} catch (CustomValidationException $e) {
			return ['threw' => get_class($e), 'message' => $e->getMessage(), 'errors' => $e->getErrors()];
		} catch (Throwable $e) {
			return ['threw' => get_class($e), 'message' => $e->getMessage()];
		}
	}//end outcome()

	/**
	 * A validator whose mapper answers every count with the given number.
	 *
	 * @param int $count The count to answer.
	 *
	 * @return ValidateObject
	 */
	private function validator(int $count): ValidateObject {
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('countAll')->willReturnCallback(function (?array $_filters = null) use ($count): int {
			$this->counted = $_filters;
			return $count;
		});
		return new ValidateObject(
			schemas: new ValidationSchemaSource(config: $this->createMock(IAppConfig::class), schemaMapper: $this->createMock(SchemaMapper::class), urlGenerator: $this->createMock(IURLGenerator::class)),
			lookups: new ValidationLookups(objectMapper: $mapper, userManager: $this->createMock(IUserManager::class)),
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end validator()
}//end class
