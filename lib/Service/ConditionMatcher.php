<?php

/**
 * Condition Matcher
 *
 * Evaluates match conditions for property-level RBAC authorization rules.
 * Handles dynamic variable resolution, object value lookup, and delegates
 * operator-based comparisons to OperatorEvaluator.
 *
 * Extracted from PropertyRbacHandler to keep class complexity manageable.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @since 2.0.0 Extracted from PropertyRbacHandler
 *
 * @spec openspec/specs/actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Condition matcher for RBAC match expressions
 *
 * Evaluates whether an object satisfies a set of match conditions,
 * including dynamic variable resolution and operator-based comparisons.
 */
class ConditionMatcher {

	/**
	 * Cached active organisation UUID
	 *
	 * @var string|null
	 */
	private ?string $cachedActiveOrg = null;

	/**
	 * Supported `$user.<property>` dot-path tokens.
	 *
	 * Any `$user.<X>` token NOT present in this list is treated as an
	 * unknown variable: it resolves to `null` and a warning is logged.
	 * This closes the silent-deny gap documented in the Wave-11.5 audit
	 * (`/tmp/wave11-or-engine-primitives.md` Section B4) where dotted-path
	 * references like `$user.uid` fell through `resolveDynamicValue` as
	 * literal strings and silently never matched.
	 *
	 * Keep this list in sync with {@see resolveUserDotProperty()}.
	 *
	 * @var string[]
	 */
	private const SUPPORTED_USER_DOT_PROPERTIES = [
		'uid',
		'email',
		'displayName',
		'groups',
	];

	/**
	 * Supported `$organisation.<property>` dot-path tokens.
	 *
	 * Currently only `uuid` is exposed so this keeps parity with the bare
	 * `$organisation` resolution. Extending this requires keeping
	 * {@see resolveOrganisationDotProperty()} in sync.
	 *
	 * @var string[]
	 */
	private const SUPPORTED_ORGANISATION_DOT_PROPERTIES = [
		'uuid',
	];

	/**
	 * Constructor for ConditionMatcher
	 *
	 * @param IUserSession $userSession User session for current user context
	 * @param ContainerInterface $container Container for service injection
	 * @param OperatorEvaluator $operatorEvaluator Operator evaluator for comparisons
	 * @param LoggerInterface $logger Logger for debugging
	 * @param IGroupManager|null $groupManager Group manager for resolving `$user.groups` (optional;
	 *                                         {@see resolveUserGroups()} falls back to an empty array
	 *                                         when not wired — Wave-12 Fix 4)
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly ContainerInterface $container,
		private readonly OperatorEvaluator $operatorEvaluator,
		private readonly LoggerInterface $logger,
		private readonly ?IGroupManager $groupManager = null,
	) {
	}//end __construct()

	/**
	 * Check if object data matches all conditions
	 *
	 * @param array $object Object data to check
	 * @param array $match Match conditions
	 *
	 * @return bool True if object matches all conditions
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md#requirement-conditional-scopes-with-dynamic-variables
	 */
	public function objectMatchesConditions(array $object, array $match): bool {
		foreach ($match as $property => $value) {
			if ($this->singleConditionMatches(object: $object, property: $property, value: $value) === false) {
				return false;
			}
		}//end foreach

		return true;
	}//end objectMatchesConditions()

	/**
	 * Filter out organisation matching for create operations
	 *
	 * On create, there's no existing object to match organisation against,
	 * so we skip organisation-based conditions.
	 *
	 * @param array $match Match conditions
	 *
	 * @return array Filtered match conditions
	 *
	 * @spec openspec/specs/actions/spec.md
	 */
	public function filterOrganisationMatchForCreate(array $match): array {
		$organisationKeys = ['_organisation', 'organisation'];
		$organisationValues = ['$organisation', '$activeOrganisation'];

		$filtered = [];
		foreach ($match as $property => $value) {
			// Skip if this is an organisation match condition.
			if (in_array($property, $organisationKeys, true) === true) {
				if (is_string($value) === true && in_array($value, $organisationValues, true) === true) {
					continue;
				}
			}

			$filtered[$property] = $value;
		}

		return $filtered;
	}//end filterOrganisationMatchForCreate()

