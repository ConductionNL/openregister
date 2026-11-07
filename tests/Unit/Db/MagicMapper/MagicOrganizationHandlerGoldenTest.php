<?php

/**
 * MagicOrganizationHandlerGoldenTest
 *
 * Golden test for the organisation boundary and the deleted lens, written on
 * the untouched code before the unit-level refactor of MagicOrganizationHandler,
 * OrganisationScopeCondition, OrganisationScopeMembership and DeletedLens. It
 * pins both the ALLOWED and the REFUSED outcomes: which organisations a
 * caller's query is limited to (system context, user, admin, admin bypass,
 * SaaS mode, no active organisation, organisation context, shared master
 * data), the SQL condition each decision renders to, the in-memory membership
 * answer, and which soft-deleted rows a list sees.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\DeletedLens;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\OrganisationScopeCondition;
use OCA\OpenRegister\Db\MagicMapper\OrganisationScopeMembership;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\AnonymousEvaluationContext;
use OCA\OpenRegister\Service\OrganisationContext;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCA\OpenRegister\Service\SharedMasterDataService;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler
 * @covers \OCA\OpenRegister\Db\MagicMapper\OrganisationScopeCondition
 * @covers \OCA\OpenRegister\Db\MagicMapper\OrganisationScopeMembership
 * @covers \OCA\OpenRegister\Db\MagicMapper\DeletedLens
 */
class MagicOrganizationHandlerGoldenTest extends TestCase {

	private const SAAS = '{"saasMode":true}';

	/**
	 * Build a handler from a caller description.
	 *
	 * Keys: user (null|'user'|'admin'), cli (bool), saas (bool), orgs (list),
	 * fallback (?string active organisation), orgsThrow (bool), holders (list|null:
	 * null means the resolver is unavailable).
	 *
	 * @param array<string, mixed> $caller The caller.
	 *
	 * @return MagicOrganizationHandler
	 */
	private function handler(array $caller): MagicOrganizationHandler {
		$caller = array_merge(
			['user' => 'user', 'cli' => true, 'saas' => false, 'orgs' => [], 'fallback' => null, 'orgsThrow' => false, 'holders' => []],
			$caller
		);

		$user = null;
		if ($caller['user'] !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($caller['user']);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn($caller['user'] === 'admin' ? ['admin', 'users'] : ['users']);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($caller['saas'] === true ? self::SAAS : '');

		$container = $this->container(caller: $caller);

		$handler = $this->getMockBuilder(MagicOrganizationHandler::class)
			->setConstructorArgs([$session, $groups, $appConfig, $container, $this->createMock(LoggerInterface::class)])
			->onlyMethods(['isCommandLine'])
			->getMock();
		$handler->method('isCommandLine')->willReturn($caller['cli']);

		return $handler;
	}//end handler()

	/**
	 * The service container behind the handler.
	 *
	 * @param array<string, mixed> $caller The caller.
	 *
	 * @return ContainerInterface
	 */
	private function container(array $caller): ContainerInterface {
		$orgService = $this->createMock(OrganisationServiceInterface::class);
		if ($caller['orgsThrow'] === true) {
			$orgService->method('getUserActiveOrganisations')->willThrowException(new \RuntimeException('down'));
		} else {
			$orgService->method('getUserActiveOrganisations')->willReturn($caller['orgs']);
		}

		$active = null;
		if ($caller['fallback'] !== null) {
			$active = new Organisation();
			$active->setUuid($caller['fallback']);
		}

		$orgService->method('getActiveOrganisation')->willReturn($active);

		$shared = $this->createMock(SharedMasterDataService::class);
		$shared->method('holdersForResource')->willReturn($caller['holders'] ?? []);
		$sharedAvailable = ($caller['holders'] !== null);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($orgService, $shared, $sharedAvailable) {
				if ($id === 'OCA\OpenRegister\Service\OrganisationServiceInterface') {
					return $orgService;
				}

				if ($id === SharedMasterDataService::class && $sharedAvailable === true) {
					return $shared;
				}

				throw new \RuntimeException('no service '.$id);
			}
		);

		return $container;
	}//end container()

