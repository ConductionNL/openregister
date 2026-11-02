<?php

/**
 * Golden test for RematerialiseCalculationsCommand::execute().
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

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Command;

use DateTime;
use OCA\OpenRegister\Command\RematerialiseCalculationsCommand;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Calculation\AggregateReferenceResolver;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Calculation\ReferenceResolver;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Each case is a schema configuration and a set of rows with scripted collaborator
 * behaviour. The exit code, the verbose console output and every collaborator call
 * are compared with fixtures/rematerialise-golden.json.
 * Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * Expressions the scripted evaluator understands: `const:<v>`, `throw`, `date`,
 * `ref` (reads `@ref.a`), `agg` (reads `@aggregate.n`), `self` (reads `@self.uuid`)
 * and `seq` (uses a sequence).
 *
 * @uses \OCA\OpenRegister\Command\RematerialiseRun
 */
class RematerialiseCalculationsGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/rematerialise-golden.json';

	/** @var list<array<string, mixed>> Every collaborator call, in order. */
	private array $calls = [];

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$calc = static fn (string $expr, array $extra = []): array => ['expression' => $expr, 'materialise' => true] + $extra;
		$cases = [
			'register missing' => ['registerThrows' => true],
			'no calculations' => ['calcs' => null],
			'empty calculations' => ['calcs' => []],
			'nothing materialised' => ['calcs' => ['a' => ['expression' => 'const:1'], 'b' => 'x', 'c' => ['expression' => 'const:1', 'materialise' => 'yes']]],
			'unchanged row' => ['calcs' => ['a' => $calc('const:1')], 'rows' => [['data' => ['a' => 1]]]],
			'changed row saved and materialised' => ['calcs' => ['a' => $calc('const:2')], 'rows' => [['data' => ['a' => 1], 'after' => ['a' => 2]]]],
			'changed row not materialised' => ['calcs' => ['a' => $calc('const:2')], 'rows' => [['data' => ['a' => 1]]]],
			'save throws' => ['calcs' => ['a' => $calc('const:2')], 'rows' => [['data' => ['a' => 1], 'saveThrows' => true]]],
			'dry run' => ['dryRun' => true, 'calcs' => ['a' => $calc('const:2'), 'b' => $calc('const:1')], 'rows' => [['data' => ['a' => 1, 'b' => 1]], ['data' => ['a' => 2, 'b' => 1]]]],
			'evaluation throws' => ['calcs' => ['a' => $calc('throw'), 'b' => $calc('const:5')], 'rows' => [['data' => ['b' => 1], 'after' => ['b' => 5]]]],
			'evaluation throws only' => ['calcs' => ['a' => $calc('throw')], 'rows' => [['data' => []]]],
			'date value' => ['calcs' => ['d' => $calc('date')], 'rows' => [['data' => ['d' => '2026-01-01T00:00:00+00:00']], ['data' => ['d' => 'old'], 'after' => ['d' => '2026-01-01T00:00:00+00:00']]]],
			'sequence skipped' => ['calcs' => ['n' => $calc('seq')], 'rows' => [['data' => ['n' => '2026-0013']]]],
			'self payload' => ['calcs' => ['u' => $calc('self')], 'rows' => [['data' => [], 'after' => ['u' => 'uuid-0']]]],
			'non-materialised mixed in' => ['calcs' => ['a' => ['expression' => 'const:9'], 'b' => $calc('const:2'), 'c' => 'scalar'], 'rows' => [['data' => ['a' => 1, 'b' => 1], 'after' => ['b' => 2]]]],
			'references resolved' => ['calcs' => ['r' => $calc('ref')], 'refs' => ['a' => ['schema' => 'x']], 'rows' => [['data' => ['r' => null], 'after' => ['r' => 'A-uuid-0']]]],
			'references empty' => ['calcs' => ['r' => $calc('ref')], 'refs' => [], 'rows' => [['data' => ['r' => 1]]]],
			'references not an array' => ['calcs' => ['r' => $calc('ref')], 'refs' => 'x', 'rows' => [['data' => ['r' => 1]]]],
			'aggregates resolved' => ['calcs' => ['n' => $calc('agg')], 'aggs' => ['n' => ['schema' => 'y']], 'rows' => [['data' => ['n' => 1], 'after' => ['n' => 3]], ['data' => ['n' => 3]]]],
			'aggregate fails' => ['calcs' => ['n' => $calc('agg')], 'aggs' => ['n' => ['schema' => 'y']], 'rows' => [['data' => ['n' => 1, 'aggFail' => true]], ['data' => ['n' => 3]]]],
			'aggregates empty' => ['calcs' => ['n' => $calc('agg')], 'aggs' => [], 'rows' => [['data' => ['n' => null]]]],
			'reread already materialised' => ['calcs' => ['a' => $calc('const:2')], 'rows' => [['data' => ['a' => 1], 'current' => ['a' => 2]]]],
			'reread partly materialised' => ['calcs' => ['a' => $calc('const:2'), 'b' => $calc('const:3')], 'rows' => [['data' => ['a' => 1, 'b' => 1], 'current' => ['a' => 2, 'b' => 1, 'c' => 'x'], 'after' => ['a' => 2, 'b' => 3]]]],
			'reread fails' => ['calcs' => ['a' => $calc('const:2')], 'rows' => [['data' => ['a' => 1], 'current' => 'throw', 'after' => ['a' => 2]]]],
			'string typed value restored' => ['calcs' => ['a' => $calc('const:2')], 'rows' => [['data' => ['a' => 1, 'blob' => ['k' => 'v']], 'after' => ['a' => 2]]]],
			'fallback kept by save' => ['calcs' => ['a' => $calc('const:2', ['fallback' => true]), 'b' => $calc('const:4')], 'rows' => [['data' => ['a' => 1, 'b' => 1], 'after' => ['b' => 4]]]],
			'fallback only' => ['calcs' => ['a' => $calc('const:2', ['fallback' => true])], 'rows' => [['data' => ['a' => 1]]]],
			'many rows mixed' => [
				'calcs' => ['a' => $calc('const:2'), 'n' => $calc('agg')],
				'aggs' => ['n' => ['schema' => 'y']],
				'rows' => [
					['data' => ['a' => 2, 'n' => 3]],
					['data' => ['a' => 1, 'n' => 3], 'after' => ['a' => 2]],
					['data' => ['a' => 1, 'n' => 3, 'aggFail' => true]],
					['data' => ['a' => 1, 'n' => 3], 'saveThrows' => true],
					['data' => ['a' => 1, 'n' => 1], 'current' => ['a' => 2, 'n' => 3]],
				],
			],
			'no slugs' => ['noSlugs' => true, 'calcs' => ['a' => $calc('const:1')], 'rows' => [['data' => ['a' => 1]]]],
			'no rows' => ['calcs' => ['a' => $calc('const:1')], 'rows' => []],
		];

		$out = [];
		foreach ($cases as $name => $case) {
			$out[$name] = [$case];
		}

		return $out;
	}//end cases()

	/**
	 * @param array<string, mixed> $case
	 */
	#[DataProvider('cases')]
	public function testExecuteMatchesGolden(array $case): void {
		$name = $this->dataName();
		$output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
		$input = ['register' => 'reg', 'schema' => 'sch'];
		if (($case['dryRun'] ?? false) === true) {
			$input['--dry-run'] = true;
		}

		$code = $this->command($case)->run(new ArrayInput($input), $output);
		$actual = json_decode(json_encode(['code' => $code, 'output' => explode("\n", $output->fetch()), 'calls' => $this->calls]), true);

		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end testExecuteMatchesGolden()

	/**
	 * Build the command with scripted collaborators that record their calls.
	 *
	 * @param array<string, mixed> $case
	 */
	private function command(array $case): RematerialiseCalculationsCommand {
		$register = new Register();
		$register->setId(5);
		$schema = new Schema();
		$schema->setId(7);
		if (($case['noSlugs'] ?? false) === false) {
			$register->setSlug('reg');
			$schema->setSlug('sch');
		}

		$schema->setProperties(['a' => ['type' => 'integer'], 'blob' => ['type' => 'string']]);
		$config = [];
		if (array_key_exists('calcs', $case) === true && $case['calcs'] !== null) {
			$config['x-openregister-calculations'] = $case['calcs'];
		}

		if (array_key_exists('refs', $case) === true) {
			$config['x-openregister-references'] = $case['refs'];
		}

		if (array_key_exists('aggs', $case) === true) {
			$config['x-openregister-aggregate-refs'] = $case['aggs'];
		}

		$schema->setConfiguration($config);

		$registers = $this->createMock(RegisterMapper::class);
		if (($case['registerThrows'] ?? false) === true) {
			$registers->method('find')->willThrowException(new RuntimeException('Register not found'));
		} else {
			$registers->method('find')->willReturn($register);
		}

		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($schema);

		$rows = ($case['rows'] ?? []);
		$entities = [];
		foreach ($rows as $index => $row) {
			$entities[] = $this->entity(uuid: 'uuid-' . $index, data: $row['data']);
		}

		$magic = $this->createMock(MagicMapper::class);
		$magic->method('findAllInRegisterSchemaTable')->willReturnCallback(
			function ($register, $schema, $limit) use ($entities): array {
				$this->calls[] = ['findAll', $limit];
				return $entities;
			}
		);
		$magic->method('find')->willReturnCallback(
			function (string $identifier, $register, $schema, $_rbac, $_multitenancy) use ($rows): ObjectEntity {
				$this->calls[] = ['reread', $identifier, $_rbac, $_multitenancy];
				$row = $rows[(int)substr($identifier, 5)];
				$current = ($row['current'] ?? $row['data']);
				if ($current === 'throw') {
					throw new RuntimeException('gone');
				}

				return $this->entity(uuid: $identifier, data: $current);
			}
		);

		$evaluator = $this->createMock(CalculationEvaluator::class);
		$evaluator->method('expressionUsesSequence')->willReturnCallback(static fn ($expression): bool => $expression === 'seq');
		$evaluator->method('evaluate')->willReturnCallback(
			function (array $payload, $expression) {
				$this->calls[] = ['evaluate', $expression, array_keys($payload)];
				if (str_starts_with((string)$expression, 'const:') === true) {
					return (int)substr((string)$expression, 6);
				}

				return match ($expression) {
					'throw' => throw new RuntimeException('bad expression'),
					'date' => new DateTime('2026-01-01T00:00:00+00:00'),
					'ref' => ($payload['@ref']['a'] ?? null),
					'agg' => ($payload['@aggregate']['n'] ?? null),
					'self' => $payload['@self'],
					default => null,
				};
			}
		);

		$references = $this->createMock(ReferenceResolver::class);
		$references->method('resolveAll')->willReturnCallback(
			function (array $payload, array $references, $register, $organisation): array {
				$this->calls[] = ['references', $references, $register, $organisation];
				return ['a' => 'A-' . $payload['@self']['uuid']];
			}
		);

		$aggregates = $this->createMock(AggregateReferenceResolver::class);
		$aggregates->method('resolveAllWithOutcome')->willReturnCallback(
			function (array $payload, array $aggregates, $registerRef): array {
				$this->calls[] = ['aggregates', $aggregates, $registerRef];
				if (($payload['aggFail'] ?? false) === true) {
					return ['values' => [], 'failed' => ['n' => 'boom']];
				}

				return ['values' => ['n' => 3], 'failed' => []];
			}
		);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('saveObject')->willReturnCallback(
			function ($object, ?array $extend = [], $reg = null, $sch = null, ?string $uuid = null, ...$rest) use ($rows): ObjectEntity {
				$this->calls[] = ['save', $object, $reg, $sch, $uuid, $rest];
				$row = $rows[(int)substr((string)$uuid, 5)];
				if (($row['saveThrows'] ?? false) === true) {
					throw new RuntimeException('Cannot save');
				}

				return $this->entity(uuid: (string)$uuid, data: array_merge($object, ($row['after'] ?? [])));
			}
		);

		return new RematerialiseCalculationsCommand($registers, $schemas, $magic, $objects, $evaluator, $references, $aggregates);
	}//end command()

	/**
	 * @param array<string, mixed> $data
	 */
	private function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setRegister('5');
		$entity->setSchema('7');
		$entity->setOrganisation('org-1');
		$entity->setOwner('alice');
		$entity->setCreated(new DateTime('2026-02-03T04:05:06+00:00'));
		$entity->setObject($data);
		return $entity;
	}//end entity()
}//end class
