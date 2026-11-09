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

use OCA\OpenRegister\Service\Rbac\MemberOfResolver;
use OCA\OpenRegister\Service\Rbac\RecordMetadataConditions;
use OCP\IGroupManager;
use OCP\IUser;
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
	 * Active organisation UUID, memoised PER SUBJECT.
	 *
	 * Not a single value: the subject can change within one request — both
	 * ObjectService::runAs() and runAsAnonymous() swap it — and a flat memo would
	 * answer a later evaluation with an earlier caller's organisation.
	 *
	 * @var array<string, string|null>
	 */
	private array $cachedActiveOrg = [];

	/**
	 * Reads which metadata keys a record carries.
	 *
	 * @var RecordMetadataConditions
	 */
	private readonly RecordMetadataConditions $metadataConditions;

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
	 * Match tokens that resolve to the caller's active organisation.
	 *
	 * @var string[]
	 */
	public const ORGANISATION_TOKENS = [
		'$organisation',
		'$activeOrganisation',
		'$organisation.uuid',
		'$activeOrganisation.uuid',
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
	 * The variable family of each bare token.
	 *
	 * @var array<string, string>
	 */
	private const BARE_TOKEN_FAMILIES = [
		'$organisation' => 'organisation',
		'$activeOrganisation' => 'organisation',
		'$userId' => 'user',
		'$user' => 'user',
		'$now' => 'now',
	];

	/**
	 * The variable family of each dotted-token prefix.
	 *
	 * @var array<string, string>
	 */
	private const DOTTED_TOKEN_FAMILIES = [
		'$organisation.' => 'organisationDot',
		'$activeOrganisation.' => 'organisationDot',
		'$user.' => 'userDot',
	];

	/**
	 * Constructor for ConditionMatcher
	 *
	 * @param ConditionUserResolvers $users             The session, group manager (optional; without it
	 *                                                  `$user.groups` resolves to an empty array) and
	 *                                                  memberOf resolver (optional; without it a
	 *                                                  `$memberOf` rule admits nobody)
	 * @param ContainerInterface     $container         Container for service injection
	 * @param OperatorEvaluator      $operatorEvaluator Operator evaluator for comparisons
	 * @param LoggerInterface        $logger            Logger for debugging
	 */
	public function __construct(
		private readonly ConditionUserResolvers $users,
		private readonly ContainerInterface $container,
		private readonly OperatorEvaluator $operatorEvaluator,
		private readonly LoggerInterface $logger,
	) {
		$this->metadataConditions = new RecordMetadataConditions(logger: $logger);
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
		// A condition on metadata no record carries is never met, also where a
		// missing value would satisfy it (`null`, `$exists: false`).
		if ($this->namesUnknownMetadata(match: $match, object: $object) === true) {
			return false;
		}

		foreach ($match as $property => $value) {
			$objectValue = $this->unwrapResolvedRelation(
				value: $this->getObjectValue(object: $object, property: $property)
			);
			if ($this->conditionMet(objectValue: $objectValue, value: $value) === false) {
				return false;
			}
		}//end foreach

		return true;
	}//end objectMatchesConditions()

	/**
	 * Whether a match clause names record metadata that no record carries
	 *
	 * Such a condition can never match a record, so every caller fails closed
	 * on it: {@see objectMatchesConditions()} answers false, so an allow grants
	 * nothing, and a deny caller treats it as selecting the row. Each schema and
	 * key is logged once per matcher.
	 *
	 * @param array $match  Match conditions
	 * @param array $object The object being evaluated, for the schema its `@self` names
	 *
	 * @return bool True when at least one condition names unknown metadata
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function namesUnknownMetadata(array $match, array $object = []): bool {
		return $this->metadataConditions->refuses(match: $match, object: $object);
	}//end namesUnknownMetadata()

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
			if (in_array($property, $organisationKeys, true) === true
				&& is_string($value) === true
				&& in_array($value, $organisationValues, true) === true
			) {
				continue;
			}

			$filtered[$property] = $value;
		}

		return $filtered;
	}//end filterOrganisationMatchForCreate()

	/**
	 * Check if a single match condition is satisfied by an object's value
	 *
	 * @param mixed $objectValue The object's value for the condition's property, relations unwrapped
	 * @param mixed $value       Expected value or operator expression
	 *
	 * @return bool True if the condition is satisfied
	 *
	 * @spec openspec/specs/actions/spec.md
	 */
	private function conditionMet(mixed $objectValue, mixed $value): bool {
		// Resolve dynamic variables in the match value.
		$resolvedValue = $this->resolveDynamicValue(value: $value);

		// If dynamic variable resolved to null, condition cannot be met.
		if ($value !== $resolvedValue && $resolvedValue === null) {
			return false;
		}

		return $this->valueSatisfies(objectValue: $objectValue, resolvedValue: $resolvedValue);
	}//end conditionMet()

	/**
	 * Compare an object's value with a resolved match value
	 *
	 * A scalar compares strictly, an array is an operator object, a null
	 * requires a null value, and anything else is satisfied.
	 *
	 * @param mixed $objectValue   The object's value
	 * @param mixed $resolvedValue The match value with dynamic variables resolved
	 *
	 * @return bool True if the value satisfies the match value
	 */
	private function valueSatisfies(mixed $objectValue, mixed $resolvedValue): bool {
		if ($this->isScalarMatch(value: $resolvedValue) === true) {
			return $objectValue === $resolvedValue;
		}

		if (is_array($resolvedValue) === true) {
			return $this->operatorEvaluator->valueMatchesOperator(value: $objectValue, operators: $resolvedValue);
		}

		return ($resolvedValue === null && $objectValue !== null) === false;
	}//end valueSatisfies()

	/**
	 * Whether a match value is compared by strict equality: a string, number or bool
	 *
	 * @param mixed $value The resolved match value
	 *
	 * @return bool
	 */
	private function isScalarMatch(mixed $value): bool {
		return is_string($value) === true
			|| is_numeric($value) === true
			|| is_bool($value) === true;
	}//end isScalarMatch()

	/**
	 * Unwrap resolved relations to their scalar id.
	 *
	 * When a property has been expanded into its full related object (array with
	 * an 'id' key), RBAC conditions still compare against the scalar id. Mirrors
	 * the behaviour of the pre-unification PermissionHandlerInterface::evaluateMatchConditions
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
	 * Get a value from the object: `@self` for an underscore-prefixed property, else the body
	 *
	 * See {@see RecordMetadataConditions::valueIn()}. A key that names no record
	 * metadata never reaches this: {@see objectMatchesConditions()} refuses it first.
	 *
	 * @param array $object Object data
	 * @param string $property Property name
	 *
	 * @return mixed Property value or null
	 */
	private function getObjectValue(array $object, string $property): mixed {
		return $this->metadataConditions->valueIn(object: $object, property: $property);
	}//end getObjectValue()

	/**
	 * Resolve dynamic variable values
	 *
	 * Supports special variables:
	 * - $organisation / $activeOrganisation: Current user's active organisation UUID
	 * - $userId / $user: Current user's ID
	 * - $now: Current datetime as 'Y-m-d H:i:s' (SQL-native format)
	 * - {"$memberOf": {...}}: `$in` over the caller's membership set, see {@see MemberOfResolver::resolveOperators()}
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
		if (isset($value[MemberOfResolver::OPERATOR]) === true) {
			return $this->resolveMemberOf(operators: $value);
		}

		// For operator arrays, resolve dynamic values inside operands.
		if (is_array($value) === true) {
			return array_map(fn (mixed $operand): mixed => $this->resolveDynamicValue(value: $operand), $value);
		}

		if (is_string($value) === false) {
			return $value;
		}

		return $this->resolveToken(token: $value);
	}//end resolveDynamicValue()

	/**
	 * Resolve a `$memberOf` operator object, see {@see MemberOfResolver::resolveOperators()}
	 *
	 * Without a resolver the result is null, so the condition admits nobody.
	 *
	 * @param mixed $operators The operator object carrying `$memberOf`
	 *
	 * @return array|null The operators with `$in` over the caller's membership set, or null
	 */
	private function resolveMemberOf(mixed $operators): ?array {
		return $this->users->memberOfResolver?->resolveOperators(
			operators: $operators,
			userId: $this->users->userSession->getUser()?->getUID(),
			resolveSibling: fn (mixed $sibling): mixed => $this->resolveDynamicValue(value: $sibling)
		);
	}//end resolveMemberOf()

	/**
	 * Resolve one string token, or return it unchanged when it names no variable
	 *
	 * $now MUST stay 'Y-m-d H:i:s' to match the SQL path, which compares
	 * text/JSON-stored dates lexicographically. ISO 8601's "T" separator once
	 * made list and find disagree.
	 *
	 * @param string $token The string match value
	 *
	 * @return mixed The resolved value, or null if the variable cannot be resolved
	 */
	private function resolveToken(string $token): mixed {
		return match ($this->tokenFamily(token: $token)) {
			'organisation' => $this->getActiveOrganisationUuid(),
			'organisationDot' => $this->resolveOrganisationDotProperty(
				property: substr($token, (int)strpos($token, '.') + 1),
				originalToken: $token
			),
			'user' => $this->users->userSession->getUser()?->getUID(),
			'userDot' => $this->resolveUserDotProperty(property: substr($token, strlen('$user.')), originalToken: $token),
			'now' => date('Y-m-d H:i:s'),
			default => $token,
		};
	}//end resolveToken()

	/**
	 * Which variable family a string token belongs to, or 'literal'
	 *
	 * The bare tokens and the dotted prefixes are disjoint, so the bare
	 * lookup and the prefix scan never both match.
	 *
	 * @param string $token The string match value
	 *
	 * @return string One of organisation, organisationDot, user, userDot, now or literal
	 */
	private function tokenFamily(string $token): string {
		if (isset(self::BARE_TOKEN_FAMILIES[$token]) === true) {
			return self::BARE_TOKEN_FAMILIES[$token];
		}

		foreach (self::DOTTED_TOKEN_FAMILIES as $prefix => $family) {
			if (str_starts_with($token, $prefix) === true) {
				return $family;
			}
		}

		return 'literal';
	}//end tokenFamily()

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

		$user = $this->users->userSession->getUser();
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
		if ($this->users->groupManager === null) {
			$this->logger->warning(
				message: '[ConditionMatcher] $user.groups requested but no IGroupManager available — returning empty array',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return [];
		}

		try {
			return $this->users->groupManager->getUserGroupIds($user);
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
	 * @SuppressWarnings(PHPMD.StaticAccess) OrganisationContext is an ambient scope marker, read statically by design.
	 */
	private function getActiveOrganisationUuid(): ?string {
		// Keyed by the subject, because the subject can change within a request:
		// ObjectService::runAsAnonymous() and runAs() both swap it. A flat memo
		// resolved under an admin session would otherwise answer `@organisation.uuid`
		// with that admin's organisation inside an evaluation meant to be anonymous,
		// admitting their tenant's rows to a public read. An organisation
		// context is a subject of its own for the same reason.
		$subject = ($this->users->userSession->getUser()?->getUID() ?? '_anon');
		$contextUuid = OrganisationContext::current();
		if ($contextUuid !== null) {
			$subject = 'organisation-context:' . $contextUuid;
		}
		if (array_key_exists($subject, $this->cachedActiveOrg) === true) {
			return $this->cachedActiveOrg[$subject];
		}

		// The miss is memoised too, so an anonymous evaluation does not re-ask
		// the container once per condition.
		$this->cachedActiveOrg[$subject] = $this->lookupActiveOrganisationUuid();

		return $this->cachedActiveOrg[$subject];
	}//end getActiveOrganisationUuid()

	/**
	 * Ask the organisation service for the active organisation's UUID
	 *
	 * @return string|null The UUID, or null when there is none or the lookup fails
	 */
	private function lookupActiveOrganisationUuid(): ?string {
		try {
			$organisationService = $this->container->get('OCA\OpenRegister\Service\OrganisationServiceInterface');
			$activeOrg = $organisationService->getActiveOrganisation();

			if ($activeOrg !== null) {
				return $activeOrg->getUuid();
			}
		} catch (\Exception $e) {
			$this->logger->debug(
				message: '[ConditionMatcher] Could not get active organisation',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
		}

		return null;
	}//end lookupActiveOrganisationUuid()
}//end class
