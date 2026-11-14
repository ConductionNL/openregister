<?php

/**
 * Unit tests for GraphQLRequestGuard.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Search\Service\GraphQL
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Search\Service\GraphQL;

use GraphQL\Error\Error;
use GraphQL\Language\Parser;
use OCA\OpenRegister\Search\Service\GraphQL\GraphQLRequestGuard;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Who may introspect the GraphQL schema, and the rate limit's bypass when it cannot count.
 */
#[CoversClass(GraphQLRequestGuard::class)]
final class GraphQLRequestGuardTest extends TestCase {

	/**
	 * A guard over an introspection setting and a caller.
	 *
	 * @param string      $introspection The graphql_introspection setting.
	 * @param string|null $uid           The signed-in user, or null.
	 * @param string      $remote        The caller's address.
	 *
	 * @return GraphQLRequestGuard
	 */
	private function guard(string $introspection, ?string $uid = null, string $remote = ''): GraphQLRequestGuard {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'graphql_introspection' => $introspection,
				default => $default,
			}
		);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturn($remote);

		return new GraphQLRequestGuard(appConfig: $config, userSession: $session, request: $request);
	}//end guard()

	/**
	 * Queries that introspect.
	 *
	 * @return array<string,array{string}>
	 */
	public static function introspectingQueries(): array {
		return [
			'__schema' => ['{ __schema { types { name } } }'],
			'__type' => ['query Q { __type(name: "Zaak") { name } }'],
		];
	}//end introspectingQueries()

	/**
	 * With introspection disabled, an introspecting query is refused for everyone.
	 *
	 * @param string $query The introspecting query.
	 *
	 * @return void
	 */
	#[DataProvider('introspectingQueries')]
	public function testDisabledIntrospectionIsRefused(string $query): void {
		try {
			$this->guard(introspection: 'disabled', uid: 'admin')->checkIntrospection(Parser::parse($query));
			$this->fail('An introspecting query was accepted.');
		} catch (Error $e) {
			$this->assertSame('Introspection is disabled', $e->getMessage());
			$this->assertSame(['code' => 'INTROSPECTION_DISABLED'], $e->getExtensions());
		}
	}//end testDisabledIntrospectionIsRefused()

	/**
	 * With introspection for signed-in users only, an anonymous caller is refused and a user passes.
	 *
	 * @return void
	 */
	public function testAuthenticatedIntrospectionNeedsAUser(): void {
		$document = Parser::parse('{ __schema { queryType { name } } }');

		try {
			$this->guard(introspection: 'authenticated')->checkIntrospection($document);
			$this->fail('An anonymous introspection was accepted.');
		} catch (Error $e) {
			$this->assertSame('Introspection requires authentication', $e->getMessage());
			$this->assertSame(['code' => 'INTROSPECTION_DISABLED'], $e->getExtensions());
		}

		$this->guard(introspection: 'authenticated', uid: 'anna')->checkIntrospection($document);
		$this->addToAssertionCount(1);
	}//end testAuthenticatedIntrospectionNeedsAUser()

	/**
	 * A query that does not introspect passes whatever the setting, and enabled lets everything through.
	 *
	 * @return void
	 */
	public function testOrdinaryQueriesAndEnabledIntrospectionPass(): void {
		$ordinary = Parser::parse('{ zaken { naam } } fragment F on Zaak { naam }');
		$introspecting = Parser::parse('{ __schema { types { name } } }');

		$this->guard(introspection: 'disabled')->checkIntrospection($ordinary);
		$this->guard(introspection: 'authenticated')->checkIntrospection($ordinary);
		$this->guard(introspection: 'enabled')->checkIntrospection($introspecting);
		$this->addToAssertionCount(3);
	}//end testOrdinaryQueriesAndEnabledIntrospectionPass()

	/**
	 * An unknown setting value refuses nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownSettingRefusesNothing(): void {
		$this->guard(introspection: 'sometimes')->checkIntrospection(Parser::parse('{ __type(name: "X") { name } }'));
		$this->addToAssertionCount(1);
	}//end testAnUnknownSettingRefusesNothing()

	/**
	 * Without a caller to count, or without APCu, the rate limit lets the request through.
	 *
	 * @return void
	 */
	public function testTheRateLimitPassesWhenItCannotCount(): void {
		if (function_exists('apcu_enabled') === true && apcu_enabled() === true) {
			$this->markTestSkipped('APCu is enabled here; this test covers the path without it.');
		}

		$this->guard(introspection: 'enabled')->checkRateLimit();
		$this->guard(introspection: 'enabled', uid: 'anna@example.org')->checkRateLimit();
		$this->guard(introspection: 'enabled', remote: '203.0.113.7')->checkRateLimit();
		$this->addToAssertionCount(3);
	}//end testTheRateLimitPassesWhenItCannotCount()
}//end class
