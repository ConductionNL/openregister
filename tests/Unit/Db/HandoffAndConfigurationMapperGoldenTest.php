<?php

/**
 * Golden test for the reads and creates of the handoff queue and configuration deployment mappers.
 *
 * Records every query-builder call and the result of each read, with a row and
 * without, and of each create with its defaults given and left out.
 * Re-record with UPDATE_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\ConfigurationDeploymentMapper;
use OCA\OpenRegister\Db\ConfigurationDraftSetMapper;
use OCA\OpenRegister\Db\HandoffQueueEntryMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\ConfigurationDeploymentMapper
 * @covers \OCA\OpenRegister\Db\ConfigurationDraftSetMapper
 * @covers \OCA\OpenRegister\Db\HandoffQueueEntryMapper
 * @uses   \OCA\OpenRegister\Db\ConfigurationDeployment
 * @uses   \OCA\OpenRegister\Db\ConfigurationDraftSet
 * @uses   \OCA\OpenRegister\Db\HandoffQueueEntry
 * @uses   \OCA\OpenRegister\Db\LinkColumn
 */
class HandoffAndConfigurationMapperGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/handoff-configuration-mapper-golden.json';

	private static function reads(): array {
		return [
			[HandoffQueueEntryMapper::class, 'find', [21]],
			[HandoffQueueEntryMapper::class, 'findParked', []],
			[HandoffQueueEntryMapper::class, 'findParked', [5]],
			[HandoffQueueEntryMapper::class, 'findParkedByKind', ['kind-1']],
			[HandoffQueueEntryMapper::class, 'findParkedForObject', ['obj-1']],
			[ConfigurationDeploymentMapper::class, 'findByUuid', ['uu-1']],
			[ConfigurationDeploymentMapper::class, 'findHistory', [7]],
			[ConfigurationDraftSetMapper::class, 'findByUuid', ['uu-2']],
			[ConfigurationDraftSetMapper::class, 'findAllSets', []],
			[ConfigurationDraftSetMapper::class, 'findAllSets', ['open', 9]],
		];
	}//end reads()

	private static function creates(): array {
		$at = new DateTime('2026-01-02T03:04:05+00:00');
		return [
			'deployment bare' => [ConfigurationDeploymentMapper::class, []],
			'deployment given' => [ConfigurationDeploymentMapper::class, ['uuid' => 'fixed-1', 'state' => 'failed', 'deployedAt' => $at]],
			'draft set bare' => [ConfigurationDraftSetMapper::class, []],
			'draft set given' => [ConfigurationDraftSetMapper::class, ['uuid' => 'fixed-2', 'state' => 'closed', 'created' => $at]],
		];
	}//end creates()

	public function testReadsAndCreatesMatchGolden(): void {
		$actual = [];
		foreach (self::reads() as [$class, $method, $args]) {
			$short = substr($class, strrpos($class, '\\') + 1);
			foreach (['found' => [['id' => 3]], 'missing' => []] as $rowName => $rows) {
				$actual[$short . ' ' . $method . '(' . implode(', ', $args) . ') / ' . $rowName] = $this->outcomeOf(class: $class, call: [$method, $args, $rows]);
			}
		}

		foreach (self::creates() as $name => [$class, $data]) {
			$actual[$name] = $this->outcomeOf(class: $class, call: ['createFromArray', [$data], []]);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testReadsAndCreatesMatchGolden()

	private function outcomeOf(string $class, array $call): array {
		[$method, $args, $rows] = $call;
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = [$rows];
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());

		$outcome = [];
		try {
			$outcome['result'] = $this->shape(value: (new $class($db))->$method(...$args));
		} catch (\Throwable $e) {
			$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage();
		}

		$outcome['queries'] = preg_replace('/= [0-9a-f]{8}-[0-9a-f-]{27}, /', '= <uuid>, ', $this->queryLog);
		return $outcome;
	}//end outcomeOf()

	private function shape(mixed $value): mixed {
		if (is_object($value) === true) {
			return get_class($value) . '#' . $value->getId();
		}

		if (is_array($value) === true) {
			return array_map(fn ($item) => $this->shape(value: $item), $value);
		}

		return $value;
	}//end shape()
}//end class
