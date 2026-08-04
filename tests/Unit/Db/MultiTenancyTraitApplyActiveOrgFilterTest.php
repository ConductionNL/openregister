<?php

/**
 * Unit tests for MultiTenancyTrait::applyActiveOrgFilter() empty-predicate guard
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\WebhookMapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Regression test for openregister#2084.
 *
 * MultiTenancyTrait::applyActiveOrgFilter() used to always call
 * `$qb->expr()->orX()` with zero initial arguments and rely on every code
 * path adding at least one predicate before the query ran. OCP's own
 * IExpressionBuilder::orX() docblock says a zero-argument call "requires at
 * least one defined when converting to string" and NC34 already logs a
 * deprecation exception on every zero-argument call, flagging it will throw
 * in a future release.
 *
 * These tests drive applyActiveOrgFilter() directly (via reflection, since
 * it is private) with an empty $activeOrgUuids array — the one input that
 * defeats the "always at least one predicate" invariant the rest of the
 * class relies on — and assert:
 *  - orX() is never invoked with zero arguments (in fact never invoked at
 *    all on this path, since there are no predicates to combine);
 *  - the query fails CLOSED (`1 = 0`, i.e. no rows), matching the
 *    tenant-isolation guarantee in
 *    openspec/specs/tenant-isolation-audit/spec.md ("the system MUST verify
 *    that no Organisation's query filter returns objects belonging to
 *    another Organisation") rather than failing open by skipping the WHERE
 *    clause entirely.
 */
class MultiTenancyTraitApplyActiveOrgFilterTest extends TestCase
{
    private IDBConnection&MockObject $db;
    private OrganisationMapper&MockObject $organisationMapper;
    private IUserSession&MockObject $userSession;
    private IGroupManager&MockObject $groupManager;
    private IAppConfig&MockObject $appConfig;
    private WebhookMapper $mapper;

    protected function setUp(): void
    {
        $this->db                 = $this->createMock(IDBConnection::class);
        $this->organisationMapper = $this->createMock(OrganisationMapper::class);
        $this->userSession        = $this->createMock(IUserSession::class);
        $this->groupManager       = $this->createMock(IGroupManager::class);
        $this->appConfig          = $this->createMock(IAppConfig::class);

        $this->mapper = new WebhookMapper(
            $this->db,
            $this->organisationMapper,
            $this->userSession,
            $this->groupManager,
            $this->appConfig
        );
    }//end setUp()

    /**
     * Invoke the private applyActiveOrgFilter() method via reflection.
     *
     * @param IQueryBuilder $qb                 Query builder mock
     * @param mixed         $user               User object (or null)
     * @param array         $activeOrgUuids     Active organisation UUIDs
     * @param bool          $allowNullOrg       Allow NULL organisation
     * @param string        $organisationColumn Organisation column name
     *
     * @return void
     */
    private function invokeApplyActiveOrgFilter(
        IQueryBuilder $qb,
        mixed $user,
        array $activeOrgUuids,
        bool $allowNullOrg,
        string $organisationColumn
    ): void {
        $method = new ReflectionMethod(WebhookMapper::class, 'applyActiveOrgFilter');
        $method->setAccessible(true);
        $method->invoke($this->mapper, $qb, $user, $activeOrgUuids, $allowNullOrg, $organisationColumn);
    }//end invokeApplyActiveOrgFilter()

    /**
     * A non-admin user with zero active organisation UUIDs must fail CLOSED
     * (no rows) and must NEVER trigger a zero-argument orX() call.
     */
    public function testEmptyActiveOrgUuidsFailsClosedWithoutZeroArgOrX(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');

        // Non-admin: isUserAdmin() must return false so the admin-override
        // early-return branch is never taken.
        $this->groupManager->method('getUserGroupIds')->willReturn(['users']);

        // getActiveOrganisationUuid() (used inside buildOrganisationConditions
        // for the "direct active org" fast path) resolves via the session +
        // OrganisationMapper fallback; return null so the empty-array branch
        // in buildOrganisationConditions() is what produces zero predicates.
        $this->userSession->method('getUser')->willReturn($user);
        $this->organisationMapper->method('getActiveOrganisationWithFallback')->willReturn(null);

        $expr = $this->createMock(IExpressionBuilder::class);
        // orX() must never be called on this path — there are no predicates
        // to combine, so the guard must short-circuit before reaching it.
        $expr->expects($this->never())->method('orX');

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('expr')->willReturn($expr);

        // Fail-closed: the guard must add an unconditional "no rows" predicate.
        $qb->expects($this->once())->method('andWhere')->with('1 = 0');

        $this->invokeApplyActiveOrgFilter(
            qb: $qb,
            user: $user,
            activeOrgUuids: [],
            allowNullOrg: false,
            organisationColumn: 'organisation'
        );
    }//end testEmptyActiveOrgUuidsFailsClosedWithoutZeroArgOrX()

    /**
     * Sanity check on the happy path: a single active organisation UUID
     * still produces a real filter (orX() called with a non-empty argument
     * list, never with zero arguments), preserving existing behaviour.
     */
    public function testSingleActiveOrgUuidBuildsOrXWithPredicates(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->groupManager->method('getUserGroupIds')->willReturn(['users']);

        $this->userSession->method('getUser')->willReturn($user);
        $this->organisationMapper->method('getActiveOrganisationWithFallback')
            ->willReturn('11111111-1111-1111-1111-111111111111');

        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturn('eq-expr');
        $expr->expects($this->once())
            ->method('orX')
            ->with($this->logicalAnd($this->isType('string')))
            ->willReturnCallback(function (...$args) {
                // orX() must be called WITH arguments — never zero-arg.
                $this->assertNotEmpty($args);
                return $this->createMock(\OCP\DB\QueryBuilder\ICompositeExpression::class);
            });

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturn(':param1');
        $qb->expects($this->once())->method('andWhere');

        $this->invokeApplyActiveOrgFilter(
            qb: $qb,
            user: $user,
            activeOrgUuids: ['11111111-1111-1111-1111-111111111111'],
            allowNullOrg: false,
            organisationColumn: 'organisation'
        );
    }//end testSingleActiveOrgUuidBuildsOrXWithPredicates()
}//end class
