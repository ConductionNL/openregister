<?php

/**
 * Rematerialise can write a changed calculated value (live pass O8) and an
 * aggregate it cannot resolve is a failure, not "unchanged" (O9).
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
 * @spec openspec/changes/rematerialise-writes-calculated-values/specs/computed-fields/spec.md
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
class RematerialiseWritesCalculatedValuesTest extends TestCase {

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

		$evaluator = $this->createMock(CalculationEvaluator::class);
		$evaluator->method('expressionUsesSequence')->willReturn(false);
		$evaluator->method('evaluate')->willReturnCallback(
			static fn (array $payload, $expression) => match ($expression) {
				'past' => true,
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
				$violations = $rules->validateReadOnlyConstraints(incomingObject: $object, existingObject: $stored, schema: $schema);
				if ($violations !== []) {
					throw new RuntimeException('Cannot modify readOnly properties: ' . implode(', ', array_column($violations, 'property')));
				}

				$result = new ObjectEntity();
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
		$code = $command->run(new ArrayInput(['register' => 'learniq', 'schema' => 'session']), $output);
		$out = $output->fetch();
		return $code;
	}//end run1()

	/**
	 * The session schema: isPast is materialised and readOnly, as learniq declares it.
	 *
	 * @return Schema
	 */
	private function session(): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('session');
		$schema->setProperties(['title' => ['type' => 'string'], 'isPast' => ['type' => 'boolean', 'readOnly' => true]]);
		$schema->setConfiguration(['x-openregister-calculations' => ['isPast' => ['expression' => 'past', 'materialise' => true]]]);
		return $schema;
	}//end session()

	/**
	 * O8: a row whose readOnly calculated value changes is written (live: "Cannot modify readOnly properties").
	 *
	 * @return void
	 */
	public function testAChangedReadOnlyCalculatedValueIsWritten(): void {
		$code = $this->run1(schema: $this->session(), stored: ['title' => 'S', 'isPast' => null], listener: true, aggregates: null, out: $out);

		$this->assertStringNotContainsString('Cannot modify readOnly', (string) $out);
		$this->assertStringContainsString('Touched 1, unchanged 0, failed 0', (string) $out);
		$this->assertSame(0, $code);
		$this->assertNull($this->saved[0]['isPast'] ?? null, 'The command hands the stored value; the save path materialises.');
	}//end testAChangedReadOnlyCalculatedValueIsWritten()

	/**
	 * A save that did not materialise the value is a failure, not a touched row.
	 *
	 * @return void
	 */
	public function testASaveThatDidNotMaterialiseIsAFailure(): void {
		$code = $this->run1(schema: $this->session(), stored: ['title' => 'S', 'isPast' => null], listener: false, aggregates: null, out: $out);

		$this->assertStringContainsString('failed 1', (string) $out);
		$this->assertSame(1, $code);
	}//end testASaveThatDidNotMaterialiseIsAFailure()

	/**
	 * O9: the aggregate resolves as the system under occ (no session), and a refusal counts as failed.
	 *
	 * @return void
	 */
	public function testAnAggregateResolvesAsTheSystemAndARefusalIsAFailure(): void {
		$schema = new Schema();
		$schema->setId(8);
		$schema->setSlug('enrolment');
		$schema->setProperties(['completed' => ['type' => 'integer', 'readOnly' => true]]);
		$schema->setConfiguration(
			[
				'x-openregister-calculations' => ['completed' => ['expression' => 'completed', 'materialise' => true]],
				'x-openregister-aggregate-refs' => ['completed' => ['schema' => 'lesson-completion', 'metric' => 'count']],
			]
		);

		// The runner's gate as AggregationRunner::runAdhoc() applies it to a null session user.
		$runner = $this->createMock(AggregationRunner::class);
		$runner->method('runAdhocByRef')->willReturnCallback(
			static function (string $registerRef, string $schemaRef, $query, bool $bypassRbac = false): array {
				if ($bypassRbac === false) {
					throw new NotAuthorizedException('You do not have permission to aggregate schema "' . $schemaRef . '".');
				}

				return ['value' => 4];
			}
		);
		$resolver = new AggregateReferenceResolver(aggregationRunner: $runner, logger: $this->createMock(LoggerInterface::class));
		$this->assertSame(['completed' => 4], $resolver->resolveAll(payload: [], aggregates: $schema->getConfiguration()['x-openregister-aggregate-refs'], registerRef: '5'));

		$broken = $this->createMock(AggregationRunner::class);
		$broken->method('runAdhocByRef')->willThrowException(new RuntimeException('database went away'));
		$code = $this->run1(
			schema: $schema,
			stored: ['completed' => null],
			listener: true,
			aggregates: new AggregateReferenceResolver(aggregationRunner: $broken, logger: $this->createMock(LoggerInterface::class)),
			out: $out
		);

		$this->assertStringContainsString('failed 1', (string) $out);
		$this->assertStringNotContainsString('unchanged 1', (string) $out);
		$this->assertSame(1, $code);
	}//end testAnAggregateResolvesAsTheSystemAndARefusalIsAFailure()
}//end class
