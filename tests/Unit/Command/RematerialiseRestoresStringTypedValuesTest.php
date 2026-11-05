<?php

/**
 * Rematerialise re-saves a row whose `type: string` property holds JSON: the
 * read hands that property back decoded, and the re-save restores the stored
 * string, so the save is not refused ("Property 'statusHistory' should be
 * type 'string or null' but is 'array'", dossiq cases on REFRESH-7).
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
class RematerialiseRestoresStringTypedValuesTest extends TestCase {

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
	 * The case schema: statusHistory is JSON held in a string, statutoryTerm is materialised.
	 *
	 * @return Schema
	 */
	private function caseSchema(): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('case');
		$schema->setProperties(
			[
				'title'         => ['type' => 'string'],
				'statusHistory' => ['type' => 'string', 'description' => 'History of status changes (JSON-encoded array)'],
				'tags'          => ['type' => 'array'],
				'isPast'        => ['type' => 'boolean', 'readOnly' => true],
			]
		);
		$schema->setConfiguration(['x-openregister-calculations' => ['isPast' => ['expression' => 'past', 'materialise' => true]]]);
		return $schema;
	}//end caseSchema()

	/**
	 * A row read back with its JSON string decoded is re-saved with the string restored.
	 *
	 * @return void
	 */
	public function testADecodedStringTypedValueIsRestoredBeforeTheResave(): void {
		$history = [['status' => 'ontvangen', 'enteredAt' => '2026-10-11T09:12:00+02:00']];
		$code    = $this->run1(
			schema: $this->caseSchema(),
			stored: ['title' => 'Zaak', 'statusHistory' => $history, 'tags' => ['woo'], 'isPast' => null],
			listener: true,
			aggregates: null,
			out: $out
		);

		$this->assertStringNotContainsString('should be type', (string) $out);
		$this->assertStringContainsString('Touched 1, unchanged 0, failed 0', (string) $out);
		$this->assertSame(0, $code);
		$this->assertSame(json_encode($history, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $this->saved[0]['statusHistory']);
		$this->assertSame(['woo'], $this->saved[0]['tags'], 'An array-typed property keeps its array.');
	}//end testADecodedStringTypedValueIsRestoredBeforeTheResave()
}//end class
