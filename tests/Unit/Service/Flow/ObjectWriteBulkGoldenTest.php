<?php

/**
 * Golden over the bulk and per-item write paths of an object-write step.
 *
 * Each case runs one step against a fake store and records what the store was
 * sent, the items that came out, the run-log report, or the failure. The expected
 * values were recorded on the code before the bulk path moved out of
 * ObjectWriteNode. Generated uuids are replaced by their order of appearance.
 *
 * Set OBJECT_WRITE_GOLDEN_RECORD=1 to rewrite the fixture; a recording run
 * compares nothing.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/flow-object-write-bulk/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowStepReport;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteNode;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteNode
 * @uses   \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteBulk
 * @uses   \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteBulkPage
 * @uses   \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteConfigForm
 * @uses   \OCA\OpenRegister\Service\Flow\Nodes\ObjectWritePayload
 * @uses   \OCA\OpenRegister\Service\Flow\FlowItems
 * @uses   \OCA\OpenRegister\Service\Flow\FlowStepReport
 * @uses   \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteConfigValidator
 * @uses   \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteItem
 * @uses   \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteOptions
 * @uses   \OCA\OpenRegister\Service\Flow\Nodes\ObjectWritePlan
 * @uses   \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteTemplate
 */
final class ObjectWriteBulkGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/object-write-bulk-golden.json';

	private const UPSERT = ['operation' => 'upsert', 'replace' => true, 'match' => ['uuid' => '{{id}}']];

	/**
	 * What the store was asked to do, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * What `saveObjects()` answers in the current case.
	 *
	 * @var array<string, mixed>
	 */
	private array $bulkAnswer = [];

	/**
	 * The rows `findAll()` answers in the current case.
	 *
	 * @var array<int, ObjectEntity>
	 */
	private array $found = [];

	/**
	 * Every case must match the recording.
	 *
	 * @return void
	 */
	public function testCasesMatchTheRecording(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$actual[$name] = $this->runCase(case: $case);
		}

		if (getenv('OBJECT_WRITE_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		self::assertGreaterThan(15, count($actual));
		self::assertNotSame([], array_filter($actual, static fn (array $one): bool => isset($one['out'])));
		self::assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), $actual);

	}//end testCasesMatchTheRecording()

	/**
	 * The cases: config, items, the bulk answer and the rows a match finds.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$one = [FlowItems::item(json: ['id' => 'u1', 'name' => 'a'], binary: ['b' => 1])];
		$page = [
			3 => FlowItems::item(json: ['id' => 'u1', 'name' => 'a'], binary: ['b' => 1]),
			5 => FlowItems::item(json: ['id' => 'u2', 'name' => 'b', 'skip' => true]),
			'k' => FlowItems::item(json: ['id' => 'u3', 'name' => 'c']),
		];
		$fields = ['fields' => ['name' => '{{name}}']];
		$bulk = array_merge($fields, ['bulk' => true]);
		$upsert = array_merge($bulk, self::UPSERT);
		$saved = ['saved' => [['@self' => ['uuid' => 'u1'], 'id' => 'x', 'name' => 'from store']]];

		return [
			'bulk create' => ['config' => array_merge($bulk, ['operation' => 'create']), 'items' => $page],
			'bulk create with output' => ['config' => array_merge($bulk, ['operation' => 'create', 'output' => ' row ']), 'items' => $page],
			'bulk upsert answers' => [
				'config' => $upsert,
				'items' => $page,
				'answer' => [
					'saved' => [['@self' => ['id' => 'u1'], 'name' => 'stored'], 'text'],
					'updated' => [['uuid' => 'u2', 'name' => 'upd']],
					'unchanged' => [['id' => 'u3', 'name' => 'same'], ['name' => 'no id']],
				],
			],
			'bulk upsert skips one' => ['config' => array_merge($upsert, ['skipWhen' => 'skip']), 'items' => $page, 'answer' => $saved],
			'bulk skips all' => ['config' => array_merge($upsert, ['skipWhen' => 'id']), 'items' => [FlowItems::item(json: ['id' => 'skip'], binary: ['b' => 2])]],
			'bulk over the cap' => ['config' => array_merge($upsert, ['maxWrites' => 2]), 'items' => $page],
			'bulk at the cap' => ['config' => array_merge($upsert, ['maxWrites' => 3]), 'items' => $page],
			'bulk rejected, invalid larger' => [
				'config' => $upsert,
				'items' => $one,
				'answer' => ['invalid' => ['text', ['error' => ' '], ['error' => ' why ']], 'errors' => [['error' => 'other']]],
			],
			'bulk rejected, no reason' => ['config' => $upsert, 'items' => $one, 'answer' => ['errors' => [['x' => 1]]]],
			'bulk upsert id missing' => ['config' => array_merge($upsert, ['match' => ['uuid' => '{{none}}']]), 'items' => $one],
			'bulk upsert id not scalar' => ['config' => array_merge($upsert, ['match' => ['uuid' => '{{list}}']]), 'items' => [FlowItems::item(json: ['list' => [1]])]],
			'bulk upsert id blank' => ['config' => array_merge($upsert, ['match' => ['@self.uuid' => ' ']]), 'items' => $one],
			'bulk payloadFrom' => ['config' => ['bulk' => true, 'operation' => 'create', 'payloadFrom' => 'obj'], 'items' => [FlowItems::item(json: ['obj' => ['a' => 1]])]],
			'item create' => ['config' => array_merge($fields, ['operation' => 'create']), 'items' => $page],
			'item upsert updates' => ['config' => array_merge($fields, ['operation' => 'upsert', 'match' => ['uuid' => '{{id}}'], 'output' => 'row']), 'items' => $one, 'found' => 1],
			'item upsert inserts' => ['config' => array_merge($fields, ['operation' => 'upsert', 'match' => ['uuid' => '{{id}}']]), 'items' => $one],
			'item update replaces' => ['config' => array_merge($fields, self::UPSERT, ['operation' => 'update']), 'items' => $one, 'found' => 1],
			'item update misses' => ['config' => array_merge($fields, ['operation' => 'update', 'match' => ['uuid' => '{{id}}']]), 'items' => $one],
			'item cap stops' => ['config' => array_merge($fields, ['operation' => 'create', 'maxWrites' => 1]), 'items' => $page],
			'item delete' => ['config' => ['operation' => 'delete', 'confirmDelete' => true, 'match' => ['uuid' => '{{id}}'], 'output' => 'gone'], 'items' => $one, 'found' => 1],
			'item delete skips' => ['config' => ['operation' => 'delete', 'confirmDelete' => true, 'match' => ['uuid' => '{{id}}'], 'onNoMatch' => 'skip'], 'items' => $one],
		];

	}//end cases()

	/**
	 * Run one case.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed> What happened.
	 */
	private function runCase(array $case): array {
		$this->calls = [];
		$this->bulkAnswer = ($case['answer'] ?? []);
		$this->found = [];
		if (($case['found'] ?? 0) === 1) {
			$this->found = [$this->entity(data: ['name' => 'old'])];
		}

		$report = new FlowStepReport();
		$context = [FlowRunServiceInterface::RUN_AS_CONTEXT_KEY => 'alice', FlowStepReport::CONTEXT_KEY => $report];
		$config = array_merge(['register' => '1', 'schema' => '2'], $case['config']);

		$outcome = [];
		try {
			$outcome['out'] = $this->node()->execute(items: $case['items'], config: $config, context: $context);
		} catch (Throwable $e) {
			$outcome['failure'] = get_class($e) . ': ' . $e->getMessage();
		}

		$outcome['calls'] = $this->calls;
		$outcome['report'] = $report->take();
		return json_decode($this->withStableUuids(json: (string)json_encode($outcome)), true);

	}//end runCase()

	/**
	 * Replace generated v4 uuids by their order of appearance.
	 *
	 * @param string $json The outcome.
	 *
	 * @return string The outcome without random values.
	 */
	private function withStableUuids(string $json): string {
		$seen = [];
		return (string)preg_replace_callback(
			'/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/',
			static function (array $match) use (&$seen): string {
				$seen[$match[0]] ??= 'uuid-' . count($seen);
				return $seen[$match[0]];
			},
			$json
		);

	}//end withStableUuids()

	/**
	 * A stored object.
	 *
	 * @param array<string, mixed> $data Its data.
	 *
	 * @return ObjectEntity The object.
	 */
	private function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('stored-uuid');
		$entity->setObject($data);
		return $entity;

	}//end entity()

	/**
	 * A node over a recording store.
	 *
	 * @return ObjectWriteNode The node.
	 */
	private function node(): ObjectWriteNode {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		$user = $this->createMock(IUser::class);
		$user->method('isEnabled')->willReturn(true);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);

		$register = new Register();
		$register->setId(1);
		$register->setSlug('reg');
		$schema = new Schema();
		$schema->setId(2);
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturn($register);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn($schema);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturnArgument(2);

		return new ObjectWriteNode($this->store(), $registers, $schemas, $users, $config, $l10n, $this->createMock(IURLGenerator::class));

	}//end node()

	/**
	 * A store that records the write calls.
	 *
	 * @return ObjectOperationsInterface The fake.
	 */
	private function store(): ObjectOperationsInterface {
		$store = $this->createMock(ObjectOperationsInterface::class);
		$store->method('runAs')->willReturnCallback(static fn (IUser $user, callable $work): mixed => $work());
		$store->method('findAll')->willReturnCallback(fn (): array => $this->found);
		$store->method('saveObjects')->willReturnCallback(
			function (array $objects, mixed $register, mixed $schema, bool $_rbac, bool $_multitenancy, bool $validation, bool $events): array {
				$this->calls[] = ['saveObjects' => $objects, 'validation' => $validation, 'events' => $events];
				return $this->bulkAnswer;
			}
		);
		$store->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, mixed ...$rest): ObjectEntity {
				$this->calls[] = ['saveObject' => $object, 'uuid' => ($rest[3] ?? null), 'failIfExists' => ($rest[10] ?? null)];
				return $this->entity(data: (array)$object);
			}
		);
		$store->method('patchObject')->willReturnCallback(
			function (string $objectId, array $data): ObjectEntity {
				$this->calls[] = ['patchObject' => $objectId, 'data' => $data];
				return $this->entity(data: $data);
			}
		);
		$store->method('deleteObject')->willReturnCallback(
			function (string $uuid): bool {
				$this->calls[] = ['deleteObject' => $uuid];
				return true;
			}
		);

		return $store;

	}//end store()
}//end class