	/**
	 * Callers and the scope each one must be limited to.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: array{mode: string, uuids: array<int, string>}}>
	 */
	public static function scopeCases(): array {
		$all = ['mode' => MagicOrganizationHandler::SCOPE_ALL, 'uuids' => []];
		$none = ['mode' => MagicOrganizationHandler::SCOPE_NONE, 'uuids' => []];
		$nullOnly = ['mode' => MagicOrganizationHandler::SCOPE_NULL_ONLY, 'uuids' => []];
		$inA = ['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['org-a']];
		$inOrNullA = ['mode' => MagicOrganizationHandler::SCOPE_IN_OR_NULL, 'uuids' => ['org-a']];

		return [
			'cli without user is system'                  => [['user' => null], [], $all],
			'cli without user in saas is not system'      => [['user' => null, 'saas' => true, 'orgs' => ['org-a']], [], $inA],
			'cli without user in saas, no org'            => [['user' => null, 'saas' => true], [], $none],
			'web without user is not system'              => [['user' => null, 'cli' => false], [], $none],
			'web without user, with org'                  => [['user' => null, 'cli' => false, 'orgs' => ['org-a']], [], $inA],
			'web system operation is system'              => [['user' => null, 'cli' => false], ['system' => true], $all],
			'cli anonymous evaluation is not system'      => [['user' => null], ['anonymous' => true], $none],
			'cli anonymous evaluation, with org'          => [['user' => null, 'orgs' => ['org-a']], ['anonymous' => true], $inA],
			'user is confined to org'                     => [['orgs' => ['org-a']], [], $inA],
			'user with several orgs'                      => [['orgs' => ['org-a', 'org-b']], [], ['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['org-a', 'org-b']]],
			'user without org sees nothing'               => [[], [], $none],
			'user in cli is not system'                   => [['orgs' => ['org-a']], ['system' => true], $inA],
			'user fallback active org'                    => [['fallback' => 'org-f'], [], ['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['org-f']]],
			'user org service failing sees nothing'       => [['orgsThrow' => true, 'fallback' => 'org-f'], [], $none],
			'user bypass does nothing'                    => [['orgs' => ['org-a']], ['bypass' => true], $inA],
			'admin with org also sees org-less'           => [['user' => 'admin', 'orgs' => ['org-a']], [], $inOrNullA],
			'admin without org sees only org-less'        => [['user' => 'admin'], [], $nullOnly],
			'admin org service failing sees org-less'     => [['user' => 'admin', 'orgsThrow' => true], [], $nullOnly],
			'admin bypass reads all'                      => [['user' => 'admin'], ['bypass' => true], $all],
			'admin bypass in saas stays in org'           => [['user' => 'admin', 'saas' => true, 'orgs' => ['org-a']], ['bypass' => true], $inOrNullA],
			'admin bypass in saas without org'            => [['user' => 'admin', 'saas' => true], ['bypass' => true], $nullOnly],
			'shared holders widen with register'          => [['orgs' => ['org-a'], 'holders' => ['org-h', 'org-a']], ['register' => 1], ['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['org-a', 'org-h']]],
			'shared holders widen with schema'            => [['user' => 'admin', 'orgs' => ['org-a'], 'holders' => ['org-h']], ['schema' => 2], ['mode' => MagicOrganizationHandler::SCOPE_IN_OR_NULL, 'uuids' => ['org-a', 'org-h']]],
			'shared holders need a table'                 => [['orgs' => ['org-a'], 'holders' => ['org-h']], [], $inA],
			'shared holders need a resolver'              => [['orgs' => ['org-a'], 'holders' => null], ['register' => 1], $inA],
			'shared holders do not open an empty scope'   => [['holders' => ['org-h']], ['register' => 1], $none],
			'context confines admin with bypass'          => [['user' => 'admin', 'orgs' => ['org-c']], ['context' => 'org-c', 'bypass' => true], ['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['org-c']]],
			'context confines the system'                 => [['user' => null, 'orgs' => ['org-c']], ['context' => 'org-c'], ['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['org-c']]],
			'context confines a system operation'         => [['user' => null, 'cli' => false, 'orgs' => ['org-c']], ['context' => 'org-c', 'system' => true], ['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['org-c']]],
			'unknown context org admits nothing'          => [['user' => 'admin'], ['context' => 'org-x'], $none],
			'context with holders'                        => [['orgs' => ['org-c'], 'holders' => ['org-h', 'org-c']], ['context' => 'org-c', 'register' => 1, 'schema' => 2], ['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['org-c', 'org-h']]],
			'context fallback org'                        => [['fallback' => 'org-c'], ['context' => 'org-c'], ['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['org-c']]],
		];
	}//end scopeCases()

	/**
	 * Run a read inside the ambient contexts the call names.
	 *
	 * @param array<string, mixed> $call The call.
	 * @param callable             $read The read.
	 *
	 * @return mixed
	 */
	private function inContexts(array $call, callable $read): mixed {
		if (($call['anonymous'] ?? false) === true) {
			$inner = $read;
			$read = static fn () => AnonymousEvaluationContext::run($inner);
		}

		if (isset($call['context']) === true) {
			$innerCtx = $read;
			$ctx = $call['context'];
			$read = static fn () => OrganisationContext::run($ctx, $innerCtx);
		}

		if (($call['system'] ?? false) === true) {
			$innerSys = $read;
			$read = static fn () => SystemOperationContext::run($innerSys);
		}

		return $read();
	}//end inContexts()

	/**
	 * Every caller gets exactly its scope, no wider and no narrower.
	 *
	 * @dataProvider scopeCases
	 *
	 * @param array<string, mixed> $caller   The caller.
	 * @param array<string, mixed> $call     The call.
	 * @param array<string, mixed> $expected The scope.
	 *
	 * @return void
	 */
	public function testTheScopeIsPinned(array $caller, array $call, array $expected): void {
		$handler = $this->handler(caller: $caller);
		$scope = $this->inContexts(
			$call,
			static fn (): array => $handler->resolveOrganizationScope(
				adminBypassEnabled: ($call['bypass'] ?? false),
				registerId: ($call['register'] ?? null),
				schemaId: ($call['schema'] ?? null)
			)
		);

		$this->assertSame($expected, $scope);
	}//end testTheScopeIsPinned()

	/**
	 * A recording query builder: renders expressions to strings.
	 *
	 * @param array<int, string> $where Collects every andWhere().
	 *
	 * @return IQueryBuilder
	 */
	private function queryBuilder(array &$where): IQueryBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('in')->willReturnCallback(static fn ($x, $y): string => $x.' IN '.$y);
		$expr->method('eq')->willReturnCallback(static fn ($x, $y): string => $x.' = '.$y);
		$expr->method('isNull')->willReturnCallback(static fn ($x): string => $x.' IS NULL');
		$expr->method('orX')->willReturnCallback(
			fn (...$parts): ICompositeExpression => $this->composite(parts: $parts)
		);

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(
			static fn ($value, $type = IQueryBuilder::PARAM_STR): string => ':'.json_encode($value).'#'.$type
		);
		$qb->method('andWhere')->willReturnCallback(
			static function ($condition) use (&$where, &$qb) {
				$where[] = (string) $condition;
				return $qb;
			}
		);

		return $qb;
	}//end queryBuilder()

	/**
	 * A composite OR expression that renders to a string.
	 *
	 * @param array<int, mixed> $parts The parts.
	 *
	 * @return ICompositeExpression
	 */
	private function composite(array $parts): ICompositeExpression {
		return new class($parts) implements ICompositeExpression {
			/**
			 * @param array<int, mixed> $parts The parts.
			 */
			public function __construct(private array $parts) {
			}

			public function addMultiple(array $parts = []): ICompositeExpression {
				return $this;
			}

			public function add($part): ICompositeExpression {
				return $this;
			}

			public function count(): int {
				return count($this->parts);
			}

			public function getType(): string {
				return 'OR';
			}

			public function __toString(): string {
				return '('.implode(' OR ', array_map('strval', $this->parts)).')';
			}
		};
	}//end composite()

	/**
	 * Scopes and the SQL condition each one renders to.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: mixed, 2: array<int, string>}>
	 */
	public static function conditionCases(): array {
		$str = IQueryBuilder::PARAM_STR;
		$arr = IQueryBuilder::PARAM_STR_ARRAY;
		return [
			'all adds nothing'             => [['mode' => 'all', 'uuids' => []], null, []],
			'all adds nothing even orAlso' => [['mode' => 'all', 'uuids' => []], 'x = 1', []],
			'none refuses every row'       => [['mode' => 'none', 'uuids' => []], null, ['1 = 0']],
			'none with orAlso'             => [['mode' => 'none', 'uuids' => []], 'pub = 1', ['(1 = 0 OR pub = 1)']],
			'null only'                    => [['mode' => 'null-only', 'uuids' => []], null, ['t._organisation IS NULL']],
			'in one org'                   => [['mode' => 'in', 'uuids' => ['a']], null, ['(t._organisation = :"a"#'.$str.')']],
			'in two orgs'                  => [['mode' => 'in', 'uuids' => ['a', 'b']], null, ['(t._organisation IN :["a","b"]#'.$arr.')']],
			'in or null one org'           => [['mode' => 'in-or-null', 'uuids' => ['a']], null, ['(t._organisation = :"a"#'.$str.' OR t._organisation IS NULL)']],
			'in or null two orgs'          => [['mode' => 'in-or-null', 'uuids' => ['a', 'b']], null, ['(t._organisation IN :["a","b"]#'.$arr.' OR t._organisation IS NULL)']],
			'in with published orAlso'     => [['mode' => 'in', 'uuids' => ['a']], 't.published = 1', ['((t._organisation = :"a"#'.$str.') OR t.published = 1)']],
			'unknown mode reads as in'     => [['mode' => 'bogus', 'uuids' => ['a', 'b']], null, ['(t._organisation IN :["a","b"]#'.$arr.')']],
		];
	}//end conditionCases()

	/**
	 * The SQL each scope renders is pinned.
	 *
	 * @dataProvider conditionCases
	 *
	 * @param array<string, mixed> $scope    The scope.
	 * @param mixed                $orAlso   The extra branch.
	 * @param array<int, string>   $expected The andWhere() calls.
	 *
	 * @return void
	 */
	public function testTheConditionIsPinned(array $scope, mixed $orAlso, array $expected): void {
		$where = [];
		(new OrganisationScopeCondition())->apply($this->queryBuilder($where), $scope, $orAlso);

		$this->assertSame($expected, $where);
	}//end testTheConditionIsPinned()

	/**
	 * The named parameters a scope binds are pinned: the IN list is always
	 * created, and a single organisation then adds its own `=` parameter.
	 *
	 * @return void
	 */
	public function testTheNamedParametersArePinned(): void {
		$cases = [
			[['mode' => 'in', 'uuids' => ['a']], [[['a'], IQueryBuilder::PARAM_STR_ARRAY], ['a', IQueryBuilder::PARAM_STR]]],
			[['mode' => 'in-or-null', 'uuids' => ['a', 'b']], [[['a', 'b'], IQueryBuilder::PARAM_STR_ARRAY]]],
			[['mode' => 'none', 'uuids' => ['a']], []],
			[['mode' => 'null-only', 'uuids' => []], []],
			[['mode' => 'all', 'uuids' => ['a']], []],
		];
		foreach ($cases as $index => [$scope, $expected]) {
			$params = [];
			$qb = $this->getMockBuilder(IQueryBuilder::class)->getMock();
			$expr = $this->createMock(IExpressionBuilder::class);
			$expr->method('in')->willReturn('in');
			$expr->method('eq')->willReturn('eq');
			$expr->method('isNull')->willReturn('null');
			$expr->method('orX')->willReturnCallback(fn (...$parts): ICompositeExpression => $this->composite(parts: $parts));
			$qb->method('expr')->willReturn($expr);
			$qb->method('createNamedParameter')->willReturnCallback(
				static function ($value, $type = IQueryBuilder::PARAM_STR) use (&$params): string {
					$params[] = [$value, $type];
					return ':p'.count($params);
				}
			);
			(new OrganisationScopeCondition())->apply($qb, $scope);
			$this->assertSame($expected, $params, 'case '.$index);
		}
	}//end testTheNamedParametersArePinned()

	/**
	 * applyOrganizationFilter() renders the decided scope, widened by org-less rows on request.
	 *
	 * @return void
	 */
	public function testApplyOrganizationFilterRendersTheDecision(): void {
		$str = IQueryBuilder::PARAM_STR;
		$cases = [
			[['orgs' => ['org-a']], false, ['(t._organisation = :"org-a"#'.$str.')']],
			[['orgs' => ['org-a']], true, ['(t._organisation = :"org-a"#'.$str.' OR t._organisation IS NULL)']],
			[[], false, ['1 = 0']],
			[[], true, ['t._organisation IS NULL']],
			[['user' => null], true, []],
			[['user' => 'admin'], false, []],
		];
		foreach ($cases as $index => [$caller, $withOrgless, $expected]) {
			$where = [];
			$this->handler(caller: $caller)->applyOrganizationFilter(
				$this->queryBuilder($where),
				($caller['user'] ?? '') === 'admin',
				null,
				null,
				$withOrgless
			);
			$this->assertSame($expected, $where, 'case '.$index);
		}
	}//end testApplyOrganizationFilterRendersTheDecision()

	/**
	 * Only IN and NONE are widened by org-less rows.
	 *
	 * @return void
	 */
	public function testAdmitOrganisationlessIsPinned(): void {
		$this->assertSame(['mode' => 'in-or-null', 'uuids' => ['a']], MagicOrganizationHandler::admitOrganisationless(['mode' => 'in', 'uuids' => ['a']]));
		$this->assertSame(['mode' => 'null-only', 'uuids' => []], MagicOrganizationHandler::admitOrganisationless(['mode' => 'none', 'uuids' => ['a']]));
		$this->assertSame(['mode' => 'all', 'uuids' => ['a']], MagicOrganizationHandler::admitOrganisationless(['mode' => 'all', 'uuids' => ['a']]));
		$this->assertSame(['mode' => 'null-only', 'uuids' => []], MagicOrganizationHandler::admitOrganisationless(['mode' => 'null-only', 'uuids' => []]));
		$this->assertSame(['mode' => 'in-or-null', 'uuids' => ['a']], MagicOrganizationHandler::admitOrganisationless(['mode' => 'in-or-null', 'uuids' => ['a']]));
		$this->assertSame(['mode' => 'bogus', 'uuids' => ['a']], MagicOrganizationHandler::admitOrganisationless(['mode' => 'bogus', 'uuids' => ['a']]));
		$this->assertSame(['uuids' => ['a']], MagicOrganizationHandler::admitOrganisationless(['uuids' => ['a']]));
	}//end testAdmitOrganisationlessIsPinned()

	/**
	 * The admin override setting defaults to on, and only an explicit false turns it off.
	 *
	 * @return void
	 */
	public function testAdminOverrideSettingIsPinned(): void {
		$cases = ['' => true, 'not json' => true, '{}' => true, '{"adminOverride":false}' => false, '{"adminOverride":true}' => true];
		foreach ($cases as $config => $expected) {
			$appConfig = $this->createMock(IAppConfig::class);
			$appConfig->method('getValueString')->willReturn((string) $config);
			$handler = new MagicOrganizationHandler(
				$this->createMock(IUserSession::class),
				$this->createMock(IGroupManager::class),
				$appConfig,
				$this->createMock(ContainerInterface::class),
				$this->createMock(LoggerInterface::class)
			);
			$this->assertSame($expected, $handler->isAdminOverrideEnabled(), 'config '.$config);
		}
	}//end testAdminOverrideSettingIsPinned()

	/**
	 * In-memory membership answers.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string|null, 2: bool}>
	 */
	public static function membershipCases(): array {
		return [
			'all holds org'               => [['mode' => 'all'], 'a', true],
			'all holds null'              => [['mode' => 'all'], null, true],
			'none refuses org'            => [['mode' => 'none', 'uuids' => ['a']], 'a', false],
			'none refuses null'           => [['mode' => 'none'], null, false],
			'null-only holds null'        => [['mode' => 'null-only'], null, true],
			'null-only refuses org'       => [['mode' => 'null-only', 'uuids' => ['a']], 'a', false],
			'in holds member'             => [['mode' => 'in', 'uuids' => ['a', 'b']], 'b', true],
			'in refuses other'            => [['mode' => 'in', 'uuids' => ['a']], 'c', false],
			'in refuses null'             => [['mode' => 'in', 'uuids' => ['a']], null, false],
			'in refuses empty string'     => [['mode' => 'in', 'uuids' => ['a']], '', false],
			'in without uuids refuses'    => [['mode' => 'in'], 'a', false],
			'in-or-null holds member'     => [['mode' => 'in-or-null', 'uuids' => ['a']], 'a', true],
			'in-or-null holds null'       => [['mode' => 'in-or-null', 'uuids' => ['a']], null, true],
			'in-or-null refuses other'    => [['mode' => 'in-or-null', 'uuids' => ['a']], 'c', false],
			'unknown mode refuses org'    => [['mode' => 'bogus', 'uuids' => ['a']], 'a', false],
			'unknown mode refuses null'   => [['mode' => 'bogus'], null, false],
			'missing mode refuses'        => [['uuids' => ['a']], 'a', false],
		];
	}//end membershipCases()

	/**
	 * The in-memory membership answer is pinned.
	 *
	 * @dataProvider membershipCases
	 *
	 * @param array<string, mixed> $scope        The scope.
	 * @param string|null          $organisation The row's organisation.
	 * @param bool                 $expected     Whether it holds.
	 *
	 * @return void
	 */
	public function testMembershipIsPinned(array $scope, ?string $organisation, bool $expected): void {
		$this->assertSame($expected, OrganisationScopeMembership::holds($scope, $organisation));
	}//end testMembershipIsPinned()

	/**
	 * Queries and the deleted lens each one asks for.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function lensCases(): array {
		return [
			'absent'                          => [[], DeletedLens::EXCLUDE],
			'false'                           => [['_deleted' => false], DeletedLens::EXCLUDE],
			'string false'                    => [['_deleted' => 'false'], DeletedLens::EXCLUDE],
			'true'                            => [['_deleted' => true], DeletedLens::ONLY],
			'string true'                     => [['_deleted' => 'true'], DeletedLens::ONLY],
			'string 1'                        => [['_deleted' => '1'], DeletedLens::ONLY],
			'string yes'                      => [['_deleted' => 'yes'], DeletedLens::ONLY],
			'any'                             => [['_deleted' => 'any'], DeletedLens::ANY],
			'ANY padded'                      => [['_deleted' => ' ANY '], DeletedLens::ANY],
			'unknown string'                  => [['_deleted' => 'everything'], DeletedLens::EXCLUDE],
			'null'                            => [['_deleted' => null], DeletedLens::EXCLUDE],
			'array'                           => [['_deleted' => ['any']], DeletedLens::EXCLUDE],
			'includeDeleted true'             => [['_includeDeleted' => true], DeletedLens::ANY],
			'includeDeleted string true'      => [['_includeDeleted' => 'true'], DeletedLens::ANY],
			'includeDeleted false'            => [['_includeDeleted' => 'false'], DeletedLens::EXCLUDE],
			'includeDeleted unknown'          => [['_includeDeleted' => 'maybe'], DeletedLens::EXCLUDE],
			'deleted false wins over include' => [['_deleted' => false, '_includeDeleted' => true], DeletedLens::EXCLUDE],
			'deleted true wins over include'  => [['_deleted' => true, '_includeDeleted' => true], DeletedLens::ONLY],
		];
	}//end lensCases()

	/**
	 * The deleted lens a query asks for is pinned, and an unknown value never widens.
	 *
	 * @dataProvider lensCases
	 *
	 * @param array<string, mixed> $query    The query.
	 * @param string               $expected The lens.
	 *
	 * @return void
	 */
	public function testTheLensIsPinned(array $query, string $expected): void {
		$this->assertSame($expected, (new DeletedLens())->mode($query));
	}//end testTheLensIsPinned()

	/**
	 * The predicate for each lens is pinned.
	 *
	 * @return void
	 */
	public function testTheLensPredicateIsPinned(): void {
		$lens = new DeletedLens();
		$this->assertNull($lens->condition(DeletedLens::ANY, 't.'));
		$this->assertSame('t._deleted IS NOT NULL', $lens->condition(DeletedLens::ONLY, 't.'));
		$this->assertSame('t._deleted IS NULL', $lens->condition(DeletedLens::EXCLUDE, 't.'));
		$this->assertSame('_deleted IS NULL', $lens->condition('bogus', ''));
	}//end testTheLensPredicateIsPinned()
}//end class
