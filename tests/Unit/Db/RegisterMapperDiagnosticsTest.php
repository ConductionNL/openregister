<?php

declare(strict_types=1);

/**
 * RegisterMapper resolution diagnostics.
 *
 * openregister#2820. RegisterMapper carried four `if (isset($this->logger))`
 * logging sites — plus the ones it inherits from MultiTenancyTrait — and never
 * declared or received a logger. `isset()` on an undeclared property is always
 * false, so every one of those branches was dead code that read as working
 * instrumentation.
 *
 * It mattered: find() answers DoesNotExistException for a register whose row
 * exists, the objects endpoint turns that into `Register not found: '19'`, and
 * the error-level line that would have said WHY — "Register not found after
 * filters", carrying `existsBeforeFilter`, i.e. "the row matched, then a filter
 * removed it" — silently did not fire. Diagnosing it took a database session
 * and a code read that the log line alone would have settled.
 *
 * These tests assert the diagnostics actually emit. Without them the fix is
 * only a moved dependency: the branches would still never run and nothing would
 * fail.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Tenancy\TenancyServices;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Db\RegisterMapperServices;

/**
 * Proves the resolution diagnostics are wired and fire.
 */
class RegisterMapperDiagnosticsTest extends TestCase {
	/** @var IDBConnection&MockObject */
	private IDBConnection $db;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var array<int,array{level:string,message:string,context:array}> */
	private array $logged = [];

