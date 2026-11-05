<?php

/**
 * QueryHandler::searchObjects() applies views, scopes to the session organisation and renders exactly as recorded.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\ContentSearchHandler;
use OCA\OpenRegister\Service\Object\FacetHandler;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\PerformanceOptimizationHandler;
use OCA\OpenRegister\Service\Object\QueryHandler;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\Object\SearchReferenceResolver;
use OCP\AppFramework\IAppContainer;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden record of searchObjects() over every branch: reference normalisation, view application and
 * the view-scope refusal, the organisation the mapper search runs for (the session user's, never a
 * value from the query), the flags it receives, the _count short-circuit, the per-schema
 * property-authorization probe and its cache, and the render call that filters properties.
 * Set OR_GOLDEN_RECORD=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Object\QueryHandler
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Service\Object\SearchRenderRequest
 * @uses   \OCA\OpenRegister\Service\Object\SearchResultShaper
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 */
class QueryHandlerSearchObjectsGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/query-handler-search-objects-golden.json';

	/** @var array<int, array<string, mixed>> */
	private array $calls = [];

	/** @var array<string, mixed> */
	private array $setup = [];

	/**
	 * Every scenario matches the recorded golden.
	 *
	 * @return void
	 */
	public function testScenariosMatchTheGolden(): void {
		$plain   = ['10' => false, '11' => false];
		$guarded = ['10' => false, '11' => true];
		$actual  = [
			'plain search'                       => $this->runScenario(['query' => ['a' => 1], 'schemas' => $plain]),
			'no results'                         => $this->runScenario(['query' => [], 'rows' => []]),
			'resolver absent'                    => $this->runScenario(['query' => ['_schema' => 'slug'], 'resolver' => false]),
			'resolver rewrites'                  => $this->runScenario(['query' => ['_schema' => 'slug']]),
			'resolver refuses'                   => $this->runScenario(['query' => ['_schema' => 'missing']]),
			'views applied'                      => $this->runScenario(['query' => ['a' => 1], 'views' => ['v1']]),
			'views applied, scope required'      => $this->runScenario(['query' => [], 'views' => ['v1'], 'required' => true]),
			'empty views, scope required'        => $this->runScenario(['query' => [], 'views' => [], 'required' => true]),
			'no views, scope required'           => $this->runScenario(['query' => [], 'required' => true]),
			'empty views'                        => $this->runScenario(['query' => [], 'views' => []]),
			'view adds _extend'                  => $this->runScenario(['query' => [], 'views' => ['v2'], 'viewAdds' => ['_extend' => ['x']]]),
			'flags passed through'               => $this->runScenario(['query' => [], 'rbac' => false, 'ids' => ['i1'], 'uses' => 'u1']),
			'multitenancy off: no organisation'  => $this->runScenario(['query' => [], 'multi' => false]),
			'forged org and user in query'       => $this->runScenario(['query' => ['_organisation' => 'org-mallory', 'organisation' => 'org-mallory', '_activeOrgUuid' => 'org-mallory', 'userId' => 'mallory', '@self' => ['organisation' => 'org-mallory', 'owner' => 'mallory']]]),
			'other session user'                 => $this->runScenario(['query' => ['userId' => 'alice'], 'session' => 'bob']),
			'no session user'                    => $this->runScenario(['query' => [], 'session' => null]),
			'count true'                         => $this->runScenario(['query' => ['_count' => true], 'schemas' => $guarded]),
			'count string true'                  => $this->runScenario(['query' => ['_count' => 'true']]),
			'count 1 is not count'               => $this->runScenario(['query' => ['_count' => 1]]),
			'count string 1 is not count'        => $this->runScenario(['query' => ['_count' => '1']]),
			'count false'                        => $this->runScenario(['query' => ['_count' => false]]),
			'count with extend'                  => $this->runScenario(['query' => ['_count' => true, '_extend' => ['a']]]),
			'mapper returns int'                 => $this->runScenario(['query' => [], 'rows' => 7]),
			'mapper returns int, extend'         => $this->runScenario(['query' => ['_extend' => 'a'], 'rows' => 7]),
			'extend renders'                     => $this->runScenario(['query' => ['_extend' => ['a'], '_filter' => ['f'], '_fields' => ['x'], '_unset' => ['u']], 'rbac' => false, 'multi' => false]),
			'extend string renders'              => $this->runScenario(['query' => ['_extend' => 'a,b']]),
			'empty extend does not render'       => $this->runScenario(['query' => ['_extend' => '', '_fields' => [], '_filter' => null, '_unset' => '0']]),
			'fields render'                      => $this->runScenario(['query' => ['_fields' => 'title']]),
			'filter render'                      => $this->runScenario(['query' => ['_filter' => ['x']]]),
			'unset render'                       => $this->runScenario(['query' => ['_unset' => 'x']]),
			'property auth on second schema'     => $this->runScenario(['query' => [], 'schemas' => $guarded, 'rows' => ['10', '10', '11', '10']]),
			'property auth on first schema'      => $this->runScenario(['query' => [], 'schemas' => ['10' => true], 'rows' => ['10', '10', '11']]),
			'guarded first stops the probe'      => $this->runScenario(['query' => [], 'schemas' => ['10' => true, '11' => false], 'rows' => ['10', '11']]),
			'repeat guarded schema'              => $this->runScenario(['query' => [], 'schemas' => ['10' => true], 'rows' => ['10']]),
			'all plain, repeats cached'          => $this->runScenario(['query' => [], 'schemas' => $plain, 'rows' => ['10', '11', '10', '11']]),
			'schema lookup fails'                => $this->runScenario(['query' => [], 'schemas' => ['11' => true], 'rows' => ['99', '99', '11']]),
			'schema lookup fails only'           => $this->runScenario(['query' => [], 'rows' => ['99', '99']]),
			'null schema id'                     => $this->runScenario(['query' => [], 'schemas' => ['11' => true], 'rows' => [null, null, '11']]),
			'complex skips the probe'            => $this->runScenario(['query' => ['_fields' => ['a']], 'schemas' => $guarded]),
			'guarded, rbac off'                  => $this->runScenario(['query' => [], 'schemas' => $guarded, 'rbac' => false, 'multi' => false]),
		];

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(expected: $expected, actual: json_decode((string) json_encode($actual, JSON_UNESCAPED_SLASHES), true));
	}//end testScenariosMatchTheGolden()

	/**
	 * Run one searchObjects() call on a fresh handler and record what happened.
	 *
	 * @param array<string, mixed> $setup The scenario.
	 *
	 * @return array<string, mixed>
	 */
	private function runScenario(array $setup): array {
		$this->calls = [];
		$this->setup = $setup + ['session' => 'alice', 'rows' => ['10', '11'], 'schemas' => [], 'resolver' => true];
		$handler = $this->handler();

		try {
			$found = $handler->searchObjects(
				query: $setup['query'],
				_rbac: ($setup['rbac'] ?? true),
				_multitenancy: ($setup['multi'] ?? true),
				ids: ($setup['ids'] ?? null),
				uses: ($setup['uses'] ?? null),
				views: ($setup['views'] ?? null),
				_viewScopeRequired: ($setup['required'] ?? false)
			);
			$returned = is_int($found) === true ? ['int' => $found] : ['objects' => array_map(fn ($o) => is_array($o) ? $o : $o->getUuid(), $found)];
		} catch (\Throwable $e) {
			$message = preg_replace('/MockObject_(\w+?)_[0-9a-f]+/', 'MockObject_$1', $e->getMessage());
			$message = preg_replace('/, called in .*$/', '', (string) $message);
			$returned = ['exception' => [get_class($e), $message]];
		}

		return ['returned' => $returned, 'calls' => $this->calls];
	}//end runScenario()

	/**
	 * A handler over recording doubles. The organisation double answers only for the session user.
	 *
	 * @return QueryHandler
	 */
	private function handler(): QueryHandler {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturnCallback(
			function (): ?IUser {
				if ($this->setup['session'] === null) {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($this->setup['session']);
				return $user;
			}
		);

		$performance = $this->createMock(PerformanceOptimizationHandler::class);
		$performance->method('getActiveOrganisationForContext')->willReturnCallback(
			function () use ($userSession): ?string {
				$uid = $userSession->getUser()?->getUID();
				$this->calls[] = ['activeOrganisation for' => $uid];
				return $uid === null ? null : 'org-' . $uid;
			}
		);

		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('searchObjects')->willReturnCallback(
			function (array $query = [], ?string $_activeOrgUuid = null, bool $_rbac = true, bool $_multitenancy = true, ?array $ids = null, ?string $uses = null): array|int {
				$this->calls[] = ['mapper.searchObjects' => $query, 'org' => $_activeOrgUuid, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy, 'ids' => $ids, 'uses' => $uses];
				if (is_int($this->setup['rows']) === true) {
					return $this->setup['rows'];
				}

				$objects = [];
				foreach ($this->setup['rows'] as $index => $schemaId) {
					$object = new ObjectEntity();
					$object->setUuid('obj-' . $index);
					$object->setSchema($schemaId);
					$objects[] = $object;
				}

				return $objects;
			}
		);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			function ($id): Schema {
				$this->calls[] = ['schemaMapper.find' => $id];
				if (array_key_exists((string) $id, $this->setup['schemas']) === false) {
					throw new Exception('no schema ' . (string) $id);
				}

				$schema = $this->createMock(Schema::class);
				$schema->method('hasPropertyAuthorization')->willReturn($this->setup['schemas'][(string) $id]);
				return $schema;
			}
		);

		$container = $this->createMock(IAppContainer::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($schemaMapper) {
				$this->calls[] = ['container.get' => $id];
				return $schemaMapper;
			}
		);

		$render = $this->createMock(RenderObject::class);
		$render->method('renderEntities')->willReturnCallback(
			function (array $entities, array|string|null $_extend = [], array|string|null $_filter = null, array|string|null $_fields = null, array|string|null $_unset = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->calls[] = ['render' => array_map(fn ($o) => $o->getUuid(), $entities), 'extend' => $_extend, 'filter' => $_filter, 'fields' => $_fields, 'unset' => $_unset, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				return array_map(fn ($o) => ['rendered' => $o->getUuid()], $entities);
			}
		);

		$searchQuery = $this->createMock(SearchQueryHandler::class);
		$searchQuery->method('applyViewsToQuery')->willReturnCallback(
			function (array $query, array $viewIds, bool $_viewScopeRequired = false): array {
				$this->calls[] = ['applyViews' => $viewIds, 'query' => $query, 'required' => $_viewScopeRequired];
				return $query + ['_viewFilter' => $viewIds] + ($this->setup['viewAdds'] ?? []);
			}
		);

		$resolver = null;
		if ($this->setup['resolver'] === true) {
			$resolver = $this->createMock(SearchReferenceResolver::class);
			$resolver->method('normaliseQuery')->willReturnCallback(
				function (array $query): array {
					$this->calls[] = ['normalise' => $query];
					if (($query['_schema'] ?? null) === 'missing') {
						throw new Exception('schema reference names nothing');
					}

					if (($query['_schema'] ?? null) === 'slug') {
						$query['_schema'] = 10;
					}

					return $query;
				}
			);
		}

		return new QueryHandler(
			objectMapper: $mapper,
			getHandler: $this->createMock(GetObject::class),
			renderHandler: $render,
			searchQueryHandler: $searchQuery,
			facetHandler: $this->createMock(FacetHandler::class),
			performanceHandler: $performance,
			contentSearchHandler: $this->createMock(ContentSearchHandler::class),
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
			request: $this->createMock(IRequest::class),
			referenceResolver: $resolver
		);
	}//end handler()
}//end class
