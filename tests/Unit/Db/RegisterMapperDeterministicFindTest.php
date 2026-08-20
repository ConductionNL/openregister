<?php

/**
 * Phase-0 regression: RegisterMapper::find() is deterministic on duplicate slugs.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
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

/**
 * Env churn can leave several `openregister_registers` rows sharing a slug.
 * Before the Phase-0 fix, the slug lookup in find() raised
 * MultipleObjectsReturnedException → a fleet-wide 500 (notably on the object
 * lock path). The fix orders the resolution by `id ASC` and caps it to a single
 * row so the oldest (lowest-id) register deterministically wins instead of
 * throwing. This test asserts those deterministic guards are applied to the
 * resolution query.
 */
class RegisterMapperDeterministicFindTest extends TestCase {

	private IDBConnection&MockObject $db;

	private RegisterMapper $mapper;

	/**
	 * Recorded orderBy() invocations across every QueryBuilder produced.
	 *
	 * @var array<int,array{0:string,1:?string}>
	 */
	private array $orderByCalls = [];

	/**
	 * Recorded setMaxResults() invocations across every QueryBuilder produced.
	 *
	 * @var array<int,int>
	 */
	private array $maxResultsCalls = [];

	protected function setUp(): void {
		parent::setUp();

		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('getQueryBuilder')->willReturnCallback(fn () => $this->makeQueryBuilder());

		$this->mapper = new RegisterMapper(
			$this->db,
			$this->createMock(SchemaMapper::class),
			$this->createMock(IEventDispatcher::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(OrganisationMapper::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IAppConfig::class)
		);
	}//end setUp()

	/**
	 * Build a QueryBuilder mock that records orderBy()/setMaxResults() and
	 * returns a single-row result from findEntity()'s executeQuery().
	 *
	 * @return IQueryBuilder&MockObject
	 */
	private function makeQueryBuilder(): IQueryBuilder {
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
		foreach (['select', 'from', 'where', 'andWhere'] as $chain) {
			$qb->method($chain)->willReturnSelf();
		}

		$qb->method('orderBy')->willReturnCallback(
			function (string $sort, ?string $order = null) use ($qb): IQueryBuilder {
				$this->orderByCalls[] = [$sort, $order];
				return $qb;
			}
		);
		$qb->method('setMaxResults')->willReturnCallback(
			function (?int $max) use ($qb): IQueryBuilder {
				$this->maxResultsCalls[] = $max;
				return $qb;
			}
		);
		// Each executeQuery() yields a FRESH single-row result. (find() clones
		// the QB for its debug probe, so the same QB mock executes more than
		// once; a shared result would let the probe consume the only row and
		// make the real lookup see none.) QBMapper::findEntity() fetches twice:
		// the row, then a guard fetch that must return false to prove uniqueness.
		$qb->method('executeQuery')->willReturnCallback(
			function (): IResult {
				$fetched = false;
				$result = $this->createMock(IResult::class);
				$result->method('fetch')->willReturnCallback(
					function () use (&$fetched) {
						if ($fetched === true) {
							return false;
						}
						$fetched = true;
						return ['id' => 1, 'uuid' => 'uuid-1', 'slug' => 'shared-slug'];
					}
				);
				$result->method('closeCursor')->willReturn(true);
				return $result;
			}
		);

		return $qb;
	}//end makeQueryBuilder()

	public function testFindCapsResolutionToOneDeterministicRowOnDuplicateSlug(): void {
		$register = $this->mapper->find('shared-slug', false, false);

		// The oldest (lowest-id) register deterministically wins.
		$this->assertSame(1, $register->getId());
		$this->assertSame('shared-slug', $register->getSlug());

		// The resolution must order by id ASC so the lowest id wins.
		$this->assertContains(
			['id', 'ASC'],
			$this->orderByCalls,
			'find() must order the resolution query by id ASC for determinism.'
		);

		// And cap to a single row so a duplicate slug never raises
		// MultipleObjectsReturnedException (the pre-fix 500).
		$this->assertContains(
			1,
			$this->maxResultsCalls,
			'find() must cap the resolution query to a single row.'
		);
	}//end testFindCapsResolutionToOneDeterministicRowOnDuplicateSlug()
}//end class