	/**
	 * Check if a single match condition is satisfied
	 *
	 * @param array $object Object data to check
	 * @param string $property Property name from the match condition
	 * @param mixed $value Expected value or operator expression
	 *
	 * @return bool True if the condition is satisfied
	 *
	 * @spec openspec/specs/actions/spec.md
	 */
	private function singleConditionMatches(array $object, string $property, mixed $value): bool {
		// Get object value, checking both direct property and @self.
		$objectValue = $this->unwrapResolvedRelation(
			value: $this->getObjectValue(object: $object, property: $property)
		);

		// Resolve dynamic variables in the match value.
		$resolvedValue = $this->resolveDynamicValue(value: $value);

		// If dynamic variable resolved to null, condition cannot be met.
		if ($value !== $resolvedValue && $resolvedValue === null) {
			return false;
		}

		// Simple value: equals comparison.
		if (is_string($resolvedValue) === true
			|| is_numeric($resolvedValue) === true
			|| is_bool($resolvedValue) === true
		) {
			return $objectValue === $resolvedValue;
		}

		// Operator object.
		if (is_array($resolvedValue) === true) {
			return $this->operatorEvaluator->valueMatchesOperator(value: $objectValue, operators: $resolvedValue);
		}

		// Null value: check if object value is null.
		if ($resolvedValue === null && $objectValue !== null) {
			return false;
		}

		return true;
	}//end singleConditionMatches()

	/**
	 * Unwrap resolved relations to their scalar id.
	 *
	 * When a property has been expanded into its full related object (array with
	 * an 'id' key), RBAC conditions still compare against the scalar id. Mirrors
	 * the behaviour of the pre-unification PermissionHandler::evaluateMatchConditions
	 * — without this, a rule like {"match": {"parent": "uuid-123"}} would flip from
	 * allow to deny for any schema where "parent" is a resolved relation
	 * (list-vs-find drift). Arrays without an 'id' key are not resolved relations
	 * and pass through unchanged.
	 *
	 * @param mixed $value Raw value from the object (may be a scalar, null, or
	 *                     an array representing a resolved relation or a plain
	 *                     array-valued property).
	 *
	 * @return mixed The unwrapped scalar id, or the original value if not a
	 *               resolved relation.
	 */
	private function unwrapResolvedRelation(mixed $value): mixed {
		if (is_array($value) === true && isset($value['id']) === true) {
			return $value['id'];
		}

		return $value;
	}//end unwrapResolvedRelation()

	/**
	 * Get a value from the object, checking both direct property and @self
	 *
	 * @param array $object Object data
	 * @param string $property Property name
	 *
	 * @return mixed Property value or null
	 */
	private function getObjectValue(array $object, string $property): mixed {
		// Check direct property first.
		if (isset($object[$property]) === true) {
			return $object[$property];
		}

		// For underscore-prefixed properties, also check @self.
		if (str_starts_with($property, '_') === true) {
			$selfProperty = substr($property, 1);
			if (isset($object['@self'][$selfProperty]) === true) {
				return $object['@self'][$selfProperty];
			}
		}

		return null;
	}//end getObjectValue()

