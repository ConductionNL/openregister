<?php

/**
 * Golden record of list() in the providers that read only their link table.
 *
 * For Bookmarks, Flow and Polls: the app missing, linked rows over a database
 * that answers, is empty or fails, a failing link mapper and an empty link table.
 * Every query is recorded with its table and parameters. The recording lives in
 * fixtures/link-rows-list-golden.json; set LINK_ROWS_GOLDEN_RECORD=1 to write it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec exclude Pins the link-rows list shape of the Bookmarks, Flow and Polls providers.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

use DateTime;
use OCA\OpenRegister\Db;
use OCA\OpenRegister\Service\Integration\Providers;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use OCA\OpenRegister\Service\Integration\Providers\LeafProviderPlatform;

/**
 * list() of the link-rows providers.
 *
 * @covers \OCA\OpenRegister\Service\Integration\Providers\BookmarksProvider
 * @covers \OCA\OpenRegister\Service\Integration\Providers\FlowProvider
 * @covers \OCA\OpenRegister\Service\Integration\Providers\PollsProvider
 * @uses   \OCA\OpenRegister\Service\Integration\Providers\NcAppLinkRowsProvider
 * @uses   \OCA\OpenRegister\Service\Integration\Providers\NcAppLinkTableProvider
 * @uses   \OCA\OpenRegister\Service\Integration\Providers\LinkTableAppDescriptor
 * @uses   \OCA\OpenRegister\Service\Integration\Providers\FirstRowQuery
 * @uses   \OCA\OpenRegister\Db\BookmarkLink
 * @uses   \OCA\OpenRegister\Db\FlowLink
 * @uses   \OCA\OpenRegister\Db\PollLink
 */
class LinkRowsListGoldenTest extends TestCase {

	private const FIXTURE = __DIR__.'/fixtures/link-rows-list-golden.json';

	/**
	 * What each table answers when the database works.
	 *
	 * @var array<string, array{fetch: mixed, fetchAll: array, fetchOne: mixed}>
	 */
	private const ANSWERS = [
		'flow_operations' => [
			'fetch' => ['id' => 9, 'class' => 'C', 'name' => 'Op', 'entity' => 'E', 'operation' => 'o', 'events' => '["e1"]', 'checks' => 'not json'],
			'fetchAll' => [],
			'fetchOne' => false,
		],
		'polls_polls' => [
			'fetch' => ['id' => 5, 'title' => 'P', 'description' => 'D', 'type' => 'datePoll', 'expire' => 1],
			'fetchAll' => [],
			'fetchOne' => false,
		],
		'polls_options' => [
			'fetch' => false,
			'fetchAll' => [['id' => 1, 'poll_option_text' => 'A', 'poll_option_hash' => 'ha'], ['poll_option_text' => 'B']],
			'fetchOne' => false,
		],
		'polls_votes' => [
			'fetch' => false,
			'fetchAll' => [['user_id' => 'u1'], ['user_id' => 'u2']],
			'fetchOne' => '3',
		],
	];

	/**
	 * The queries the database was asked.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * How the database behaves: 'answers', 'empty', 'fails' or 'votes fail'.
	 *
	 * @var string
	 */
	private string $dbMode = 'answers';

