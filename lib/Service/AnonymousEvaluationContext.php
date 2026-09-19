<?php

/**
 * Ambient marker for a forced-anonymous evaluation scope.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Marks that the current call evaluates access AS AN ANONYMOUS CALLER, whatever
 * session or process context it runs in.
 *
 * The public-endpoint contract of OpenCatalogi's `/api/search` (SCH-PFTS-001,
 * WOO-536) is that every caller sees the same rows and the same `total`. The
 * RBAC layer reads the subject from `IUserSession` at roughly a dozen points,
 * and two of them widen the result set without a user at all: the CLI bypass
 * (`$user === null && PHP_SAPI === 'cli'`) and the system-operation scope
 * ({@see SystemOperationContext}). Clearing the session subject alone would
 * therefore make an anonymous evaluation under PHPUnit or occ MORE permissive,
 * not less. This marker closes those two doors for the duration of the scope;
 * {@see \OCA\OpenRegister\Service\ObjectService::runAsAnonymous()} clears the
 * subject and opens the scope in one move.
 *
 * Deliberately NOT a query key. `_rbac` and `_multitenancy` travel in the query
 * dict and are stripped from request parameters by the controllers; a
 * `_forceAnonymous=false` that slipped through would switch the guarantee off
 * from the outside. A static scope has no request-side representation at all
 * (WOO-578, hard requirement).
 *
 * Narrowing wins over elevating: while this scope is active,
 * {@see SystemOperationContext::isActive()} answers false.
 *
 * DO NOT OPEN THIS SCOPE INSIDE A SYSTEM OPERATION. Most consumers of the
 * system scope lose trust when it yields, which is the intent — they deny where
 * they would have allowed. Two do the opposite: MagicMapper's
 * `suppressLifecycleEvents()` and SaveObjects' bulk dispatch use it to WITHHOLD
 * work, so inside an anonymous scope they would start firing again — a config
 * import that wakes every listening app per object, which is the storm that
 * suppression exists to prevent. Not reachable today: the only caller is a
 * read-only public search and nothing in this app opens the scope internally.
 * It is a constraint on the next caller, not a live bug.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class AnonymousEvaluationContext {

	/**
	 * Nesting depth; > 0 means active.
	 *
	 * @var int
	 */
	private static int $depth = 0;


	/**
	 * Not instantiable — static scope only.
	 */
	private function __construct() {
	}//end __construct()


	/**
	 * Execute an operation inside a forced-anonymous evaluation scope.
	 *
	 * @param callable $operation The operation to run.
	 *
	 * @return mixed Whatever the operation returns.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public static function run(callable $operation) {
		self::$depth++;
		try {
			return $operation();
		} finally {
			self::$depth--;
		}
	}//end run()


	/**
	 * Whether a forced-anonymous evaluation scope is active.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public static function isActive(): bool {
		return self::$depth > 0;
	}//end isActive()
}//end class
