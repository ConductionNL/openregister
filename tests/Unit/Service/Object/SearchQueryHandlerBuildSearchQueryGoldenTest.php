<?php

/**
 * SearchQueryHandler::buildSearchQuery() turns request parameters into the search query exactly as recorded.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\Object\ViewScopeApplier;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\Vocabulary\CodedFilterExpander;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden record of buildSearchQuery() over every branch: the un-mangling of PHP's dotted parameter
 * names (and its object-source exception), the bracket filter spelling, the system parameters it
 * drops, the metadata filters it moves into `@self`, the register/schema references, the coded
 * filter expansion, the `_ids` handling and the lenses. Set OR_GOLDEN_RECORD=1 to rewrite the
 * fixture.
 *
 * @covers \OCA\OpenRegister\Service\Object\SearchQueryHandler
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Support\FilterParams
 * @uses   \OCA\OpenRegister\Exception\RegisterNotFoundException
 * @uses   \OCA\OpenRegister\Exception\SchemaNotFoundException
 * @uses   \OCA\OpenRegister\Service\Object\DottedParamTree
 * @uses   \OCA\OpenRegister\Service\Object\SearchQueryParts
 *
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 */
class SearchQueryHandlerBuildSearchQueryGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/search-query-handler-build-search-query-golden.json';

	/** @var array<int, mixed> */
	private array $calls = [];

	/**
	 * Every scenario matches the recorded golden.
	 *
	 * @return void
	 */
	public function testScenariosMatchTheGolden(): void {
		$actual = [
			'plain filters'                  => $this->scenario(params: ['name' => 'x', '_limit' => 10, '_page' => 2], register: 1, schema: 7),
			'no scope'                       => $this->scenario(params: ['name' => 'x']),
			'mangled dots'                   => $this->scenario(params: ['@self_register' => '5', 'person_address_street' => 'Main', '_order' => ['name' => 'asc']], schema: 7),
			'mangled over a scalar'          => $this->scenario(params: ['a' => 'scalar', 'a_b' => 'v', 'a_c_d' => 'w'], schema: 7),
			'mangled twice into one branch'  => $this->scenario(params: ['p_q' => 1, 'p_r' => 2, 'p_q_s' => 3], schema: 7),
			'object-source schema'           => $this->scenario(params: ['product_line' => 'a', '@self_owner' => 'bob', 'app_slug_x' => 'y'], schema: 8),
			'object-source schema list'      => $this->scenario(params: ['product_line' => 'a'], schema: [8, 7]),
			'unknown schema id'              => $this->scenario(params: ['product_line' => 'a'], schema: 404),
			'bracket filter'                 => $this->scenario(params: ['filter' => ['status' => 'open', 'kind' => 'b']], schema: 7),
			'bracket filter, schema has one' => $this->scenario(params: ['filter' => ['status' => 'open']], schema: 9),
			'bracket filter in schema list'  => $this->scenario(params: ['filter' => ['status' => 'open']], schema: [7, 9]),
			'bracket filter, no schema'      => $this->scenario(params: ['filter' => ['status' => 'open']]),
			'scalar filter param'            => $this->scenario(params: ['filter' => 'open'], schema: 7),
			'system params dropped'          => $this->scenario(params: ['_route' => 'r', 'id' => 3, 'rbac' => 'false', 'multi' => 'false', 'deleted' => 'x', 'kept' => 1], schema: 7),
			'metadata fields'                => $this->scenario(params: ['owner' => 'bob', 'uuid' => 'u-1', 'organisation' => 'o', 'application' => 'a', 'created' => 'c', 'updated' => 'u', 'title' => 't'], schema: 7),
			'metadata register and schema'   => $this->scenario(params: ['register' => '99', 'schema' => '98']),
			'arguments win over params'      => $this->scenario(params: ['register' => '99', 'schema' => '98'], register: 1, schema: 7),
			'register and schema lists'      => $this->scenario(params: [], register: [1, '2', 'x', null], schema: [7, '9']),
			'valid reference lists'          => $this->scenario(params: ['name' => 'n'], register: [1, '2', null, 3.5], schema: [7, '9']),
			'register slug refused'          => $this->scenario(params: [], register: 'my-register'),
			'schema slug refused'            => $this->scenario(params: [], schema: 'my-schema'),
			'blank references'               => $this->scenario(params: [], register: ' ', schema: '0'),
			'ids argument'                   => $this->scenario(params: [], schema: 7, ids: ['a', 'b']),
			'ids param, special spelling'    => $this->scenario(params: ['_ids' => 'u1, u2,,u3'], schema: 7),
			'ids argument and param'         => $this->scenario(params: ['_ids' => ['p1']], schema: 7, ids: ['a1']),
			'plain ids param'                => $this->scenario(params: ['ids' => 'x,y'], schema: 7),
			'coded filters'                  => $this->scenario(params: ['categorie' => ['branch' => 'uri:1'], '_limit' => 5], schema: 7, coded: true),
			'watching lens'                  => $this->scenario(params: ['_watching' => 'true', '_ids' => 'a,b'], schema: 7),
			'watching lens, anonymous'       => $this->scenario(params: ['_watching' => 'true'], schema: 7, user: null),
			'favourite lens'                 => $this->scenario(params: ['_favourite' => '1', '_watchingFor' => 'mallory'], schema: 7),
			'watching false'                 => $this->scenario(params: ['_watching' => 'false', '_watchingFor' => 'mallory'], schema: 7),
			'unread lens'                    => $this->scenario(params: ['_unread' => 'true'], schema: 7),
			'unread lens, anonymous'         => $this->scenario(params: ['_unread' => 'yes'], schema: 7, user: null),
			'unread false'                   => $this->scenario(params: ['_unread' => '0'], schema: 7),
			'recent lens without history'    => $this->scenario(params: ['_recent' => 'true'], schema: 7),
		];

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(expected: $expected, actual: json_decode((string) json_encode($actual, JSON_UNESCAPED_SLASHES), true));
	}//end testScenariosMatchTheGolden()

	/**
	 * Run buildSearchQuery() once and record the query or the exception.
	 *
	 * @param array<string, mixed>       $params   The request parameters.
	 * @param int|string|array|null      $register The register argument.
	 * @param int|string|array|null      $schema   The schema argument.
	 * @param array<int, string>|null    $ids      The ids argument.
	 * @param bool                       $coded    Whether a coded-filter expander is wired.
	 * @param string|null                $user     The session user.
	 *
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 */
	private function scenario(
		array $params,
		int|string|array|null $register = null,
		int|string|array|null $schema = null,
		?array $ids = null,
		bool $coded = false,
		?string $user = 'alice',
	): array {
		$this->calls = [];
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			function ($id, ...$rest): Schema {
				$this->calls[] = ['schemaMapper.find' => $id];
				$schema = new Schema();
				$schema->setId((int) $id);
				if ((int) $id === 8) {
					$schema->setConfiguration(['x-openregister-object-source' => ['provider' => 'database']]);
				} else if ((int) $id === 9) {
					$schema->setProperties(['filter' => ['type' => 'string']]);
				} else if ((int) $id !== 7) {
					throw new DoesNotExistException('no schema ' . $id);
				}

				return $schema;
			}
		);

		$session = $this->createMock(IUserSession::class);
		$sessionUser = null;
		if ($user !== null) {
			$sessionUser = $this->createMock(IUser::class);
			$sessionUser->method('getUID')->willReturn($user);
		}

		$session->method('getUser')->willReturn($sessionUser);

		$expander = null;
		if ($coded === true) {
			$expander = $this->createMock(CodedFilterExpander::class);
			$expander->method('expand')->willReturnCallback(
				function (array $filters, int|string|array|null $schemaRef): array {
					$this->calls[] = ['expand' => $filters, 'schemaRef' => $schemaRef];
					return ['expanded' => array_keys($filters)];
				}
			);
		}

		$handler = new SearchQueryHandler(
			$this->createMock(ViewScopeApplier::class),
			$schemaMapper,
			$this->createMock(SettingsService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IRequest::class),
			$this->createMock(SearchTrailService::class),
			$session,
			$expander
		);

		try {
			$query = $handler->buildSearchQuery(requestParams: $params, register: $register, schema: $schema, ids: $ids);
			return ['query' => $query, 'calls' => $this->calls];
		} catch (\Throwable $e) {
			return ['exception' => [get_class($e), $e->getMessage()], 'calls' => $this->calls];
		}
	}//end scenario()
}//end class