	/**
	 * A recording query builder.
	 *
	 * @return IQueryBuilder The builder.
	 */
	private function queryBuilder(): IQueryBuilder {
		$index = count($this->queries);
		$this->queries[] = ['calls' => []];
		$log = function (string $call, array $args) use ($index): void {
			$this->queries[$index]['calls'][] = [$call, array_map(static fn ($a) => is_object($a) ? 'object' : $a, $args)];
		};
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static fn ($col, $val) => 'eq('.$col.','.$val.')');
		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturn($this->createMock(IQueryFunction::class));
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'selectDistinct', 'from', 'where', 'andWhere', 'orderBy'] as $method) {
			$qb->method($method)->willReturnCallback(
				function (...$args) use ($qb, $log, $method, $index) {
					$log($method, $args);
					if ($method === 'from') {
						$this->queries[$index]['table'] = $args[0];
					}
					return $qb;
				}
			);
		}
		$qb->method('createNamedParameter')->willReturnCallback(
			function (...$args) use ($log): string {
				$log('param', $args);
				return ':p'.json_encode($args[0]);
			}
		);
		$qb->method('expr')->willReturn($expr);
		$qb->method('func')->willReturn($func);
		$qb->method('executeQuery')->willReturnCallback(
			function () use ($index): IResult {
				$table = (string)($this->queries[$index]['table'] ?? '');
				if ($this->dbMode === 'votes fail' && $table === 'polls_votes') {
					throw new RuntimeException('votes down');
				}
				return $this->tableResult(table: $table);
			}
		);
		return $qb;
	}//end queryBuilder()

	/**
	 * The result a table gives in the current database mode.
	 *
	 * @param string $table The table.
	 *
	 * @return IResult The result.
	 */
	private function tableResult(string $table): IResult {
		$answer = self::ANSWERS[$table] ?? ['fetch' => false, 'fetchAll' => [], 'fetchOne' => false];
		if ($this->dbMode === 'empty') {
			$answer = ['fetch' => false, 'fetchAll' => [], 'fetchOne' => false];
		}
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn($answer['fetch']);
		$result->method('fetchAll')->willReturn($answer['fetchAll']);
		$result->method('fetchOne')->willReturn($answer['fetchOne']);
		return $result;
	}//end result()

	/**
	 * A database whose query builders record and answer per table.
	 *
	 * @return IDBConnection The connection.
	 */
	private function db(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(
			function (): IQueryBuilder {
				if ($this->dbMode === 'fails') {
					$this->queries[] = ['failed' => true];
					throw new RuntimeException('db down');
				}
				return $this->queryBuilder();
			}
		);
		return $db;
	}//end db()

	/**
	 * A link mapper returning rows or throwing.
	 *
	 * @param class-string $mapperClass The mapper class.
	 * @param mixed        $links       Rows, or an exception.
	 *
	 * @return object The mapper.
	 */
	private function mapper(string $mapperClass, mixed $links): object {
		$mapper = $this->createMock($mapperClass);
		if ($links instanceof \Throwable) {
			$mapper->method('findByObjectUuid')->willThrowException($links);
			return $mapper;
		}
		$mapper->method('findByObjectUuid')->willReturn($links);
		return $mapper;
	}//end mapper()

	/**
	 * Build one provider.
	 *
	 * @param string $name      bookmarks, flow or polls.
	 * @param bool   $installed Whether the app is installed.
	 * @param mixed  $links     Rows the mapper returns, or an exception.
	 *
	 * @return object The provider.
	 */
	private function provider(string $name, bool $installed, mixed $links): object {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn($installed);
		$l10n = $this->createMock(IL10N::class);
		if ($name === 'bookmarks') {
			return new Providers\BookmarksProvider($this->mapper(Db\BookmarkLinkMapper::class, $links), $apps, $l10n);
		}
		if ($name === 'flow') {
			return new Providers\FlowProvider($this->mapper(Db\FlowLinkMapper::class, $links), $this->db(), $apps, $l10n);
		}
		return new Providers\PollsProvider(new LeafProviderPlatform($this->db(), $apps, $l10n), $this->mapper(Db\PollLinkMapper::class, $links), $this->createMock(IUserSession::class));
	}//end provider()

	/**
	 * Two links per provider: one filled, one bare.
	 *
	 * @param string $name bookmarks, flow or polls.
	 *
	 * @return list<object> The links.
	 */
	private function links(string $name): array {
		if ($name === 'bookmarks') {
			$full = new Db\BookmarkLink();
			$full->setBookmarkId(11);
			$full->setTitle('T');
			$full->setUrl('https://u');
			$full->setDescription('D');
			$full->setTags(['a', 'b']);
			$full->setAddedAt(new DateTime('@1700000000'));
			$bare = new Db\BookmarkLink();
			$bare->setUrl('https://only-url');
			$bare->setAddedAt(null);
		} elseif ($name === 'flow') {
			$full = new Db\FlowLink();
			$full->setOperationId(9);
			$full->setOperationName('cached');
			$full->setOperationClass('CC');
			$full->setEntityType('CE');
			$bare = new Db\FlowLink();
		} else {
			$full = new Db\PollLink();
			$full->setPollId(5);
			$full->setPollTitle('cached');
			$full->setPollType('textPoll');
			$bare = new Db\PollLink();
		}
		$full->setId(1);
		$bare->setId(2);
		return [$full, $bare];
	}//end links()

	/**
	 * Every provider lists as recorded.
	 *
	 * @return void
	 */
	public function testListsMatchTheRecording(): void {
		$seen = [];
		foreach (['bookmarks', 'flow', 'polls'] as $name) {
			$scenarios = [
				'app missing' => [false, $this->links($name), 'answers'],
				'linked rows' => [true, $this->links($name), 'answers'],
				'linked rows, empty database' => [true, $this->links($name), 'empty'],
				'linked rows, failing database' => [true, $this->links($name), 'fails'],
				'linked rows, failing vote tables' => [true, $this->links($name), 'votes fail'],
				'mapper fails' => [true, new RuntimeException('no table'), 'answers'],
				'no links' => [true, [], 'answers'],
			];
			foreach ($scenarios as $label => [$installed, $links, $mode]) {
				$this->queries = [];
				$this->dbMode = $mode;
				try {
					$rows = $this->provider($name, $installed, $links)->list('r', 's', 'obj-1', ['q' => 1]);
				} catch (\Throwable $e) {
					$rows = ['thrown' => get_class($e), 'message' => $e->getMessage()];
				}
				$seen[$name.', '.$label] = ['rows' => $rows, 'queries' => $this->queries];
			}
		}

		$json = json_decode((string)json_encode($seen, JSON_PARTIAL_OUTPUT_ON_ERROR), true);
		if (getenv('LINK_ROWS_GOLDEN_RECORD') === '1') {
			@mkdir(dirname(self::FIXTURE));
			file_put_contents(self::FIXTURE, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
			$this->markTestIncomplete('recorded');
		}

		$this->assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), $json);
	}//end testListsMatchTheRecording()
}//end class
