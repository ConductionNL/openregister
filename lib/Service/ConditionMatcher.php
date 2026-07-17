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
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Condition matcher for RBAC match expressions
 *
 * Evaluates whether an object satisfies a set of match conditions,
 * including dynamic variable resolution and operator-based comparisons.
 */
class ConditionMatcher
{

    /**
     * Cached active organisation UUID
     *
     * @var string|null
     */
    private ?string $cachedActiveOrg = null;

    /**
     * Constructor for ConditionMatcher
     *
     * @param IUserSession       $userSession       User session for current user context
     * @param ContainerInterface $container         Container for service injection
     * @param OperatorEvaluator  $operatorEvaluator Operator evaluator for comparisons
     * @param LoggerInterface    $logger            Logger for debugging
     */
    public function __construct(
        private readonly IUserSession $userSession,
        private readonly ContainerInterface $container,
        private readonly OperatorEvaluator $operatorEvaluator,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Check if object data matches all conditions
     *
     * @param array $object Object data to check
     * @param array $match  Match conditions
     *
     * @return bool True if object matches all conditions
     *
     * @spec openspec/specs/rbac-scopes/spec.md#requirement-conditional-scopes-with-dynamic-variables
     */
    public function objectMatchesConditions(array $object, array $match): bool
    {
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
    public function filterOrganisationMatchForCreate(array $match): array
    {
        $organisationKeys   = ['_organisation', 'organisation'];
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
     * @param array  $object   Object data to check
     * @param string $property Property name from the match condition
     * @param mixed  $value    Expected value or operator expression
     *
     * @return bool True if the condition is satisfied
     *
     * @spec openspec/specs/actions/spec.md
     */
    private function singleConditionMatches(array $object, string $property, mixed $value): bool
    {
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
    private function unwrapResolvedRelation(mixed $value): mixed
    {
        if (is_array($value) === true && isset($value['id']) === true) {
            return $value['id'];
        }

        return $value;
    }//end unwrapResolvedRelation()

    /**
     * Get a value from the object, checking both direct property and @self
     *
     * @param array  $object   Object data
     * @param string $property Property name
     *
     * @return mixed Property value or null
     */
    private function getObjectValue(array $object, string $property): mixed
    {
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
     */
    private function resolveDynamicValue(mixed $value): mixed
    {
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

        // Check for $organisation variable.
        if ($value === '$organisation' || $value === '$activeOrganisation') {
            return $this->getActiveOrganisationUuid();
        }

        // Check for $userId variable.
        if ($value === '$userId' || $value === '$user') {
            return $this->userSession->getUser()?->getUID();
        }

        // Check for $now variable.
        // MUST match MagicRbacHandler's SQL-path format (Y-m-d H:i:s) so that
        // list and find endpoints produce identical verdicts for text-column
        // date comparisons. Previously used 'c' (ISO 8601 with "T" separator),
        // which caused divergence against columns storing dates in SQL format.
        if ($value === '$now') {
            return (new DateTime())->format('Y-m-d H:i:s');
        }

        // Not a bare token. It may be a dotted token ($user.uid, …);
        // resolveDotted() returns any non-token string unchanged.
        // MUST stay aligned with MagicRbacHandler::resolveDotted().
        return $this->resolveDotted(token: $value);
    }//end resolveDynamicValue()

    /**
     * Resolve a dotted dynamic token to a scalar.
     *
     * Supported: `$user.uid`, `$user.email`, `$user.displayName`,
     * `$organisation.uuid` (parity with the bare `$organisation` token).
     *
     * An UNKNOWN token resolves to null, which the matcher treats as a deny
     * (see the null guard in matchesCondition). This is the point of the
     * method: before it existed, `$user.unknownThing` fell through as the
     * literal string `'$user.unknownThing'` and was compared against the
     * object's stored value — a rule that silently never matched, with no
     * diagnostic. Failing loudly to null is both safer and debuggable.
     *
     * `$user.groups` is deliberately NOT supported here. It resolves to an
     * array, which the SQL-path twin cannot express as a scalar equality
     * without an IN predicate; supporting it on this path alone would make
     * list and find endpoints disagree — the exact drift this class's
     * resolveDynamicValue() docblock forbids. Tracked separately.
     *
     * A string that is not a dotted token is returned unchanged, so this method
     * doubles as resolveDynamicValue()'s literal fallthrough.
     *
     * @param string $token The candidate token, including any leading `$`.
     *
     * @return string|null The resolved scalar, the unchanged literal, or null
     *                     when the token is a known prefix but an unknown field (deny).
     *
     * @spec openspec/specs/authorization-rbac/spec.md#requirement-dynamic-condition-tokens-resolve-by-dot-syntax-on-both-evaluators
     */
    private function resolveDotted(string $token): ?string
    {
        if (str_starts_with($token, '$user.') === false
            && str_starts_with($token, '$organisation.') === false
        ) {
            // Not a dotted token — a plain literal.
            return $token;
        }

        $user = $this->userSession->getUser();

        switch ($token) {
            case '$user.uid':
                return $user?->getUID();

            case '$user.email':
                return $user?->getEMailAddress();

            case '$user.displayName':
                return $user?->getDisplayName();

            case '$organisation.uuid':
                return $this->getActiveOrganisationUuid();

            default:
                $this->logger->warning(
                    message: '[ConditionMatcher] Unknown dynamic token in an authorization condition; '
                        .'resolving to null (deny). Supported: $user.uid, $user.email, '
                        .'$user.displayName, $organisation.uuid.',
                    context: [
                        'token' => $token,
                        'file'  => __FILE__,
                        'line'  => __LINE__,
                    ]
                );
                return null;
        }//end switch

    }//end resolveDotted()

    /**
     * Get the current user's active organisation UUID
     *
     * @return string|null The active organisation UUID or null
     *
     * @spec openspec/specs/actions/spec.md
     */
    private function getActiveOrganisationUuid(): ?string
    {
        // Return cached value if available.
        if ($this->cachedActiveOrg !== null) {
            return $this->cachedActiveOrg;
        }

        try {
            $organisationService = $this->container->get('OCA\OpenRegister\Service\OrganisationService');
            $activeOrg           = $organisationService->getActiveOrganisation();

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
