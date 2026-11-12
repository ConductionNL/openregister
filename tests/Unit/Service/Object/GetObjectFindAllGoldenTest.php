<?php

/**
 * Golden for GetObject::findAll
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Object
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\ObjectSource\SourcedTable;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicListRequest;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Interaction\ReadHistoryService;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\ObjectListRequest;
use OCA\OpenRegister\Service\Object\ObjectSelection;
use OCA\OpenRegister\Service\Object\ReadScope;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceProvider;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Where a list read goes (an object source or the magic table), with what, and what comes back.
 * Set OR_GOLDEN_RECORD=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Object\GetObject
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class GetObjectFindAllGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/get-object-find-all-golden.json';

	/**
	 * Every call made to the mapper or the provider.
	 *
	 * @var array<int, mixed>
	 */
	private array $calls = [];

	/**
	 * A GetObject over a table of two rows and a registry holding an enabled `live` and a disabled `off` provider.
	 *
	 * @return GetObject
	 */
	private function getObject(): GetObject {
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('findAllListed')->willReturnCallback(
			function (MagicListRequest $request): array {
				$q    = $request->query;
				$args = [$q->limitOrNull(), $q->offset, $q->filters, null, null, $q->sort, $q->search, $request->ids, $request->uses, false];
				$args[10] = $request->table?->register->getId();
				$args[11] = $request->table?->schema->getId();
				$this->calls[] = ['table', $args];
				$rows = [];
				foreach (['r1' => ['a' => 1], 'r2' => ['b' => 2]] as $uuid => $data) {
					$row = new ObjectEntity();
					$row->setUuid($uuid);
					$row->setObject($data);
					$rows[$uuid] = $row;
				}

				return $rows;
			}
		);

		$calls = &$this->calls;
		$provider = static function (bool $enabled) use (&$calls): object {
			return new class($enabled, $calls) implements ObjectSourceProvider {
				public function __construct(private bool $enabled, private array &$calls) {
				}

				public function getId(): string {
					return 'live';
				}

				public function find(SourcedTable $source, string $id): ?ObjectEntity {
					return null;
				}

				public function count(SourcedTable $source, array $query = []): int {
					return 0;
				}

				public function isEnabled(): bool {
					return $this->enabled;
				}

				public function findAll(SourcedTable $source, array $query = []): array {
					$register = $source->table->register;
					$schema   = $source->table->schema;
					$config   = $source->config;
					$this->calls[] = ['source', $register->getId(), $schema->getId(), $query, $config];
					return [['live' => true]];
				}
			};
		};
		$registry = $this->createMock(ObjectSourceRegistry::class);
		$registry->method('get')->willReturnCallback(
			static fn (string $name): ?object => match ($name) {
				'live' => $provider(true),
				'off' => $provider(false),
				default => null,
			}
		);

		return new GetObject(
			objectMapper: $mapper,
			auditTrailMapper: $this->createMock(AuditTrailMapperInterface::class),
			readHistory: $this->createMock(ReadHistoryService::class),
			sources: new \OCA\OpenRegister\Service\Object\GetObjectSources(
				objectSourceRegistry: $registry,
				logger: new NullLogger()
			)
		);
	}//end getObject()

	/**
	 * A schema, optionally served from an object source.
	 *
	 * @param array|null $source The object-source declaration.
	 *
	 * @return Schema
	 */
	private static function schema(?array $source): Schema {
		$schema = new Schema();
		$schema->setId(4);
		$schema->setSlug('s');
		if ($source !== null) {
			$schema->setConfiguration(['x-openregister-object-source' => $source]);
		}

		return $schema;
	}//end schema()

	/**
	 * The listing request a case's named arguments describe.
	 *
	 * @param array<string, mixed> $args The case.
	 *
	 * @return ObjectListRequest
	 */
	private static function request(array $args): ObjectListRequest {
		return new ObjectListRequest(
			query: new ListQuery(
				limit: ($args['limit'] ?? ListQuery::UNBOUNDED),
				offset: ($args['offset'] ?? null),
				filters: ($args['filters'] ?? []),
				sort: ($args['sort'] ?? []),
				search: ($args['search'] ?? null)
			),
			scope: new ReadScope(register: ($args['register'] ?? null), schema: ($args['schema'] ?? null)),
			selection: new ObjectSelection(ids: ($args['ids'] ?? null), uses: ($args['uses'] ?? null)),
			files: ($args['files'] ?? false)
		);
	}//end request()

	public function testEveryRouteMatchesTheGolden(): void {
		$register = new Register();
		$register->setId(9);
		$full = ['limit' => 5, 'offset' => 2, 'filters' => ['x' => 1], 'sort' => ['y' => 'asc'], 'search' => 'q', '_extend' => ['z'], 'uses' => 'u', 'register' => $register, 'ids' => ['i']];
		$cases = [
			'table, defaults'      => [],
			'table, everything'    => $full + ['schema' => self::schema(null), '_rbac' => false, '_multitenancy' => false],
			'table, files'         => ['files' => true, 'filters' => ['_rbac' => 'overwritten']],
			'source'               => $full + ['schema' => self::schema(['provider' => 'live', 'config' => ['k' => 'v']]), 'files' => true],
			'source without config' => ['schema' => self::schema(['provider' => 'live']), 'register' => $register],
			'source disabled'      => ['schema' => self::schema(['provider' => 'off'])],
			'source missing'       => ['schema' => self::schema(['provider' => 'gone'])],
		];
		$actual = [];
		foreach ($cases as $name => $args) {
			$this->calls = [];
			$rows = $this->getObject()->findAll(
				request: self::request(args: $args),
				_rbac: ($args['_rbac'] ?? true),
				_multitenancy: ($args['_multitenancy'] ?? true)
			);
			$actual[$name] = [
				'rows'  => array_map(static fn ($row) => $row instanceof ObjectEntity ? [$row->getUuid(), $row->getObject()] : $row, $rows),
				'calls' => $this->calls,
			];
		}

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$actual   = json_decode((string) json_encode($actual), true);
		$this->assertSame(expected: $expected, actual: $actual);
	}//end testEveryRouteMatchesTheGolden()
}//end class
