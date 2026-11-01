<?php

/**
 * BulkRelationHandler::handlePostSaveInverseRelations() prepares the inverse writeBack exactly as recorded.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObjects;

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkRelationHandler;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkValidationHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Throwable;

/**
 * Golden record of the post-save inverse-relation pass: which related ids are fetched (by
 * parameter name), how often the schema analysis is asked, which warnings are raised, and what
 * every fetched related object holds afterwards. Covers missing schemas, empty analyses,
 * writeBack switched on, off, falsy and missing, single and list values, empty and repeated ids,
 * ids nothing answers to, a failing fetch, keyed saved-object lists and inverse properties that
 * already list the source. Set OR_GOLDEN_RECORD=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObjects\BulkRelationHandler
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Object\SaveObjects\InverseLink
 * @uses   \OCA\OpenRegister\Service\Object\SaveObjects\InverseRelationContext
 */
class BulkRelationHandlerInverseWriteBackGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/bulk-relation-handler-inverse-write-back-golden.json';

	/**
	 * Every scenario matches the recorded golden.
	 *
	 * @return void
	 */
	public function testScenariosMatchTheGolden(): void {
		$actual = [];
		foreach ($this->scenarios() as $name => $scenario) {
			$actual[$name] = $this->outcome(scenario: $scenario);
		}

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(expected: $expected, actual: $actual);
	}//end testScenariosMatchTheGolden()

	/**
	 * The scenarios: saved objects as [uuid, schema id, data], analyses by schema id, existing targets.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function scenarios(): array {
		$on   = ['writeBack' => true, 'inverseProperty' => 'members'];
		$pool = [
			't1' => ['name' => 'one'],
			't2' => ['name' => 'two', 'members' => 'solo'],
			't3' => ['name' => 'three', 'members' => ['s1']],
			'7'  => ['name' => 'seven'],
		];
		return [
			'no saved objects'           => ['saved' => [], 'analyses' => ['s' => ['inverseProperties' => ['p' => $on]]], 'pool' => $pool],
			'schema not cached'          => ['saved' => [['s1', 'unknown', ['p' => 't1']]], 'analyses' => ['s' => ['inverseProperties' => ['p' => $on]]], 'pool' => $pool],
			'schema id null'             => ['saved' => [['s1', null, ['p' => 't1']]], 'analyses' => ['' => ['inverseProperties' => ['p' => $on]]], 'pool' => $pool],
			'no inverse properties'      => ['saved' => [['s1', 's', ['p' => 't1']]], 'analyses' => ['s' => ['inverseProperties' => []]], 'pool' => $pool],
			'analysis without key'       => ['saved' => [['s1', 's', ['p' => 't1']]], 'analyses' => ['s' => []], 'pool' => $pool],
			'single id'                  => ['saved' => [['s1', 's', ['p' => 't1']]], 'analyses' => ['s' => ['inverseProperties' => ['p' => $on]]], 'pool' => $pool],
			'id list with gaps'          => ['saved' => [['s9', 's', ['p' => ['t1', '', null, '0', 'missing', 't3', 0]]]], 'analyses' => ['s' => ['inverseProperties' => ['p' => $on]]], 'pool' => $pool],
			'empty list id'              => ['saved' => [['s1', 's', ['p' => [[], 't1', false]]]], 'analyses' => ['s' => ['inverseProperties' => ['p' => $on]]], 'pool' => $pool],
			'keyed id list'              => ['saved' => [['s9', 's', ['p' => ['x' => 't1', 'y' => 't2']]]], 'analyses' => ['s' => ['inverseProperties' => ['p' => $on]]], 'pool' => $pool],
			'scalar inverse wrapped'     => ['saved' => [['s1', 's', ['p' => 't2']]], 'analyses' => ['s' => ['inverseProperties' => ['p' => $on]]], 'pool' => $pool],
			'source already listed'      => ['saved' => [['s1', 's', ['p' => 't3']]], 'analyses' => ['s' => ['inverseProperties' => ['p' => $on]]], 'pool' => $pool],
			'writeBack false'            => ['saved' => [['s1', 's', ['p' => 't1']]], 'analyses' => ['s' => ['inverseProperties' => ['p' => ['writeBack' => false, 'inverseProperty' => 'members']]]], 'pool' => $pool],
			'writeBack falsy, fetched'   => ['saved' => [['s1', 's', ['p' => 't1', 'q' => 't1']]], 'analyses' => ['s' => ['inverseProperties' => ['p' => $on, 'q' => ['writeBack' => 0, 'inverseProperty' => 'others']]]], 'pool' => $pool],
			'writeBack falsy, alone'     => ['saved' => [['s1', 's', ['q' => 't1']]], 'analyses' => ['s' => ['inverseProperties' => ['q' => ['writeBack' => 0, 'inverseProperty' => 'others']]]], 'pool' => $pool],
			'writeBack missing, fetched' => ['saved' => [['s1', 's', ['p' => 't1', 'q' => 't2']]], 'analyses' => ['s' => ['inverseProperties' => ['p' => $on, 'q' => ['inverseProperty' => 'others']]]], 'pool' => $pool],
			'writeBack missing, unset'   => ['saved' => [['s1', 's', ['p' => 't1']]], 'analyses' => ['s' => ['inverseProperties' => ['p' => $on, 'q' => ['inverseProperty' => 'others']]]], 'pool' => $pool],
			'no inverseProperty name'    => ['saved' => [['s1', 's', ['p' => 't1', 'r' => 't2']]], 'analyses' => ['s' => ['inverseProperties' => ['p' => ['writeBack' => true], 'r' => ['writeBack' => true, 'inverseProperty' => null]]]], 'pool' => $pool],
			'property not set'           => ['saved' => [['s1', 's', ['p' => null, 'z' => 't1']]], 'analyses' => ['s' => ['inverseProperties' => ['p' => $on, 'z2' => $on]]], 'pool' => $pool],
			'two sources, one target'    => ['saved' => [['s1', 's', ['p' => 't1']], ['s2', 's', ['p' => ['t1', 't2']]], ['s3', 'other', ['p' => 't1']]], 'analyses' => ['s' => ['inverseProperties' => ['p' => $on]], 'other' => ['inverseProperties' => []]], 'pool' => $pool],
			'keyed saved objects'        => ['saved' => ['x' => ['s1', 's', ['p' => 't1']], 5 => ['s2', 'unknown', ['p' => 't1']], 'z' => ['s3', 's', ['p' => 't2']]], 'analyses' => ['s' => ['inverseProperties' => ['p' => $on]]], 'pool' => $pool],
			'numeric id'                 => ['saved' => [['s1', 's', ['p' => 7]]], 'analyses' => ['s' => ['inverseProperties' => ['p' => $on]]], 'pool' => $pool],
			'nothing found'              => ['saved' => [['s1', 's', ['p' => 'missing']]], 'analyses' => ['s' => ['inverseProperties' => ['p' => $on]]], 'pool' => $pool],
			'fetch fails'                => ['saved' => [['s1', 's', ['p' => 't1']]], 'analyses' => ['s' => ['inverseProperties' => ['p' => $on]]], 'pool' => $pool, 'throws' => true],
			'source without uuid'        => ['saved' => [[null, 's', ['p' => 't1']]], 'analyses' => ['s' => ['inverseProperties' => ['p' => $on]]], 'pool' => $pool],
			'two properties, two names'  => ['saved' => [['s1', 's', ['p' => 't1', 'q' => ['t1', 't3']]]], 'analyses' => ['s' => ['inverseProperties' => ['p' => $on, 'q' => ['writeBack' => true, 'inverseProperty' => 'owners']]]], 'pool' => $pool],
		];
	}//end scenarios()

	/**
	 * Run one scenario and record what it fetched, asked and changed.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 *
	 * @return array<string, mixed> The recorded outcome.
	 */
	private function outcome(array $scenario): array {
		$record  = ['findAll' => [], 'analysisAsked' => [], 'warnings' => []];
		$targets = [];
		foreach ($scenario['pool'] as $uuid => $data) {
			$targets[(string) $uuid] = $this->entity(uuid: (string) $uuid, schema: 't', data: $data);
		}

		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('findAll')->willReturnCallback(
			function (...$args) use (&$record, $targets, $scenario) {
				$record['findAll'][] = $this->byName(args: $args);
				if (($scenario['throws'] ?? false) === true) {
					throw new Exception('fetch failed');
				}

				$ids = array_map('strval', $this->byName(args: $args)['ids'] ?? []);
				return array_values(array_filter($targets, fn ($uuid) => in_array((string) $uuid, $ids, true), ARRAY_FILTER_USE_KEY));
			}
		);

		$schemaCache = [];
		foreach (array_keys($scenario['analyses']) as $schemaId) {
			$schema = new Schema();
			$schema->setSlug((string) $schemaId);
			$schemaCache[$schemaId] = $schema;
		}

		$saved = [];
		foreach ($scenario['saved'] as $key => [$uuid, $schemaId, $data]) {
			$saved[$key] = $this->entity(uuid: $uuid, schema: $schemaId, data: $data);
		}

		$analyses = $scenario['analyses'];
		$analysis = function (Schema $schema) use (&$record, $analyses) {
			$record['analysisAsked'][] = $schema->getSlug();
			return $analyses[$schema->getSlug()];
		};

		$handler = new BulkRelationHandler(
			$this->createMock(BulkValidationHandler::class),
			$mapper,
			$this->createMock(LoggerInterface::class)
		);

		set_error_handler(
			function (int $errno, string $message) use (&$record): bool {
				$record['warnings'][] = $message;
				return true;
			}
		);
		try {
			$handler->handlePostSaveInverseRelations($saved, $schemaCache, $analysis);
		} catch (Throwable $e) {
			$record['threw'] = get_class($e);
		} finally {
			restore_error_handler();
		}

		$record['targets'] = array_map(fn (ObjectEntity $target) => $target->getObject(), $targets);
		$record['saved']   = array_map(fn (ObjectEntity $source) => $source->getObject(), $saved);
		return $record;
	}//end outcome()

	/**
	 * The arguments of a findAll() call by parameter name, leaving out those at their default.
	 *
	 * @param array $args The positional arguments the double received.
	 *
	 * @return array<string, mixed> The arguments by name.
	 */
	private function byName(array $args): array {
		$named = [];
		foreach ((new ReflectionMethod(MagicMapper::class, 'findAll'))->getParameters() as $position => $parameter) {
			if (array_key_exists($position, $args) === false) {
				continue;
			}

			if ($parameter->isDefaultValueAvailable() === true && $parameter->getDefaultValue() === $args[$position]) {
				continue;
			}

			$named[$parameter->getName()] = $args[$position];
		}

		return $named;
	}//end byName()

	/**
	 * An object entity with this uuid, schema id and data.
	 *
	 * @param string|null $uuid   The uuid.
	 * @param string|null $schema The schema id.
	 * @param array       $data   The object data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(?string $uuid, ?string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()
}//end class
