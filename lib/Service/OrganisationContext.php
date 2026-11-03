<?php

/**
 * OpenRegister OrganisationContext
 *
 * Scoped "act for organisation X" marker for code that serves callers who are
 * not Nextcloud users, such as portal accounts. While a scope is active the
 * organisation it names is the caller's organisation for every tenancy
 * decision: the organisation filter, the `$organisation` token in read rules,
 * and the organisation stamped on new objects.
 *
 * Like SystemOperationContext and AnonymousEvaluationContext it is a static
 * scope with no request-side representation: only PHP code can open it, never
 * a query parameter or header. Apps open it through
 * {@see \OCA\OpenRegister\Service\ObjectService::runForOrganisation()}, which
 * checks that the organisation exists and evaluates the callable as an
 * anonymous caller.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/act-for-an-organisation/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use InvalidArgumentException;

/**
 * Static scope holder naming the organisation code acts for.
 */
final class OrganisationContext {

	/**
	 * The organisation uuids of the open scopes, innermost last.
	 *
	 * @var array<int, string>
	 */
	private static array $stack = [];

	/**
	 * This class is a static scope holder and must not be instantiated.
	 */
	private function __construct() {
	}//end __construct()

	/**
	 * Run a callable acting for one organisation.
	 *
	 * Nested scopes compose: the innermost organisation applies, and the outer
	 * one is back in force when the inner callable ends, also on a throw.
	 *
	 * @param string   $organisationUuid The organisation to act for.
	 * @param callable $operation        The operation to run.
	 *
	 * @return mixed Whatever the callable returns.
	 *
	 * @throws InvalidArgumentException When the uuid is empty.
	 *
	 * @spec openspec/changes/act-for-an-organisation/specs/rbac-scopes/spec.md
	 */
	public static function run(string $organisationUuid, callable $operation) {
		if (trim($organisationUuid) === '') {
			throw new InvalidArgumentException(message: 'An organisation context needs an organisation uuid.');
		}

		self::$stack[] = $organisationUuid;

		try {
			return $operation();
		} finally {
			array_pop(self::$stack);
		}
	}//end run()

	/**
	 * Whether code is acting for an organisation.
	 *
	 * @return bool True inside run().
	 *
	 * @spec openspec/changes/act-for-an-organisation/specs/rbac-scopes/spec.md
	 */
	public static function isActive(): bool {
		return self::$stack !== [];
	}//end isActive()

	/**
	 * The multitenancy flag a read or write runs with.
	 *
	 * Inside a scope the organisation filter cannot be switched off, so a
	 * caller's `false` reads as `true`.
	 *
	 * @param bool $requested The flag the caller passed.
	 *
	 * @return bool True inside a scope, otherwise the requested flag.
	 *
	 * @spec openspec/changes/act-for-an-organisation/specs/rbac-scopes/spec.md
	 */
	public static function multitenancy(bool $requested): bool {
		return $requested === true || self::$stack !== [];
	}//end multitenancy()

	/**
	 * The organisation the innermost scope acts for.
	 *
	 * @return string|null The organisation uuid, or null outside a scope.
	 *
	 * @spec openspec/changes/act-for-an-organisation/specs/rbac-scopes/spec.md
	 */
	public static function current(): ?string {
		if (self::$stack === []) {
			return null;
		}

		return self::$stack[array_key_last(self::$stack)];
	}//end current()
}//end class
