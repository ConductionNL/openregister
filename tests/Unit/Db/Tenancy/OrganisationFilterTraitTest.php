<?php

/**
 * OrganisationFilterTrait unit test.
 *
 * The organisation filter a mapper adds to a query: skipped when tenancy is
 * off, nothing for an admin under an admin override outside SaaS mode, the
 * caller's own and parent organisations, rows without an organisation when
 * allowed, shared master data ids, and no rows at all when nothing scopes
 * the caller.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\Tenancy
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\Tenancy;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Tenancy\OrganisationFilterTrait;
use OCA\OpenRegister\Db\Tenancy\TenancyIdentityTrait;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A mapper-shaped host for the filter, with only the collaborators a case gives it.
 */
final class OrganisationFilterHost {
	use TenancyIdentityTrait;
	use OrganisationFilterTrait;

	/**
	 * The logger, when the case gives one.
	 *
	 * @var LoggerInterface
	 */
	protected LoggerInterface $logger;

	/**
	 * The app config, when the case gives one.
	 *
	 * @var IAppConfig
	 */
	protected IAppConfig $appConfig;

	/**
	 * The session, when the case gives one.
	 *
	 * @var IUserSession
	 */
	protected IUserSession $userSession;

	/**
	 * The organisation mapper, when the case gives one.
	 *
	 * @var OrganisationMapper
	 */
	protected OrganisationMapper $organisationMapper;

	/**
	 * The group manager, when the case gives one.
	 *
	 * @var IGroupManager
	 */
	protected IGroupManager $groupManager;

	/**
	 * Build the host with the given collaborators.
	 *
	 * @param array<string, object> $deps Collaborators by property name.
	 */
	public function __construct(array $deps) {
		foreach (['logger', 'appConfig', 'userSession', 'organisationMapper', 'groupManager'] as $name) {
			if (isset($deps[$name]) === true) {
				$this->{$name} = $deps[$name];
			}
		}
	}//end __construct()

	/**
	 * The mapped table.
	 *
	 * @return string
	 */
	public function getTableName(): string {
		return 'openregister_views';
	}//end getTableName()

	/**
	 * Run a trait method.
	 *
	 * @param string $method The method.
	 * @param array  $args   Named arguments.
	 *
	 * @return mixed
	 */
	public function call(string $method, array $args=[]): mixed {
		return $this->{$method}(...$args);
	}//end call()
}//end class

/**
 * @covers \OCA\OpenRegister\Db\Tenancy\OrganisationFilterTrait
 * @uses   \OCA\OpenRegister\Db\Tenancy\TenancyIdentityTrait
 * @uses   \OCA\OpenRegister\Service\TenancyCollaborators
 * @uses   \OCA\OpenRegister\Service\TenantLogRedactor
 */
final class OrganisationFilterTraitTest extends TestCase {

	private const ORG = 'aaaaaaaa-0000-0000-0000-000000000001';

	private const PARENT = 'aaaaaaaa-0000-0000-0000-000000000002';

	/**
	 * The where clauses the query got, as text.
	 *
	 * @var array<int, string>
	 */
	private array $where = [];