	protected function setUp(): void {
		parent::setUp();

		$this->db = $this->createMock(IDBConnection::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		foreach (['info', 'debug', 'warning', 'error'] as $level) {
			$this->logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logged[] = ['level' => $level, 'message' => $message, 'context' => $context];
				}
			);
		}
	}

	/**
	 * Build the mapper over a query builder that finds nothing.
	 *
	 * @param bool $rowExistsBeforeFilters Whether the unfiltered probe finds a row
	 *
	 * @return RegisterMapper the mapper under test
	 */
	private function mapperFindingNothing(bool $rowExistsBeforeFilters = false): RegisterMapper {
		// The first execution is the unfiltered existence probe, the second the
		// filtered lookup: a row for the probe and none after the filters models
		// "a filter removed the row".
		$executions = 0;
		$this->db->method('getQueryBuilder')->willReturnCallback(
			function () use (&$executions, $rowExistsBeforeFilters) {
				return $this->emptyQueryBuilder(
					probeRow: $rowExistsBeforeFilters === true ? ['id' => 19, 'uuid' => 'u-19', 'slug' => 'r19'] : false,
					executions: $executions
				);
			}
		);

		return new RegisterMapper(
			db: $this->db,
			tenancy: new TenancyServices($this->createMock(OrganisationMapper::class), $this->createMock(IUserSession::class), $this->createMock(IGroupManager::class)),
			appConfig: $this->createMock(IAppConfig::class),
			services: new RegisterMapperServices(
				schemaMapper: $this->createMock(SchemaMapper::class),
				container: $this->createMock(ContainerInterface::class),
				eventDispatcher: $this->createMock(IEventDispatcher::class),
				logger: $this->logger
			)
		);
	}

	/**
	 * A query builder that yields zero rows, except that the first execution
	 * (the unfiltered probe) yields `$probeRow` when one is given.
	 *
	 * @param array|false $probeRow    The row the first execution returns, or false for none
	 * @param int         $executions  Shared execution counter
	 *
	 * @return IQueryBuilder&MockObject the mocked builder
	 */
	private function emptyQueryBuilder(array|false $probeRow = false, int &$executions = 0): IQueryBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('orX')->willReturnCallback(
			function () {
				$comp = $this->createMock(\OCP\DB\QueryBuilder\ICompositeExpression::class);
				$comp->method('add')->willReturnSelf();
				return $comp;
			}
		);
		$expr->method('eq')->willReturn('eq');

		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('lower')->willReturn($this->createMock(IQueryFunction::class));

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('func')->willReturn($func);
		$qb->method('createNamedParameter')->willReturn('p');
		foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'setMaxResults'] as $chain) {
			$qb->method($chain)->willReturnSelf();
		}

		$qb->method('executeQuery')->willReturnCallback(
			function () use ($probeRow, &$executions): IResult {
				$executions++;
				$result = $this->createMock(IResult::class);
				$result->method('fetch')->willReturn($executions === 1 ? $probeRow : false);
				$result->method('closeCursor')->willReturn(true);
				return $result;
			}
		);

		return $qb;
	}

	/**
	 * Messages logged at a given level.
	 *
	 * @param string $level the psr-3 level
	 *
	 * @return string[] the messages
	 */
	private function messagesAt(string $level): array {
		$out = [];
		foreach ($this->logged as $entry) {
			if ($entry['level'] === $level) {
				$out[] = $entry['message'];
			}
		}
		return $out;
	}

	/**
	 * The lookup attempt is announced, so a failing resolve can be traced.
	 *
	 * @return void
	 */
	public function testTheSearchAttemptIsLogged(): void {
		$mapper = $this->mapperFindingNothing();

		try {
			$mapper->find(id: '19', _rbac: false, _multitenancy: false);
		} catch (DoesNotExistException) {
			// The miss is the point; the logging is what is under test.
		}

		$this->assertNotSame(
			[],
			$this->logged,
			'RegisterMapper emitted nothing at all — the logger is not wired.'
		);
		$this->assertStringContainsString(
			'Searching for register',
			implode(' | ', $this->messagesAt('info'))
		);
	}

	/**
	 * A register removed by a filter is reported at error level WITH
	 * `existsBeforeFilter`.
	 *
	 * That flag is the whole diagnostic: it distinguishes "no such row" from
	 * "the row matched and then a filter removed it", which is the difference
	 * between a bad identifier and a scoping bug. #2820 was the second, and
	 * presented as the first.
	 *
	 * @return void
	 */
	public function testTheMissIsReportedWithWhetherTheRowExistedBeforeFilters(): void {
		$mapper = $this->mapperFindingNothing(rowExistsBeforeFilters: true);

		try {
			$mapper->find(id: '19', _rbac: false, _multitenancy: false);
			$this->fail('expected the lookup to miss');
		} catch (DoesNotExistException) {
			// Expected.
		}

		$errors = [];
		foreach ($this->logged as $entry) {
			if ($entry['level'] === 'error') {
				$errors[] = $entry;
			}
		}

		$this->assertNotSame([], $errors, 'the not-found diagnostic did not fire');

		$found = null;
		foreach ($errors as $entry) {
			if (str_contains($entry['message'], 'Register not found after filters') === true) {
				$found = $entry;
			}
		}

		$this->assertNotNull($found, 'the "after filters" diagnostic did not fire');
		$this->assertArrayHasKey(
			'existsBeforeFilter',
			$found['context'],
			'the diagnostic must say whether the row existed before filtering'
		);
		$this->assertTrue($found['context']['existsBeforeFilter']);
		$this->assertSame('19', (string)($found['context']['identifier'] ?? ''));
	}//end testTheMissIsReportedWithWhetherTheRowExistedBeforeFilters()

	/**
	 * A register that does not exist at all is an ordinary miss: callers probe
	 * several register spellings and catch the exception, so nothing is logged
	 * at error level, and the exception is still rethrown.
	 *
	 * @return void
	 */
	public function testARegisterThatDoesNotExistIsNotLoggedAsAnError(): void {
		$mapper = $this->mapperFindingNothing(rowExistsBeforeFilters: false);

		try {
			$mapper->find(id: 'openconnector', _rbac: false, _multitenancy: false);
			$this->fail('expected the lookup to miss');
		} catch (DoesNotExistException) {
			// Expected: the exception is still rethrown.
		}

		$this->assertSame([], $this->messagesAt('error'), 'a plain miss must not be logged at error level');
		$this->assertContains(
			'[RegisterMapper] Register does not exist',
			$this->messagesAt('debug')
		);
	}//end testARegisterThatDoesNotExistIsNotLoggedAsAnError()
}