	/**
	 * Resolve dynamic variable values
	 *
	 * Supports special variables:
	 * - $organisation / $activeOrganisation: Current user's active organisation UUID
	 * - $userId / $user: Current user's ID
	 * - $now: Current datetime as 'Y-m-d H:i:s' (SQL-native format)
	 *
	 * For operator arrays (e.g. {"$lte": "$now"}), resolves dynamic values
	 * inside operator operands recursively.
	 *
	 * The `$now` format MUST stay aligned with
	 * {@see \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler::resolveDynamicValue()}
	 * — both paths evaluate the same authorization JSON, and for text/JSON-stored
	 * date columns the comparison is a raw lexicographic string compare. A format
	 * mismatch causes list (SQL) and find (PHP) endpoints to disagree on objects
	 * whose stored dates use a different separator (e.g. ISO 8601 "T" vs space).
	 * See `rbac-scopes/spec.md` scenario "Dynamic `$now` variable resolves to a
	 * canonical SQL-native format".
	 *
	 * @param mixed $value The value to resolve
	 *
	 * @return mixed The resolved value, or null if variable cannot be resolved
	 *
	 * PUBLIC because it is the ONE token resolver for the whole RBAC stack.
	 * `MagicRbacHandler`'s SQL emitters kept their own copy that recognised only
	 * the BARE tokens and passed every dotted form through as a literal string —
	 * so a rule using `$user.groups` resolved to the user's groups on the
	 * single-object path and compared against the literal `'$user.groups'` on the
	 * list path, granting on one and denying on the other. That is the same
	 * list-vs-find divergence class that already forced the `$now` format
	 * alignment and `unwrapResolvedRelation()`, and what ADR-011 exists to
	 * prevent. Callers MUST treat a null result as deny; the SQL emitter emits an
	 * impossible predicate rather than dropping the condition.
	 *
	 * @spec openspec/changes/shared-credentials-and-flows/specs/flow-sharing/spec.md#requirement-the-single-object-and-list-access-decisions-agree
	 */
	public function resolveDynamicValue(mixed $value): mixed {
		// For operator arrays, resolve dynamic values inside operands.
		if (is_array($value) === true) {
			$resolved = [];
			foreach ($value as $key => $operand) {
				$resolved[$key] = $this->resolveDynamicValue(value: $operand);
			}

			return $resolved;
		}

		if (is_string($value) === false) {
			return $value;
		}

		// $organisation (bare and dotted forms).
		if ($value === '$organisation' || $value === '$activeOrganisation') {
			return $this->getActiveOrganisationUuid();
		}

		if (str_starts_with($value, '$organisation.') === true
			|| str_starts_with($value, '$activeOrganisation.') === true
		) {
			$property = substr($value, (int)strpos($value, '.') + 1);
			return $this->resolveOrganisationDotProperty(property: $property, originalToken: $value);
		}

		// $userId / $user (bare and dotted forms).
		if ($value === '$userId' || $value === '$user') {
			return $this->userSession->getUser()?->getUID();
		}

		if (str_starts_with($value, '$user.') === true) {
			$property = substr($value, strlen('$user.'));
			return $this->resolveUserDotProperty(property: $property, originalToken: $value);
		}

		// $now — MUST stay 'Y-m-d H:i:s' to match the SQL path, which compares
		// text/JSON-stored dates lexicographically. ISO 8601's "T" separator once
		// made list and find disagree.
		if ($value === '$now') {
			return (new DateTime())->format('Y-m-d H:i:s');
		}

		return $value;
	}//end resolveDynamicValue()

	/**
	 * Resolve a dotted `$user.<property>` token.
	 *
	 * Supports:
	 *  - `$user.uid`          → current user's UID (same as bare `$user`/`$userId`)
	 *  - `$user.email`        → primary email address (null if none)
	 *  - `$user.displayName`  → display name (falls back to UID)
	 *  - `$user.groups`       → array of group IDs the user belongs to
	 *
	 * Unknown properties return `null` and log a warning. A `null` resolved
	 * value causes the match clause to fail (deny), which closes the silent
	 * pass-through that earlier let `$user.unknownThing` be compared as a
	 * literal string. See Wave-11.5 audit Section B4 / Wave-12 Fix 4.
	 *
	 * @param string $property The property after the dot (e.g. `uid`).
	 * @param string $originalToken The full token (e.g. `$user.uid`) for log context.
	 *
	 * @return mixed Resolved value, or `null` if unsupported / no user.
	 */
	private function resolveUserDotProperty(string $property, string $originalToken): mixed {
		if (in_array($property, self::SUPPORTED_USER_DOT_PROPERTIES, true) === false) {
			$this->logger->warning(
				message: '[ConditionMatcher] Unknown $user.<property> dotted token — returning null (deny)',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'token' => $originalToken,
					'supported' => self::SUPPORTED_USER_DOT_PROPERTIES,
				]
			);
			return null;
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			// Anonymous principal: no $user.* property is resolvable.
			return null;
		}