	/**
	 * What the logger received, as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * A query builder whose expressions read as text, recording its where clauses.
	 *
	 * @return IQueryBuilder
	 */
	private function queryBuilder(): IQueryBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static fn ($x, $y): string => $x . ' = ' . $y);
		$expr->method('in')->willReturnCallback(static fn ($x, $y): string => $x . ' IN ' . $y);
		$expr->method('isNull')->willReturnCallback(static fn ($x): string => $x . ' IS NULL');
		$composites = [];
		$expr->method('orX')->willReturnCallback(
			function (...$parts) use (&$composites): ICompositeExpression {
				$composite = $this->createMock(ICompositeExpression::class);
				$composites[spl_object_id($composite)] = '(' . implode(' OR ', $parts) . ')';

				return $composite;
			}
		);

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(
			static fn ($value): string => ':' . (is_array($value) === true ? implode(',', $value) : (string)$value)
		);
		$qb->method('andWhere')->willReturnCallback(
			function (...$where) use (&$qb, &$composites): IQueryBuilder {
				$clause = $where[0];
				if ($clause instanceof ICompositeExpression) {
					$clause = $composites[spl_object_id($clause)];
				}

				$this->where[] = (string)$clause;

				return $qb;
			}
		);

		return $qb;
	}//end queryBuilder()

	/**
	 * The host for a signed-in user active in the given organisation.
	 *
	 * @param string|null           $active  The active organisation, or null for none.
	 * @param array<string, string> $config  The app config string values.
	 * @param array<int, string>    $groups  The user's groups.
	 * @param bool                  $withLog Whether a logger is given.
	 *
	 * @return OrganisationFilterHost
	 */
	private function host(?string $active, array $config=[], array $groups=[], bool $withLog=true): OrganisationFilterHost {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$organisations = $this->createMock(OrganisationMapper::class);
		$organisations->method('getActiveOrganisationWithFallback')->willReturn($active);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default=''): string => ($config[$key] ?? $default)
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		$deps = ['userSession' => $session, 'organisationMapper' => $organisations, 'appConfig' => $appConfig, 'groupManager' => $groupManager];
		if ($withLog === true) {
			$logger = $this->createMock(LoggerInterface::class);
			foreach (['warning', 'info', 'debug'] as $level) {
				$logger->method($level)->willReturnCallback(
					function (string $message, array $context=[]) use ($level): void {
						$this->logged[] = [$level, $message, $context];
					}
				);
			}

			$deps['logger'] = $logger;
		}

		return new OrganisationFilterHost(deps: $deps);
	}//end host()

	/**
	 * The scope a mapper hands the filter.
	 *
	 * @param array<string, mixed> $overrides Keys that differ.
	 *
	 * @return array<string, mixed>
	 */
	private static function scope(array $overrides=[]): array {
		return array_merge(
			[
				'user' => null,
				'organisationColumn' => 't.organisation',
				'allowNullOrg' => false,
				'activeOrgUuids' => [self::ORG],
				'sharedIds' => [],
				'sharedIdColumn' => 't.id',
			],
			$overrides
		);
	}//end scope()

	/**
	 * Filtering is skipped when the caller turns tenancy off or the config disables it.
	 *
	 * @return void
	 */
	public function testFilteringIsSkippedWhenTenancyIsOff(): void {
		$this->assertTrue($this->host(active: self::ORG)->call(method: 'shouldSkipFiltering', args: ['multiTenancyEnabled' => false]));
		$this->assertFalse($this->host(active: self::ORG)->call(method: 'shouldSkipFiltering', args: ['multiTenancyEnabled' => true]));
		$this->assertTrue($this->host(active: self::ORG, config: ['multitenancy' => '{"enabled":false}'])->call(method: 'shouldSkipFiltering', args: ['multiTenancyEnabled' => true]));
		$this->assertFalse($this->host(active: self::ORG, config: ['multitenancy' => '{"adminOverride":true}'])->call(method: 'shouldSkipFiltering', args: ['multiTenancyEnabled' => true]));
	}//end testFilteringIsSkippedWhenTenancyIsOff()

	/**
	 * The admin override applies to admins outside SaaS mode only.
	 *
	 * @return void
	 */
	public function testTheAdminOverrideNeedsAnAdminOutsideSaasMode(): void {
		$admin = $this->createMock(IUser::class);

		$this->assertTrue($this->host(active: self::ORG, config: ['multitenancy' => '{"adminOverride":true}'], groups: ['admin'])->call(method: 'adminOverrides', args: ['user' => $admin]));
		$this->assertFalse($this->host(active: self::ORG, config: ['multitenancy' => '{"adminOverride":true,"saasMode":true}'], groups: ['admin'])->call(method: 'adminOverrides', args: ['user' => $admin]));
		$this->assertFalse($this->host(active: self::ORG, config: ['multitenancy' => '{"adminOverride":true}'], groups: ['staff'])->call(method: 'adminOverrides', args: ['user' => $admin]));
		$this->assertFalse($this->host(active: self::ORG, groups: ['admin'])->call(method: 'adminOverrides', args: ['user' => $admin]), 'no tenancy config, no override');
		$this->assertFalse($this->host(active: self::ORG, config: ['multitenancy' => '{"adminOverride":true}'], groups: ['admin'])->call(method: 'adminOverrides', args: ['user' => null]));
	}//end testTheAdminOverrideNeedsAnAdminOutsideSaasMode()

	/**
	 * The caller's own organisation and its parents scope the query, with null rows and shared ids when given.
	 *
	 * @return void
	 */
	public function testTheOwnAndParentOrganisationsScopeTheQuery(): void {
		$this->host(active: self::ORG)->call(
			method: 'applyActiveOrgFilter',
			args: ['qb' => $this->queryBuilder(), 'scope' => self::scope(overrides: ['activeOrgUuids' => [self::ORG, self::PARENT], 'allowNullOrg' => true, 'sharedIds' => [4, 9]])]
		);

		$this->assertSame(
			['(t.organisation = :' . self::ORG . ' OR t.organisation IN :' . self::PARENT . ' OR t.organisation IS NULL OR t.id IN :4,9)'],
			$this->where
		);
	}//end testTheOwnAndParentOrganisationsScopeTheQuery()

	/**
	 * Without a direct active organisation the hierarchy scopes the query; with neither, no rows.
	 *
	 * @return void
	 */
	public function testWithoutAnActiveOrganisationTheHierarchyScopesOrNothingDoes(): void {
		$host = $this->host(active: null);

		$host->call(method: 'applyActiveOrgFilter', args: ['qb' => $this->queryBuilder(), 'scope' => self::scope(overrides: ['activeOrgUuids' => [self::PARENT]])]);
		$host->call(method: 'applyActiveOrgFilter', args: ['qb' => $this->queryBuilder(), 'scope' => self::scope(overrides: ['activeOrgUuids' => []])]);

		$this->assertSame(['(t.organisation IN :' . self::PARENT . ')', '1 = 0'], $this->where);
		$warnings = array_values(array_filter($this->logged, static fn (array $line): bool => $line[0] === 'warning'));
		$this->assertCount(1, $warnings);
		$this->assertStringContainsString('failing closed', $warnings[0][1]);
	}//end testWithoutAnActiveOrganisationTheHierarchyScopesOrNothingDoes()

	/**
	 * An admin under the override adds no clause, and the override is logged without the user id.
	 *
	 * @return void
	 */
	public function testAnAdminOverrideAddsNoClauseAndIsLogged(): void {
		$admin = $this->createMock(IUser::class);
		$admin->method('getUID')->willReturn('root');

		$host = $this->host(active: self::ORG, config: ['multitenancy' => '{"adminOverride":true}'], groups: ['admin']);
		$host->call(method: 'applyActiveOrgFilter', args: ['qb' => $this->queryBuilder(), 'scope' => self::scope(overrides: ['user' => $admin])]);
		$host->call(method: 'applyNoActiveOrgFilter', args: ['qb' => $this->queryBuilder(), 'scope' => self::scope(overrides: ['user' => $admin, 'activeOrgUuids' => []])]);

		$this->assertSame([], $this->where);
		$info = array_values(array_filter($this->logged, static fn (array $line): bool => $line[0] === 'info'));
		$this->assertCount(1, $info);
		$this->assertSame('cross_tenant_access_admin_override', $info[0][2]['type']);
		$this->assertStringStartsWith('actor:', $info[0][2]['actor']);
		$this->assertNotSame('root', $info[0][2]['actor']);
	}//end testAnAdminOverrideAddsNoClauseAndIsLogged()

	/**
	 * Without an active organisation: rows without one when allowed, otherwise none.
	 *
	 * @return void
	 */
	public function testNoActiveOrganisationSeesOnlyUnownedRowsOrNothing(): void {
		$host = $this->host(active: null, withLog: false);

		$host->call(method: 'applyNoActiveOrgFilter', args: ['qb' => $this->queryBuilder(), 'scope' => self::scope(overrides: ['activeOrgUuids' => [], 'allowNullOrg' => true])]);
		$host->call(method: 'applyNoActiveOrgFilter', args: ['qb' => $this->queryBuilder(), 'scope' => self::scope(overrides: ['activeOrgUuids' => []])]);
		$host->call(method: 'applyActiveOrgFilter', args: ['qb' => $this->queryBuilder(), 'scope' => self::scope(overrides: ['activeOrgUuids' => []])]);

		$this->assertSame(['t.organisation IS NULL', '1 = 0', '1 = 0'], $this->where);
	}//end testNoActiveOrganisationSeesOnlyUnownedRowsOrNothing()

	/**
	 * A column is qualified with its table alias when there is one, and the session user is read.
	 *
	 * @return void
	 */
	public function testColumnQualificationAndSessionUser(): void {
		$host = $this->host(active: self::ORG);

		$this->assertSame('o.organisation', $host->call(method: 'buildQualifiedColumnName', args: ['columnName' => 'organisation', 'tableAlias' => 'o']));
		$this->assertSame('organisation', $host->call(method: 'buildQualifiedColumnName', args: ['columnName' => 'organisation', 'tableAlias' => '']));
		$this->assertSame('anna', $host->call(method: 'getUserFromSession')->getUID());

		$anonymousSession = $this->createMock(IUserSession::class);
		$anonymousSession->method('getUser')->willReturn(null);
		$this->assertNull((new OrganisationFilterHost(deps: ['userSession' => $anonymousSession]))->call(method: 'getUserFromSession'));
		$this->assertNull((new OrganisationFilterHost(deps: []))->call(method: 'getUserFromSession'));
	}//end testColumnQualificationAndSessionUser()
}//end class
