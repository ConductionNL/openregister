<?php

/**
 * A calculation declared `fallback` gives the value only until an app's save
 * listener sets its own: dossiq's case deadline follows the statutory term
 * once there is one. Rematerialise then counts the row as touched, not as a
 * save that did not materialise (23 of 75 dossiq cases after REFRESH-7).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/computed-fields/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\RematerialiseCalculationsCommand;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\Calculation\AggregateReferenceResolver;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Calculation\ReferenceResolver;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * learniq session (O8) and enrolment (O9), each one row.
 */
class RematerialiseFallbackCalculationTest extends TestCase {

	/** @var array<int, array<string, mixed>> What the command handed to saveObject(). */
	private array $saved = [];

	/**
	 * Run the command over one stored row.
	 *
	 * @param Schema                    $schema     The schema.
	 * @param array<string, mixed>      $stored     The row's stored data.
	 * @param bool                      $listener   Whether the save path materialises (as CalculationOnSaveListener does).
	 * @param AggregateReferenceResolver|null $aggregates The resolver.
	 * @param string|null               $out        The output.
	 *
	 * @return int The exit code.
	 */
	private function run1(Schema $schema, array $stored, bool $listener, ?AggregateReferenceResolver $aggregates, ?string &$out=null): int {
		$register = new Register();
		$register->setId(5);
		$register->setSlug('learniq');
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturn($register);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($schema);

		$entity = new ObjectEntity();
		$entity->setUuid('27ac309c-d9fd-44e4-9d69-8a17cdd894f4');
		$entity->setRegister('5');
		$entity->setSchema('7');
		$entity->setObject($stored);
		$magic = $this->createMock(MagicMapper::class);
		$magic->method('findAllInRegisterSchemaTable')->willReturn([$entity]);
		// The command re-reads the row before saving it (live pass O11).
		$magic->method('find')->willReturn($entity);

		$evaluator = $this->createMock(CalculationEvaluator::class);
		$evaluator->method('expressionUsesSequence')->willReturn(false);
		$evaluator->method('evaluate')->willReturnCallback(
			static fn (array $payload, $expression) => match ($expression) {
				'past' => true,
				'formula' => '2026-06-29',
				'completed' => ($payload['@aggregate']['completed'] ?? null),
				default => null,
			}
		);

		// The REAL readOnly rule ObjectService::enforceReadOnlyOnUpdate() applies.
		$rules = new ValidateObject(
			$this->createMock(IAppConfig::class),
			$this->createMock(MagicMapper::class),
			$schemas,
			$this->createMock(IURLGenerator::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IUserManager::class)
		);
		$objects = $this->createMock(ObjectService::class);
		$objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], $reg = null, $sch = null, ?string $uuid = null) use ($rules, $schema, $stored, $listener): ObjectEntity {
				$this->saved[] = $object;
				// The type rule validation applies: a `type: string` property refuses an array.
				foreach ($schema->getProperties() as $name => $property) {
					if (($property['type'] ?? null) === 'string' && is_array($object[$name] ?? null) === true) {
						throw new RuntimeException("Property '".$name."' should be type 'string or null' but is 'array'");
					}
				}

				$violations = $rules->validateReadOnlyConstraints(incomingObject: $object, existingObject: $stored, schema: $schema);
				if ($violations !== []) {
					throw new RuntimeException('Cannot modify readOnly properties: ' . implode(', ', array_column($violations, 'property')));
				}

				$result = new ObjectEntity();
				// The save path materialises isPast; the app's own listener then puts its deadline back.
				$result->setObject($listener === true ? array_merge($object, ['isPast' => true]) : $object);
				return $result;
			}
		);

		$command = new RematerialiseCalculationsCommand(
			$registers,
			$schemas,
			$magic,
			$objects,
			$evaluator,
			$this->createMock(ReferenceResolver::class),
			($aggregates ?? $this->createMock(AggregateReferenceResolver::class))
		);

		$output = new BufferedOutput();
		$code = $command->run(new ArrayInput(['register' => 'dossiq', 'schema' => 'case']), $output);
		$out = $output->fetch();
		return $code;
	}//end run1()

	/**
	 * The case schema: isPast is a plain materialised calculation, deadline one an app may override.
	 *
	 * @param bool $fallback Whether deadline is declared a fallback.
	 *
	 * @return Schema
	 */
	private function caseSchema(bool $fallback): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('case');
		$schema->setProperties(['deadline' => ['type' => 'string', 'readOnly' => true], 'isPast' => ['type' => 'boolean', 'readOnly' => true]]);
		$deadline = ['expression' => 'formula', 'materialise' => true];
		if ($fallback === true) {
			$deadline['fallback'] = true;
		}

		$schema->setConfiguration(['x-openregister-calculations' => ['isPast' => ['expression' => 'past', 'materialise' => true], 'deadline' => $deadline]]);
		return $schema;
	}//end caseSchema()

	/**
	 * A fallback the app kept is touched, not failed.
	 *
	 * @return void
	 */
	public function testAFallbackTheAppKeptIsNotAFailure(): void {
		$code = $this->run1(schema: $this->caseSchema(fallback: true), stored: ['deadline' => '2026-10-29', 'isPast' => null], listener: true, aggregates: null, out: $out);

		$this->assertStringNotContainsString('did not materialise', (string) $out);
		$this->assertStringContainsString('Touched 1, unchanged 0, failed 0', (string) $out);
		$this->assertSame(0, $code);
	}//end testAFallbackTheAppKeptIsNotAFailure()

	/**
	 * Without the declaration, a value the save did not change is still a failure.
	 *
	 * @return void
	 */
	public function testAPlainCalculationTheSaveDidNotWriteIsStillAFailure(): void {
		$code = $this->run1(schema: $this->caseSchema(fallback: false), stored: ['deadline' => '2026-10-29', 'isPast' => null], listener: true, aggregates: null, out: $out);

		$this->assertStringContainsString('did not materialise: deadline', (string) $out);
		$this->assertSame(1, $code);
	}//end testAPlainCalculationTheSaveDidNotWriteIsStillAFailure()
}//end class
