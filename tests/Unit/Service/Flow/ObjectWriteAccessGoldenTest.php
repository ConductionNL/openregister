<?php

/**
 * Golden over who an object-write step acts as and what a write can reach.
 *
 * Each case runs one step against fakes that record the acting user of every
 * lookup and write, the filters a match sends, and the arguments of the write it
 * feeds. The session user of the fake store is `session`, never the run's owner,
 * so a lookup or a write outside `runAs()` shows up in the recording. The expected
 * values were recorded on the code before the match, the owner and the delete
 * left ObjectWriteNode.
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
 * @spec openspec/specs/flow-object-write-access/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Exception\SchemaNotInRegisterException;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteNode;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectWritePlatform;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteStores;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use OCA\OpenRegister\Service\Object\BulkSaveOptions;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteNode
 * @uses   \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteMatcher
 * @uses   \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteMatchFilters
 * @uses   \OCA\OpenRegister\Exception\SchemaNotInRegisterException
 * @uses   \OCA\OpenRegister\Service\Flow\FlowItems
 * @uses   \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteBulk
 * @uses   \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteBulkPage
 * @uses   \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteConfigValidator
 * @uses   \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteItem
 * @uses   \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteOptions
 * @uses   \OCA\OpenRegister\Service\Flow\Nodes\ObjectWritePayload
 * @uses   \OCA\OpenRegister\Service\Flow\Nodes\ObjectWritePlan
 * @uses   \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteTemplate
 */
