<?php

/**
 * Unit tests for the RBAC filter under a forced-anonymous evaluation scope.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\AnonymousEvaluationContext;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * PHPUnit runs under the CLI SAPI, which is exactly the situation WOO-578 has
 * to get right: a session without a user is trusted as the system on the CLI,
 * and a forced-anonymous evaluation must NOT inherit that trust.
 */
class MagicRbacHandlerAnonymousScopeTest extends TestCase {

	private MagicRbacHandler $handler;


	protected function setUp(): void {
		parent::setUp();
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$this->handler = new MagicRbacHandler(
			$userSession,
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(ConditionMatcher::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()


	/**
	 * A schema only a named group may read: an anonymous caller has no rule
	 * to qualify for, so the filter must clamp the query.
	 */
	private function staffOnlySchema(): Schema {
		$schema = new Schema();
		$schema->setId(1);
		$schema->setTitle('Staff only');
		$schema->setAuthorization(['read' => ['behandelaars']]);
		return $schema;
	}//end staffOnlySchema()


	private function queryBuilder(): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$qb->method('createNamedParameter')->willReturn(':p');
		$qb->method('createFunction')->willReturnArgument(0);
		return $qb;
	}//end queryBuilder()


	public function testWithoutTheScopeAnEmptyCliSessionBypassesTheFilter(): void {
		$this->assertSame('cli', PHP_SAPI, 'this test only means something under the CLI SAPI');
		$qb = $this->queryBuilder();
		$qb->expects($this->never())->method('andWhere');

		$this->handler->applyRbacFilters(qb: $qb, schema: $this->staffOnlySchema(), action: 'read');
	}//end testWithoutTheScopeAnEmptyCliSessionBypassesTheFilter()


	public function testInsideTheScopeTheSameSessionIsFilteredAsAnAnonymousCaller(): void {
		$qb = $this->queryBuilder();
		$qb->expects($this->atLeastOnce())->method('andWhere');

		AnonymousEvaluationContext::run(
			function () use ($qb): void {
				$this->handler->applyRbacFilters(qb: $qb, schema: $this->staffOnlySchema(), action: 'read');
			}
		);
	}//end testInsideTheScopeTheSameSessionIsFilteredAsAnAnonymousCaller()


	/**
	 * A companion check, NOT evidence for the gating: `hasPermission()` has no
	 * CLI bypass and no system-scope bypass, so it denies a staff-only schema for
	 * a null user with or without the scope. It is here to pin that the scope does
	 * not accidentally make this path MORE permissive — the gating itself is
	 * pinned by the test above and by MagicOrganizationHandlerAnonymousScopeTest.
	 */
	public function testInsideTheScopeAnAnonymousCallerStillHoldsNoStaffPermission(): void {
		$granted = AnonymousEvaluationContext::run(
			fn (): bool => $this->handler->hasPermission(schema: $this->staffOnlySchema(), action: 'read')
		);
		$this->assertFalse($granted);
	}//end testInsideTheScopeAnAnonymousCallerHoldsNoStaffPermission()
}//end class
