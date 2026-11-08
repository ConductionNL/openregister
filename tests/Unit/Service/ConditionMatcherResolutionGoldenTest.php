<?php

/**
 * Golden test: how ConditionMatcher resolves tokens and decides a match.
 *
 * Pins, on fixed inputs, every dynamic token resolution for a signed-in and
 * an anonymous caller (bare and dotted organisation and user forms, unknown
 * dotted properties that resolve to null, literals and non-strings, operand
 * recursion, `$memberOf` with and without a resolver), the per-subject
 * memo of the active organisation including the failure path, and the
 * allowed and refused outcomes of objectMatchesConditions().
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use Exception;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\OperatorEvaluator;
use OCA\OpenRegister\Service\OrganisationContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Rbac\MemberOfResolver;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden outcomes of token resolution and condition matching.
 */
class ConditionMatcherResolutionGoldenTest extends TestCase {

	/**
	 * How often the organisation service was asked for the active organisation.
	 *
	 * @var int
	 */
	private int $orgLookups = 0;

	/**
	 * Warning messages logged.
	 *
	 * @var array<int,string>
	 */
	private array $warnings = [];

	private function matcher(bool $signedIn, bool $orgFails = false, bool $withResolver = false): ConditionMatcher {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$user->method('getEMailAddress')->willReturn('alice@example.org');
		$user->method('getDisplayName')->willReturn('Alice A');

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		if ($signedIn === true) {
			$session = $this->createMock(IUserSession::class);
			$session->method('getUser')->willReturn($user);
		}

		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('getActiveOrganisation')->willReturnCallback(
			function () use ($orgFails): ?Organisation {
				$this->orgLookups++;
				if ($orgFails === true) {
					throw new Exception('no org');
				}

				$organisation = new Organisation();
				$organisation->setUuid('org-' . (OrganisationContext::current() ?? 'session'));
				return $organisation;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($organisations);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn(['admin', 'staff']);

		$resolver = null;
		if ($withResolver === true) {
			$resolver = $this->createMock(MemberOfResolver::class);
			$resolver->method('resolveOperators')->willReturnCallback(
				static fn (array $operators, ?string $userId, callable $resolveSibling): ?array => [
					'$in' => ['m-' . ($userId ?? 'anon')],
					'siblings' => array_map($resolveSibling, array_diff_key($operators, [MemberOfResolver::OPERATOR => true])),
				]
			);
		}

		return new ConditionMatcher(
			userSession: $session,
			container: $container,
			operatorEvaluator: new OperatorEvaluator(logger: $logger),
			logger: $logger,
			groupManager: $groups,
			memberOfResolver: $resolver,
		);
	}//end matcher()

	/**
	 * The tokens resolved by every case, with what they resolve to signed in and anonymous.
	 *
	 * @return array<string,array{0:mixed,1:mixed,2:mixed}>
	 */
	public static function tokens(): array {
		return [
			'organisation'            => ['$organisation', 'org-session', 'org-session'],
			'activeOrganisation'      => ['$activeOrganisation', 'org-session', 'org-session'],
			'organisation.uuid'       => ['$organisation.uuid', 'org-session', 'org-session'],
			'activeOrganisation.uuid' => ['$activeOrganisation.uuid', 'org-session', 'org-session'],
			'organisation.name'       => ['$organisation.name', null, null],
			'organisationX literal'   => ['$organisationX', '$organisationX', '$organisationX'],
			'userId'                  => ['$userId', 'alice', null],
			'user'                    => ['$user', 'alice', null],
			'user.uid'                => ['$user.uid', 'alice', null],
			'user.email'              => ['$user.email', 'alice@example.org', null],
			'user.displayName'        => ['$user.displayName', 'Alice A', null],
			'user.groups'             => ['$user.groups', ['admin', 'staff'], null],
			'user.bogus'              => ['$user.bogus', null, null],
			'users literal'           => ['$users', '$users', '$users'],
			'NOW literal'             => ['$NOW', '$NOW', '$NOW'],
			'plain literal'           => ['open', 'open', 'open'],
			'empty string'            => ['', '', ''],
			'int'                     => [5, 5, 5],
			'bool'                    => [false, false, false],
			'null'                    => [null, null, null],
			'operands'                => [
				['$eq' => '$user', '$in' => ['$userId', 'x']],
				['$eq' => 'alice', '$in' => ['alice', 'x']],
				['$eq' => null, '$in' => [null, 'x']],
			],
		];
	}//end tokens()

	/**
	 * A token resolves to the pinned value, signed in and anonymous.
	 *
	 * @param mixed $token     The match value.
	 * @param mixed $signedIn  What it resolves to for a signed-in caller.
	 * @param mixed $anonymous What it resolves to for an anonymous caller.
	 *
	 * @dataProvider tokens
	 *
	 * @return void
	 */
	public function testTokenResolution(mixed $token, mixed $signedIn, mixed $anonymous): void {
		self::assertSame($signedIn, $this->matcher(signedIn: true)->resolveDynamicValue(value: $token));
		self::assertSame($anonymous, $this->matcher(signedIn: false)->resolveDynamicValue(value: $token));
	}//end testTokenResolution()

	public function testNowIsTheSqlNativeFormat(): void {
		$now = $this->matcher(signedIn: false)->resolveDynamicValue(value: '$now');
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $now);
	}//end testNowIsTheSqlNativeFormat()

	public function testUnknownDottedPropertiesWarn(): void {
		$matcher = $this->matcher(signedIn: true);
		$matcher->resolveDynamicValue(value: '$user.bogus');
		$matcher->resolveDynamicValue(value: '$organisation.name');

		self::assertSame(
			[
				'[ConditionMatcher] Unknown $user.<property> dotted token — returning null (deny)',
				'[ConditionMatcher] Unknown $organisation.<property> dotted token — returning null (deny)',
			],
			$this->warnings
		);
	}//end testUnknownDottedPropertiesWarn()

	public function testMemberOfGoesToTheResolverOrDenies(): void {
		$value = [MemberOfResolver::OPERATOR => ['field' => 'members'], '$ne' => '$user'];

		self::assertSame(
			['$in' => ['m-alice'], 'siblings' => ['$ne' => 'alice']],
			$this->matcher(signedIn: true, withResolver: true)->resolveDynamicValue(value: $value)
		);
		self::assertSame(
			['$in' => ['m-anon'], 'siblings' => ['$ne' => null]],
			$this->matcher(signedIn: false, withResolver: true)->resolveDynamicValue(value: $value)
		);
		self::assertNull($this->matcher(signedIn: true)->resolveDynamicValue(value: $value));
	}//end testMemberOfGoesToTheResolverOrDenies()

	public function testActiveOrganisationIsMemoisedPerSubject(): void {
		$matcher = $this->matcher(signedIn: true);

		$seen   = [];
		$seen[] = $matcher->resolveDynamicValue(value: '$organisation');
		$seen[] = $matcher->resolveDynamicValue(value: '$organisation.uuid');
		$seen[] = OrganisationContext::run('ctx', static fn (): mixed => $matcher->resolveDynamicValue(value: '$organisation'));
		$seen[] = OrganisationContext::run('ctx', static fn (): mixed => $matcher->resolveDynamicValue(value: '$activeOrganisation'));
		$seen[] = $matcher->resolveDynamicValue(value: '$activeOrganisation');

		self::assertSame(['org-session', 'org-session', 'org-ctx', 'org-ctx', 'org-session'], $seen);
		self::assertSame(2, $this->orgLookups);
	}//end testActiveOrganisationIsMemoisedPerSubject()

	public function testAFailedOrganisationLookupIsMemoisedAsNull(): void {
		$matcher = $this->matcher(signedIn: false, orgFails: true);

		self::assertNull($matcher->resolveDynamicValue(value: '$organisation'));
		self::assertNull($matcher->resolveDynamicValue(value: '$organisation.uuid'));
		self::assertSame(1, $this->orgLookups);
		self::assertFalse($matcher->objectMatchesConditions(['_organisation' => 'org-session'], ['_organisation' => '$organisation']));
	}//end testAFailedOrganisationLookupIsMemoisedAsNull()

	/**
	 * Each case: object, match, signed-in outcome, anonymous outcome.
	 *
	 * @return array<string,array{0:array,1:array,2:bool,3:bool}>
	 */
	public static function conditionCases(): array {
		$self = ['@self' => ['owner' => 'alice', 'organisation' => 'org-session']];
		return [
			'string equal'                 => [['status' => 'open'], ['status' => 'open'], true, true],
			'string differs'               => [['status' => 'closed'], ['status' => 'open'], false, false],
			'int equal'                    => [['n' => 3], ['n' => 3], true, true],
			'int vs numeric string'        => [['n' => 3], ['n' => '3'], false, false],
			'bool equal'                   => [['b' => true], ['b' => true], true, true],
			'bool differs'                 => [['b' => false], ['b' => true], false, false],
			'owner is caller'              => [$self, ['_owner' => '$userId'], true, false],
			'owner is other'               => [['@self' => ['owner' => 'bob']], ['_owner' => '$userId'], false, false],
			'organisation'                 => [$self, ['_organisation' => '$organisation'], true, true],
			'groups operator'              => [['g' => 'staff'], ['g' => ['$in' => '$user.groups']], true, false],
			'resolved relation id'         => [['parent' => ['id' => 'p1', 'name' => 'P']], ['parent' => 'p1'], true, true],
			'array without id'             => [['tags' => ['a']], ['tags' => 'a'], false, false],
			'null match, null value'       => [['x' => null], ['x' => null], true, true],
			'null match, missing value'    => [[], ['x' => null], true, true],
			'null match, set value'        => [['x' => 'y'], ['x' => null], false, false],
			'unknown dotted user token'    => [['x' => null], ['x' => '$user.bogus'], false, false],
			'operator $gt'                 => [['n' => 5], ['n' => ['$gt' => 3]], true, true],
			'operator $gt fails'           => [['n' => 2], ['n' => ['$gt' => 3]], false, false],
			'unknown metadata refused'     => [['@self' => ['bogus' => null]], ['_bogus' => null], false, false],
			'all must hold'                => [['a' => 1, 'b' => 2], ['a' => 1, 'b' => 3], false, false],
			'empty match admits'           => [['a' => 1], [], true, true],
		];
	}//end conditionCases()

	/**
	 * A match is allowed or refused as pinned, signed in and anonymous.
	 *
	 * @param array $object    The object.
	 * @param array $match     The match conditions.
	 * @param bool  $signedIn  The outcome for a signed-in caller.
	 * @param bool  $anonymous The outcome for an anonymous caller.
	 *
	 * @dataProvider conditionCases
	 *
	 * @return void
	 */
	public function testObjectMatchesConditions(array $object, array $match, bool $signedIn, bool $anonymous): void {
		self::assertSame($signedIn, $this->matcher(signedIn: true)->objectMatchesConditions($object, $match));
		self::assertSame($anonymous, $this->matcher(signedIn: false)->objectMatchesConditions($object, $match));
	}//end testObjectMatchesConditions()

	public function testAnObjectMatchValueIsNotReadAsAnArray(): void {
		$this->expectException(\Error::class);
		$this->matcher(signedIn: true)->objectMatchesConditions(['a' => 1], ['a' => new \stdClass()]);
	}//end testAnObjectMatchValueIsNotReadAsAnArray()
}//end class