final class ObjectWriteAccessGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/object-write-access-golden.json';

	/**
	 * What the store was asked to do, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * The user the fake session currently answers for.
	 *
	 * @var string
	 */
	private string $acting = 'session';

	/**
	 * The rows `findAll()` answers in the current case.
	 *
	 * @var array<int, mixed>
	 */
	private array $found = [];

	/**
	 * What `deleteObject()` answers in the current case.
	 *
	 * @var boolean
	 */
	private bool $deletes = true;

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

		self::assertGreaterThan(25, count($actual));
		self::assertNotSame([], array_filter($actual, static fn (array $one): bool => isset($one['out'])));
		self::assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), $actual);

	}//end testCasesMatchTheRecording()

	/**
	 * The cases.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$update = ['operation' => 'update', 'fields' => ['a' => 1], 'match' => ['uuid' => '{{id}}']];
		$upsert = array_merge($update, ['operation' => 'upsert']);
		$delete = ['operation' => 'delete', 'confirmDelete' => true, 'match' => ['uuid' => '{{id}}']];
		$create = ['operation' => 'create', 'fields' => ['a' => 1]];

		return [
			'owner absent' => ['config' => $create, 'context' => []],
			'owner not a string' => ['config' => $create, 'context' => ['runAs' => 5]],
			'owner blank' => ['config' => $create, 'context' => ['runAs' => '  ']],
			'owner unknown' => ['config' => $create, 'context' => ['runAs' => 'ghost']],
			'owner disabled' => ['config' => $create, 'context' => ['runAs' => 'gone']],
			'owner padded' => ['config' => $create, 'context' => ['runAs' => ' alice ']],
			'delete by a disabled owner' => ['config' => $delete, 'context' => ['runAs' => 'gone'], 'found' => 1],
			'create' => ['config' => $create],
			'create claims' => ['config' => array_merge($create, ['onConflict' => 'fail'])],
			'bulk create' => ['config' => array_merge($create, ['bulk' => true])],
			'update patches' => ['config' => $update, 'found' => 1],
			'update replaces' => ['config' => array_merge($update, ['replace' => true]), 'found' => 1],
			'update misses' => ['config' => $update],
			'upsert inserts' => ['config' => $upsert],
			'upsert claims' => ['config' => array_merge($upsert, ['onConflict' => 'fail'])],
			'upsert patches' => ['config' => $upsert, 'found' => 1],
			'match two' => ['config' => $update, 'found' => 2],
			'match the scan limit' => ['config' => $update, 'found' => 100],
			'match ignores other rows' => ['config' => $update, 'found' => 1, 'extra' => ['text', 5]],
			'match only other rows' => ['config' => $update, 'extra' => ['text']],
			'match value unresolved' => ['config' => array_merge($update, ['match' => ['uuid' => '{{none}}']]), 'found' => 1],
			'match self uuid' => ['config' => array_merge($update, ['match' => ['@self.uuid' => 'x', '@self.owner' => '{{id}}']]), 'found' => 1],
			'match self unknown' => ['config' => array_merge($update, ['match' => ['@self.nope' => 'x']])],
			'match property wins' => ['config' => array_merge($update, ['match' => ['name' => 'x']]), 'found' => 1, 'properties' => ['name' => []]],
			'match metadata name' => ['config' => array_merge($update, ['match' => ['name' => 'x', 'owner' => 'y']]), 'found' => 1, 'properties' => ['title' => []]],
			'match unknown property' => ['config' => array_merge($update, ['match' => ['nope' => 'x']]), 'properties' => ['title' => []]],
			'match unknown, no declaration' => ['config' => array_merge($update, ['match' => ['nope' => 'x']]), 'found' => 1],
			'delete soft' => ['config' => $delete, 'found' => 1],
			'delete permanent' => ['config' => array_merge($delete, ['permanent' => true]), 'found' => 1],
			'delete with output' => ['config' => array_merge($delete, ['output' => 'gone']), 'found' => 1],
			'delete refused by the service' => ['config' => $delete, 'found' => 1, 'deletes' => false],
			'delete misses' => ['config' => $delete],
			'delete skips' => ['config' => array_merge($delete, ['onNoMatch' => 'skip'])],
			'delete skips with output' => ['config' => array_merge($delete, ['onNoMatch' => 'skip', 'output' => ' gone '])],
			'delete capped' => ['config' => array_merge($delete, ['maxWrites' => 1]), 'found' => 1, 'items' => 2],
			'schema slug in the register' => ['config' => array_merge($create, ['schema' => 'mine'])],
			'schema slug elsewhere' => ['config' => array_merge($create, ['schema' => 'foreign'])],
			'schema id unknown' => ['config' => array_merge($create, ['schema' => '99'])],
			'register unknown' => ['config' => array_merge($create, ['register' => '99'])],
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
		$this->acting = 'session';
		$this->deletes = ($case['deletes'] ?? true);
		$this->found = array_merge(
			array_map(fn (int $row): ObjectEntity => $this->entity(uuid: 'stored-' . $row), range(1, max(1, ($case['found'] ?? 0)))),
			($case['extra'] ?? [])
		);
		if (($case['found'] ?? 0) === 0) {
			$this->found = ($case['extra'] ?? []);
		}

		$context = [FlowRunServiceInterface::RUN_AS_CONTEXT_KEY => 'alice'];
		if (array_key_exists('context', $case) === true) {
			$context = array_filter(
				[FlowRunServiceInterface::RUN_AS_CONTEXT_KEY => ($case['context']['runAs'] ?? null)],
				static fn (mixed $value): bool => $value !== null
			);
		}

		$items = array_fill(0, ($case['items'] ?? 1), FlowItems::item(json: ['id' => 'u1', 'kept' => true]));
		$config = array_merge(['register' => '1', 'schema' => '2'], $case['config']);

		$outcome = [];
		try {
			$outcome['out'] = $this->node(properties: ($case['properties'] ?? []))->execute(new FlowNodeCall(items: $items, config: $config, context: $context));
		} catch (SchemaNotInRegisterException $e) {
			$outcome['failure'] = get_class($e) . ': ' . $e->getMessage();
		} catch (Throwable $e) {
			$outcome['failure'] = get_class($e) . ': ' . $e->getMessage() . ' <- ' . get_class((object)$e->getPrevious());
		}

		$outcome['calls'] = $this->calls;
		$outcome['session after'] = $this->acting;
		return json_decode((string)preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f-]{21}/', 'uuid', (string)json_encode($outcome)), true);

	}//end runCase()

	/**
	 * A stored object.
	 *
	 * @param string $uuid Its uuid.
	 *
	 * @return ObjectEntity The object.
	 */
	private function entity(string $uuid): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject(['stored' => true]);
		return $entity;

	}//end entity()

	/**
	 * A user.
	 *
	 * @param string $uid     The uid.
	 * @param bool   $enabled Whether the account is enabled.
	 *
	 * @return IUser The user.
	 */
	private function user(string $uid, bool $enabled): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('isEnabled')->willReturn($enabled);
		return $user;

	}//end user()

	/**
	 * A node over recording fakes.
	 *
	 * @param array<string, mixed> $properties The schema's declared properties.
	 *
	 * @return ObjectWriteNode The node.
	 */
	private function node(array $properties): ObjectWriteNode {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			function (string $uid): ?IUser {
				$this->calls[] = ['user lookup' => $uid];
				return ['alice' => $this->user(uid: 'alice', enabled: true), 'gone' => $this->user(uid: 'gone', enabled: false)][$uid] ?? null;
			}
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturnArgument(2);

		return new ObjectWriteNode(
			new ObjectWriteStores($this->store(), $this->registers(), $this->schemas(properties: $properties)),
			new ObjectWritePlatform($users, $config),
			new FlowNodePresentation($l10n, $this->createMock(IURLGenerator::class))
		);

	}//end node()

	/**
	 * A register mapper knowing register 1, which holds schemas 2 and 3.
	 *
	 * @return RegisterMapperInterface The fake.
	 */
	private function registers(): RegisterMapperInterface {
		$register = new Register();
		$register->setId(1);
		$register->setSlug('reg');
		$register->setSchemas([2, 3]);

		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturnCallback(
			function (int|string $id, bool $_rbac = true, bool $_multitenancy = true) use ($register): Register {
				$this->calls[] = ['register' => $id, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				if ((string)$id === '99') {
					throw new RuntimeException('no such register');
				}

				return $register;
			}
		);

		return $registers;

	}//end registers()

	/**
	 * A schema mapper knowing schema 2, and `mine` only within the register's ids.
	 *
	 * @param array<string, mixed> $properties The schema's declared properties.
	 *
	 * @return SchemaMapperInterface The fake.
	 */
	private function schemas(array $properties): SchemaMapperInterface {
		$schema = new Schema();
		$schema->setId(2);
		$schema->setProperties($properties);

		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true) use ($schema): Schema {
				$this->calls[] = ['schema' => $id, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				if ((string)$id === '99') {
					throw new RuntimeException('no such schema');
				}

				return $schema;
			}
		);
		$schemas->method('findBySlugInIds')->willReturnCallback(
			function (string $slug, array $schemaIds) use ($schema): ?Schema {
				$this->calls[] = ['schema slug' => $slug, 'within' => $schemaIds];
				if ($slug === 'mine') {
					return $schema;
				}

				return null;
			}
		);
		$schemas->method('countBySlug')->willReturn(4);

		return $schemas;

	}//end schemas()

	/**
	 * A store that records every call under the user it ran as.
	 *
	 * @return ObjectOperationsInterface The fake.
	 */
	private function store(): ObjectOperationsInterface {
		$store = $this->createMock(ObjectOperationsInterface::class);
		$store->method('runAs')->willReturnCallback(
			function (IUser $user, callable $work): mixed {
				$before = $this->acting;
				$this->acting = $user->getUID();
				try {
					return $work();
				} finally {
					$this->acting = $before;
				}
			}
		);
		$store->method('findAll')->willReturnCallback(
			function (array $config): array {
				$this->calls[] = ['findAll' => $config, 'as' => $this->acting];
				return $this->found;
			}
		);
		$store->method('saveObjects')->willReturnCallback(
			function (array $objects, BulkSaveOptions $options = new BulkSaveOptions(), bool $_rbac = true, bool $_multitenancy = true): array {
				$this->calls[] = ['saveObjects' => $objects, 'as' => $this->acting, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				return [];
			}
		);
		$store->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, mixed ...$rest): ObjectEntity {
				$this->calls[] = [
					'saveObject' => $object, 'as' => $this->acting, 'uuid' => ($rest[3] ?? null), 'currentUser' => $rest[9]?->getUID(),
					'failIfExists' => ($rest[10] ?? null), 'rbac' => ($rest[4] ?? null), 'multitenancy' => ($rest[5] ?? null),
				];
				return $this->entity(uuid: 'saved');
			}
		);
		$store->method('patchObject')->willReturnCallback(
			function (string $objectId, array $data, mixed ...$rest): ObjectEntity {
				$this->calls[] = ['patchObject' => $objectId, 'data' => $data, 'as' => $this->acting, 'arguments' => array_map(
					static fn (mixed $one): mixed => match (true) {
						$one instanceof IUser => $one->getUID(),
						is_object($one) => get_class($one),
						default => $one,
					},
					$rest
				)];
				return $this->entity(uuid: 'patched');
			}
		);
		$store->method('deleteObject')->willReturnCallback(
			function (string $uuid, mixed $register, mixed $schema, bool $_rbac, bool $_multitenancy, bool $sweep, ?IUser $currentUser, bool $permanent): bool {
				$this->calls[] = [
					'deleteObject' => $uuid, 'as' => $this->acting, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy,
					'sweep' => $sweep, 'currentUser' => $currentUser?->getUID(), 'permanent' => $permanent,
				];
				return $this->deletes;
			}
		);

		return $store;

	}//end store()
}//end class
