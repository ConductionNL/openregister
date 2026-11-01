<?php

/**
 * RelationHandler::getUses() and getUsedBy() load, filter, page and label relations exactly as recorded.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\PerformanceHandler;
use OCA\OpenRegister\Service\Object\RelationHandler;
use OCA\OpenRegister\Service\Relation\RelationTypeResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden record of both relation lookups over every branch: the pinned register and schema
 * loads, the object find, the cross-table location, each per-table read with its arguments, the
 * fallback to located rows, the access filter with the questions it asks, paging, the relation
 * labels and the log lines (without file and line). The access doubles answer only for the
 * session user; forged user, owner and requester values travel in the query and in the stored
 * object data. Set OR_GOLDEN_RECORD=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Object\RelationHandler
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Object\DraftCallerRule
 * @uses   \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses   \OCA\OpenRegister\Service\Object\PinnedTable
 * @uses   \OCA\OpenRegister\Service\Object\ReferrerPage
 * @uses   \OCA\OpenRegister\Service\Object\RelatedObjectSet
 * @uses   \OCA\OpenRegister\Service\Object\RelationLink
 * @uses   \OCA\OpenRegister\Service\Object\RelationSource
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.TooManyFields)
 */
class RelationHandlerLookupsGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/relation-handler-lookups-golden.json';

	private const FORGED_QUERY = [
		'userId' => 'mallory',
		'_owner' => 'mallory',
		'owner' => 'mallory',
		'requestedBy' => 'mallory',
		'_user' => 'mallory',
		'@self' => ['owner' => 'mallory'],
	];

	/** @var array<int, mixed> */
	private array $calls = [];

	/** @var string|null */
	private ?string $uid = 'alice';

	/** @var array<int, string> */
	private array $admins = ['root'];

	/** @var array<string, array<int, string>> */
	private array $readableByUser = [];

	/** @var array<string, mixed> */
	private array $world = [];

	/**
	 * Every scenario matches the recorded golden.
	 *
	 * @return void
	 */
	public function testScenariosMatchTheGolden(): void {
		$readable = ['alice' => ['b1', 'b2', 'c1', 'c2', 'd1', 'n1', 'x1'], 'bob' => ['b1']];
		$lead = ['uuid' => 'lead', 'register' => '1', 'schema' => '2', 'relations' => ['one' => 'b1', 'many' => ['c1', 'https://x/objects/c2', '', 7], 'self' => 'lead', 'url' => 'https://x/b2']];
		$b1 = ['uuid' => 'b1', 'register' => '1', 'schema' => '3', 'owner' => 'alice', 'object' => ['title' => 'B1', '_owner' => 'mallory', '_organisation' => 'forged']];
		$b2 = ['uuid' => 'b2', 'register' => '1', 'schema' => '3', 'owner' => 'bob', 'organisation' => 'org-a'];
		$c1 = ['uuid' => 'c1', 'register' => '4', 'schema' => '5', 'owner' => 'carol', 'authorization' => ['read' => ['group-x']]];
		$c2 = ['uuid' => 'c2', 'register' => '4', 'schema' => '5', 'owner' => 'carol'];
		$draft = ['uuid' => 'd1', 'register' => '1', 'schema' => '3', 'owner' => 'bob', 'status' => 'draft'];
		$nullSchema = ['uuid' => 'n1', 'register' => '1', 'schema' => null, 'owner' => 'bob'];
		$loose = ['uuid' => 'x1', 'register' => 'abc', 'schema' => '3', 'owner' => 'bob'];
		$all = ['b1' => $b1, 'b2' => $b2, 'c1' => $c1, 'c2' => $c2];
		$tables = ['1_3' => ['b1', 'b2'], '4_5' => ['c1', 'c2']];
		$base = ['lead' => $lead, 'located' => array_values($all), 'tables' => $tables, 'objects' => $all, 'readable' => $readable];

		$actual = [];
		foreach ($this->usesScenarios(base: $base, extra: ['draft' => $draft, 'nullSchema' => $nullSchema, 'loose' => $loose]) as $name => $scenario) {
			$actual['uses: ' . $name] = $this->lookup(method: 'getUses', scenario: $scenario);
		}

		foreach ($this->usedByScenarios(base: $base) as $name => $scenario) {
			$actual['usedBy: ' . $name] = $this->lookup(method: 'getUsedBy', scenario: $scenario);
		}

		$actual['both on one handler'] = $this->lookup(method: 'getUses', scenario: $base + ['then' => 'getUsedBy']);

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(expected: $expected, actual: json_decode((string) json_encode($actual, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true));
	}//end testScenariosMatchTheGolden()

	/**
	 * The getUses() scenarios.
	 *
	 * @param array<string, mixed> $base  The shared world.
	 * @param array<string, mixed> $extra Extra stored objects.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function usesScenarios(array $base, array $extra): array {
		$lead = $base['lead'];
		$withDraft = $base;
		$withDraft['lead']['relations'] = ['d' => 'd1', 'n' => 'n1', 'b' => 'b1', 'x' => 'x1'];
		$withDraft['located'] = [$extra['draft'], $extra['nullSchema'], $base['objects']['b1'], $extra['loose']];
		$withDraft['objects'] += ['d1' => $extra['draft'], 'n1' => $extra['nullSchema'], 'x1' => $extra['loose']];
		$withDraft['tables'] = ['1_3' => ['b1']];

		return [
			'no relations'                 => ['lead' => ['relations' => []] + $lead, 'query' => ['_limit' => '5', '_offset' => '2']],
			'null relations'               => ['lead' => ['relations' => null] + $lead],
			'only blanks and zero'         => ['lead' => ['relations' => ['a' => '', 'b' => '0', 'c' => ['', '0', 3, null], 'd' => 5]] + $lead],
			'only itself'                  => ['lead' => ['relations' => ['self' => 'lead', 'again' => ['lead']]] + $lead],
			'itself with a null uuid'      => ['lead' => ['uuid' => null, 'relations' => ['a' => 'b1']] + $lead, 'located' => [$base['objects']['b1']], 'tables' => ['1_3' => ['b1']], 'objects' => $base['objects'], 'readable' => $base['readable']],
			'all found in tables'          => $base,
			'forged query and data'        => $base + ['query' => self::FORGED_QUERY],
			'another session user'         => $base + ['uid' => 'bob'],
			'anonymous'                    => $base + ['uid' => null],
			'administrator'                => $base + ['uid' => 'root'],
			'access filter off'            => $base + ['rbac' => false, 'uid' => 'bob'],
			'drafts, null schema, loose'   => $withDraft,
			'drafts as their owner'        => $withDraft + ['uid' => 'bob', 'readable' => ['bob' => ['d1', 'b1', 'x1']]],
			'schema lookup fails in filter' => $base + ['schemaFails' => [5]],
			'one pair cannot load'         => $base + ['registerFails' => [4]],
			'one table read throws'        => $base + ['tableFails' => ['4_5']],
			'table answers extras and repeats' => ['tableExtra' => ['1_3' => ['zz', 'b1', 'b1']]] + $base,
			'table answers nothing'        => ['tables' => ['1_3' => [], '4_5' => []]] + $base,
			'located twice'                => ['located' => array_merge($base['located'], [$base['objects']['c1']]), 'tables' => ['1_3' => ['b1', 'b2']]] + $base,
			'nothing located'              => ['located' => []] + $base,
			'paged'                        => $base + ['query' => ['_limit' => 2, '_offset' => 1]],
			'paged with strings'           => $base + ['query' => ['_limit' => '1', '_offset' => '3']],
			'paged past the end'           => $base + ['query' => ['_limit' => 10, '_offset' => 9]],
			'without a resolver'           => $base + ['noResolver' => true],
			'pinned register and schema'   => $base + ['pin' => [1, 2]],
			'pinned register only'         => $base + ['pin' => [1, null]],
			'pinned schema only'           => $base + ['pin' => [null, 2]],
			'pinned register fails'        => $base + ['pin' => [1, 2], 'registerFails' => [1]],
			'pinned schema fails'          => $base + ['pin' => [1, 2], 'schemaFails' => [2]],
			'source schema not numeric'    => ['lead' => ['schema' => 'abc'] + $lead] + $base,
			'source schema fails'          => $base + ['schemaFails' => [2]],
			'object not found'             => $base + ['findFails' => true, 'query' => ['_limit' => 3]],
		];
	}//end usesScenarios()

	/**
	 * The getUsedBy() scenarios.
	 *
	 * @param array<string, mixed> $base The shared world.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function usedByScenarios(array $base): array {
		$referrers = $base;
		$referrers['objects']['b1']['relations'] = ['owner' => 'lead'];
		$referrers['objects']['b2']['relations'] = ['list' => ['x', 'https://x/lead']];
		$referrers['objects']['c1']['relations'] = ['other' => 'nope'];
		$referrers['objects']['c2']['relations'] = null;
		$referrers['objects']['lead'] = $base['lead'];
		$referrers['located'] = array_values($referrers['objects']);
		$referrers['tables'] = ['1_3' => ['b1', 'lead', 'b2'], '4_5' => ['c1', 'c2']];
		$referrers['lead']['register'] = '1';

		return [
			'referrers in two tables'      => $referrers,
			'forged query'                 => $referrers + ['query' => self::FORGED_QUERY],
			'another session user'         => $referrers + ['uid' => 'bob'],
			'limit reached in first table' => $referrers + ['query' => ['_limit' => 2]],
			'limit as a string'            => $referrers + ['query' => ['_limit' => '3', '_offset' => '1']],
			'offset spans tables'          => $referrers + ['query' => ['_limit' => 5, '_offset' => 4]],
			'one pair cannot load'         => $referrers + ['registerFails' => [1]],
			'one table read throws'        => $referrers + ['tableFails' => ['1_3']],
			'nothing references it'        => ['located' => []] + $referrers,
			'loose located row'            => ['located' => [['uuid' => 'q', 'register' => null, 'schema' => '3']]] + $referrers,
			'without a resolver'           => $referrers + ['noResolver' => true],
			'pinned register and schema'   => $referrers + ['pin' => [1, 2]],
			'pinned register only'         => $referrers + ['pin' => [1, null]],
			'pinned schema fails'          => $referrers + ['pin' => [1, 2], 'schemaFails' => [2]],
			'object not found'             => $referrers + ['findFails' => true, 'query' => ['_offset' => 4]],
			'access filter off'            => $referrers + ['rbac' => false],
		];
	}//end usedByScenarios()

	/**
	 * Run one lookup on a fresh handler and record what happened.
	 *
	 * @param string               $method   getUses or getUsedBy.
	 * @param array<string, mixed> $scenario The world and the request.
	 *
	 * @return array<string, mixed>
	 */
	private function lookup(string $method, array $scenario): array {
		$this->calls = [];
		$this->uid = array_key_exists('uid', $scenario) === true ? $scenario['uid'] : 'alice';
		$this->readableByUser = $scenario['readable'] ?? [];
		$this->world = $scenario;

		$handler = $this->handler(noResolver: ($scenario['noResolver'] ?? false));
		$pin = $scenario['pin'] ?? [null, null];
		$result = $handler->$method(
			objectId: 'lead-id',
			query: ($scenario['query'] ?? []),
			_rbac: ($scenario['rbac'] ?? true),
			_multitenancy: true,
			_registerId: $pin[0],
			_schemaId: $pin[1],
		);
		$record = ['result' => $this->rows(result: $result), 'calls' => $this->calls];

		if (isset($scenario['then']) === true) {
			$this->calls = [];
			$then = $scenario['then'];
			$record['then'] = ['result' => $this->rows(result: $handler->$then(objectId: 'lead-id')), 'calls' => $this->calls];
		}

		return $record;
	}//end lookup()

	/**
	 * The result with each row reduced to what the lookup decides.
	 *
	 * @param array<string, mixed> $result The lookup's answer.
	 *
	 * @return array<string, mixed>
	 */
	private function rows(array $result): array {
		$result['results'] = array_map(
			static function ($row) {
				if (is_array($row) === false) {
					return ['not an array' => get_debug_type($row)];
				}

				$self = $row['@self'] ?? [];
				unset($row['@self']);
				$row['@self'] = ['name' => $self['name'] ?? null, 'owner' => $self['owner'] ?? null, 'schema' => $self['schema'] ?? null];
				return $row;
			},
			$result['results']
		);
		return $result;
	}//end rows()

	/**
	 * Build the handler over recording doubles.
	 *
	 * @param bool $noResolver Whether the relation type resolver is absent.
	 *
	 * @return RelationHandler
	 */
	private function handler(bool $noResolver): RelationHandler {
		$magic = $this->createMock(MagicMapper::class);
		$magic->method('find')->willReturnCallback(fn (...$args) => $this->find(args: $args));
		$magic->method('findMultipleAcrossAllMagicTables')->willReturnCallback(
			function (...$args): array {
				$this->record(name: 'findMultipleAcrossAllMagicTables', args: $args);
				return array_map(fn (array $data): ObjectEntity => $this->entity(data: $data), ($this->world['located'] ?? []));
			}
		);
		$magic->method('findByRelationAcrossAllMagicTables')->willReturnCallback(
			function (...$args): array {
				$this->record(name: 'findByRelationAcrossAllMagicTables', args: $args);
				return array_map(fn (array $data): ObjectEntity => $this->entity(data: $data), ($this->world['located'] ?? []));
			}
		);
		$magic->method('findAllInRegisterSchemaTable')->willReturnCallback(fn (...$args): array => $this->tableRead(args: $args));

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(
			function (...$args): Register {
				$this->record(name: 'register.find', args: $args);
				if (in_array((int) $args[0], ($this->world['registerFails'] ?? []), true) === true) {
					throw new Exception('register ' . $args[0] . ' gone');
				}

				$register = new Register();
				$register->setId((int) $args[0]);
				return $register;
			}
		);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			function (...$args): Schema {
				$this->record(name: 'schema.find', args: $args);
				if (in_array((int) $args[0], ($this->world['schemaFails'] ?? []), true) === true) {
					throw new Exception('schema ' . $args[0] . ' gone');
				}

				$schema = new Schema();
				$schema->setId((int) $args[0]);
				return $schema;
			}
		);

		$rbac = $this->createMock(MagicRbacHandler::class);
		$rbac->method('isAdmin')->willReturnCallback(fn (): bool => in_array($this->uid, $this->admins, true));
		$rbac->method('getCurrentUserId')->willReturnCallback(fn (): ?string => $this->uid);
		$rbac->method('hasPermission')->willReturnCallback(
			function (...$args): bool {
				$this->record(name: 'hasPermission', args: $args);
				$uuid = $args[5] ?? null;
				return $this->uid !== null && in_array($uuid, ($this->readableByUser[$this->uid] ?? []), true) === true;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'warning', 'error', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (...$args) use ($level): void {
					$context = $args[1] ?? [];
					unset($context['file'], $context['line']);
					$this->record(name: 'log.' . $level, args: [$args[0], $context]);
				}
			);
		}

		$resolver = null;
		if ($noResolver === false) {
			$resolver = $this->createMock(RelationTypeResolver::class);
			$resolver->method('descriptorFor')->willReturnCallback(
				function (...$args): ?array {
					$this->record(name: 'descriptorFor', args: $args);
					return $args[1] === 'many.0' ? null : ['label' => 'L:' . $args[1]];
				}
			);
			$resolver->method('row')->willReturnCallback(
				function (...$args): array {
					$this->record(name: 'row', args: $args);
					return ['descriptor' => $args[0], 'direction' => $args[1], 'path' => $args[2] ?? null];
				}
			);
		}

		return new RelationHandler(
			objectEntityMapper: $magic,
			schemaMapper: $schemaMapper,
			performanceHandler: $this->createMock(PerformanceHandler::class),
			rbacHandler: $rbac,
			logger: $logger,
			registerMapper: $registerMapper,
			relationTypes: $resolver
		);
	}//end handler()

	/**
	 * The object find double.
	 *
	 * @param array<int, mixed> $args The call's arguments.
	 *
	 * @return ObjectEntity
	 */
	private function find(array $args): ObjectEntity {
		$this->record(name: 'find', args: $args);
		if (($this->world['findFails'] ?? false) === true) {
			throw new Exception('lead gone');
		}

		return $this->entity(data: $this->world['lead']);
	}//end find()

	/**
	 * The per-table read double: answers the table's uuids, honouring limit and offset.
	 *
	 * @param array<int, mixed> $args The call's arguments.
	 *
	 * @return ObjectEntity[]
	 */
	private function tableRead(array $args): array {
		$this->record(name: 'findAllInRegisterSchemaTable', args: $args);
		$key = $args[0]->getId() . '_' . $args[1]->getId();
		if (in_array($key, ($this->world['tableFails'] ?? []), true) === true) {
			throw new Exception('table ' . $key . ' gone');
		}

		$uuids = $this->world['tableExtra'][$key] ?? ($this->world['tables'][$key] ?? []);
		$uuids = array_slice($uuids, (int) ($args[3] ?? 0), ($args[2] ?? null));
		$objects = $this->world['objects'] ?? [];
		return array_map(fn (string $uuid): ObjectEntity => $this->entity(data: ($objects[$uuid] ?? ['uuid' => $uuid, 'register' => '9', 'schema' => '9'])), $uuids);
	}//end tableRead()

	/**
	 * Build a stored object.
	 *
	 * @param array<string, mixed> $data The stored fields.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($data['uuid'] ?? null);
		$entity->setRegister($data['register'] ?? null);
		$entity->setSchema($data['schema'] ?? null);
		$entity->setRelations(array_key_exists('relations', $data) === true ? $data['relations'] : []);
		$entity->setOwner($data['owner'] ?? null);
		$entity->setOrganisation($data['organisation'] ?? null);
		$entity->setObject($data['object'] ?? []);
		$entity->setAuthorization($data['authorization'] ?? []);
		$entity->setStatus($data['status'] ?? null);
		return $entity;
	}//end entity()

	/**
	 * Record one double call with its arguments reduced to comparable values.
	 *
	 * @param string            $name The double.
	 * @param array<int, mixed> $args The arguments.
	 *
	 * @return void
	 */
	private function record(string $name, array $args): void {
		$this->calls[] = [$name, $this->plain(value: $args)];
	}//end record()

	/**
	 * A value reduced to JSON-safe terms.
	 *
	 * @param mixed $value Any value.
	 *
	 * @return mixed
	 */
	private function plain(mixed $value): mixed {
		if ($value instanceof Register === true) {
			return 'Register#' . $value->getId();
		}

		if ($value instanceof Schema === true) {
			return 'Schema#' . $value->getId();
		}

		if ($value instanceof ObjectEntity === true) {
			return 'Object#' . $value->getUuid();
		}

		if (is_array($value) === true) {
			return array_map(fn ($item) => $this->plain(value: $item), $value);
		}

		return $value;
	}//end plain()
}//end class
