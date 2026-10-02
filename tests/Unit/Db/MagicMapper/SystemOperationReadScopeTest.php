<?php

/**
 * A read made inside ObjectService::runAsSystem() without a user is a system read.
 *
 * The bug (Woo round 3, 2026-10-02): dossiq's WooRequestIntake writes the case of
 * a portal Woo request inside runAsSystem(), in a web request with no user. The
 * case's calculations resolve `x-openregister-references` (caseType, statusType)
 * through ReferenceResolver with RBAC on. MagicRbacHandler and
 * MagicOrganizationHandler only trusted a userless caller on the command line,
 * so in the web request every reference read clamped to nothing, and the case
 * got no deadline and no status label. nextcloud.log: "Reference resolution
 * failed for schema "caseType": ... not found in any magic table".
 *
 * The fix trusts a userless caller inside the system-operation scope the way the
 * command line already is (PermissionHandler::hasPermission() already did), and
 * opens nothing else: a logged-in user inside the scope is still filtered as that
 * user, a forced-anonymous evaluation is still anonymous, and a userless web
 * caller outside the scope is still filtered.
 *
 * PHPUnit runs on the command line, so each handler is built with
 * isCommandLine() answering false: these tests read as a web request.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\AnonymousEvaluationContext;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

#[CoversClass(MagicRbacHandler::class)]
#[CoversClass(MagicOrganizationHandler::class)]
#[UsesClass(\OCA\OpenRegister\Db\Schema::class)]
#[UsesClass(\OCA\OpenRegister\Service\AnonymousEvaluationContext::class)]
#[UsesClass(\OCA\OpenRegister\Service\Object\PermissionHandler::class)]
#[UsesClass(\OCA\OpenRegister\Service\Rbac\DenyEnforcementMode::class)]
#[UsesClass(\OCA\OpenRegister\Service\Rbac\ObjectScopeResolver::class)]
#[UsesClass(\OCA\OpenRegister\Service\SystemOperationContext::class)]
class SystemOperationReadScopeTest extends TestCase {

	/**
	 * A userless session, or one holding a plain non-admin user.
	 *
	 * @param bool $withUser Whether a non-admin user is logged in.
	 *
	 * @return IUserSession
	 */
	private function session(bool $withUser): IUserSession {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($withUser === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('resident');
		}

		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	/**
	 * A MagicRbacHandler that reads as a web request.
	 *
	 * @param bool $withUser Whether a non-admin user is logged in.
	 *
	 * @return MagicRbacHandler
	 */
	private function webRbacHandler(bool $withUser = false): MagicRbacHandler {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn(['ambtenaar']);

		return new class(
			$this->session($withUser),
			$groupManager,
			$this->createMock(IUserManager::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(ConditionMatcher::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class)
		) extends MagicRbacHandler {
			/**
			 * A web request, not the command line.
			 *
			 * @return bool
			 */
			protected function isCommandLine(): bool {
				return false;
			}//end isCommandLine()
		};
	}//end webRbacHandler()

	/**
	 * A MagicOrganizationHandler that reads as a web request.
	 *
	 * @return MagicOrganizationHandler
	 */
	private function webOrganizationHandler(): MagicOrganizationHandler {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		$organisationService = new class {
			/**
			 * No organisations for a userless caller.
			 *
			 * @return array<int, string>
			 */
			public function getUserActiveOrganisations(): array {
				return [];
			}

			/**
			 * No active organisation for a userless caller.
			 *
			 * @return object|null
			 */
			public function getActiveOrganisation(): ?object {
				return null;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($organisationService);

		return new class(
			$this->session(false),
			$this->createMock(IGroupManager::class),
			$appConfig,
			$container,
			$this->createMock(LoggerInterface::class)
		) extends MagicOrganizationHandler {
			/**
			 * A web request, not the command line.
			 *
			 * @return bool
			 */
			protected function isCommandLine(): bool {
				return false;
			}//end isCommandLine()
		};
	}//end webOrganizationHandler()

	/**
	 * The caseType schema of the bug: readable by staff only.
	 *
	 * @return Schema
	 */
	private function staffOnlySchema(): Schema {
		$schema = new Schema();
		$schema->setId(165);
		$schema->setTitle('Case type');
		$schema->setAuthorization(['read' => ['behandelaars']]);
		return $schema;
	}//end staffOnlySchema()

	/**
	 * A query builder double.
	 *
	 * @return IQueryBuilder
	 */
	private function queryBuilder(): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$qb->method('createNamedParameter')->willReturn(':p');
		$qb->method('createFunction')->willReturnArgument(0);
		return $qb;
	}//end queryBuilder()

	/**
	 * THE BUG. A userless read inside runAsSystem() is not filtered.
	 */
	public function testAUserlessReadInsideTheSystemScopeIsNotFiltered(): void {
		$qb = $this->queryBuilder();
		$qb->expects($this->never())->method('andWhere');
		$handler = $this->webRbacHandler();

		SystemOperationContext::run(
			fn () => $handler->applyRbacFilters(qb: $qb, schema: $this->staffOnlySchema(), action: 'read')
		);
	}//end testAUserlessReadInsideTheSystemScopeIsNotFiltered()

	/**
	 * The raw-SQL path (UNION reads) agrees with the query-builder path.
	 */
	public function testTheSqlPathAgreesInsideTheSystemScope(): void {
		$handler = $this->webRbacHandler();

		$inside = SystemOperationContext::run(
			fn (): array => $handler->buildRbacConditionsSql(schema: $this->staffOnlySchema(), action: 'read')
		);
		$outside = $handler->buildRbacConditionsSql(schema: $this->staffOnlySchema(), action: 'read');

		$this->assertTrue($inside['bypass']);
		$this->assertFalse($outside['bypass'], 'Outside the scope a userless web caller is not bypassed.');
	}//end testTheSqlPathAgreesInsideTheSystemScope()

	/**
	 * CONTROL. A userless web read outside the scope is still filtered.
	 */
	public function testAUserlessWebReadOutsideTheScopeIsStillFiltered(): void {
		$qb = $this->queryBuilder();
		$qb->expects($this->atLeastOnce())->method('andWhere');

		$this->webRbacHandler()->applyRbacFilters(qb: $qb, schema: $this->staffOnlySchema(), action: 'read');
	}//end testAUserlessWebReadOutsideTheScopeIsStillFiltered()

	/**
	 * CONTROL. A logged-in user inside the scope is still filtered as that user:
	 * the scope does not open user-facing reads.
	 */
	public function testALoggedInUserInsideTheScopeIsStillFiltered(): void {
		$qb = $this->queryBuilder();
		$qb->expects($this->atLeastOnce())->method('andWhere');
		$handler = $this->webRbacHandler(withUser: true);

		SystemOperationContext::run(
			fn () => $handler->applyRbacFilters(qb: $qb, schema: $this->staffOnlySchema(), action: 'read')
		);
	}//end testALoggedInUserInsideTheScopeIsStillFiltered()

	/**
	 * CONTROL. A forced-anonymous evaluation stays anonymous inside the scope.
	 */
	public function testAForcedAnonymousEvaluationStaysAnonymous(): void {
		$qb = $this->queryBuilder();
		$qb->expects($this->atLeastOnce())->method('andWhere');
		$handler = $this->webRbacHandler();

		SystemOperationContext::run(
			fn () => AnonymousEvaluationContext::run(
				fn () => $handler->applyRbacFilters(qb: $qb, schema: $this->staffOnlySchema(), action: 'read')
			)
		);
	}//end testAForcedAnonymousEvaluationStaysAnonymous()

	/**
	 * The organisation boundary follows the same rule.
	 */
	public function testTheOrganisationScopeFollowsTheSameRule(): void {
		$handler = $this->webOrganizationHandler();

		$inside = SystemOperationContext::run(fn (): array => $handler->resolveOrganizationScope());
		$outside = $handler->resolveOrganizationScope();
		$anonymous = SystemOperationContext::run(
			fn (): array => AnonymousEvaluationContext::run(fn (): array => $handler->resolveOrganizationScope())
		);

		$this->assertSame(MagicOrganizationHandler::SCOPE_ALL, $inside['mode']);
		$this->assertNotSame(MagicOrganizationHandler::SCOPE_ALL, $outside['mode']);
		$this->assertNotSame(MagicOrganizationHandler::SCOPE_ALL, $anonymous['mode']);
	}//end testTheOrganisationScopeFollowsTheSameRule()
}//end class