		// `default` arm omitted on purpose: SUPPORTED_USER_DOT_PROPERTIES
		// gates entry; anything else returned earlier via the in_array check.
		return match ($property) {
			'uid' => $user->getUID(),
			'email' => $user->getEMailAddress(),
			'displayName' => $user->getDisplayName(),
			'groups' => $this->resolveUserGroups(user: $user),
		};
	}//end resolveUserDotProperty()

	/**
	 * Resolve the current user's group IDs for `$user.groups`.
	 *
	 * Falls back to an empty array when no GroupManager was injected (e.g.
	 * tests that constructed the matcher with the legacy 4-arg signature).
	 *
	 * @param IUser $user The user whose groups to resolve.
	 *
	 * @return array<int, string> The group IDs.
	 */
	private function resolveUserGroups(IUser $user): array {
		if ($this->groupManager === null) {
			$this->logger->warning(
				message: '[ConditionMatcher] $user.groups requested but no IGroupManager available — returning empty array',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return [];
		}

		try {
			return $this->groupManager->getUserGroupIds($user);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[ConditionMatcher] Failed to resolve $user.groups',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return [];
		}
	}//end resolveUserGroups()

	/**
	 * Resolve a dotted `$organisation.<property>` token.
	 *
	 * Currently only `uuid` is exposed (parity with the bare `$organisation`).
	 * Unknown properties return `null` and log a warning so silent-deny is
	 * surfaced in NC logs.
	 *
	 * @param string $property The property after the dot.
	 * @param string $originalToken The full token for log context.
	 *
	 * @return mixed Resolved value, or `null`.
	 */
	private function resolveOrganisationDotProperty(string $property, string $originalToken): mixed {
		if (in_array($property, self::SUPPORTED_ORGANISATION_DOT_PROPERTIES, true) === false) {
			$this->logger->warning(
				message: '[ConditionMatcher] Unknown $organisation.<property> dotted token — returning null (deny)',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'token' => $originalToken,
					'supported' => self::SUPPORTED_ORGANISATION_DOT_PROPERTIES,
				]
			);
			return null;
		}

		// `default` arm omitted: SUPPORTED_ORGANISATION_DOT_PROPERTIES gates
		// entry above.
		return match ($property) {
			'uuid' => $this->getActiveOrganisationUuid(),
		};
	}//end resolveOrganisationDotProperty()

	/**
	 * Get the current user's active organisation UUID
	 *
	 * @return string|null The active organisation UUID or null
	 *
	 * @spec openspec/specs/actions/spec.md
	 */
	private function getActiveOrganisationUuid(): ?string {
		// Return cached value if available.
		if ($this->cachedActiveOrg !== null) {
			return $this->cachedActiveOrg;
		}

		try {
			$organisationService = $this->container->get('OCA\OpenRegister\Service\OrganisationService');
			$activeOrg = $organisationService->getActiveOrganisation();

			if ($activeOrg !== null) {
				$this->cachedActiveOrg = $activeOrg->getUuid();
				return $this->cachedActiveOrg;
			}
		} catch (\Exception $e) {
			$this->logger->debug(
				message: '[ConditionMatcher] Could not get active organisation',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
		}

		return null;
	}//end getActiveOrganisationUuid()
}//end class
