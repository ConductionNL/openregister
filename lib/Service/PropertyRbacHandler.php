<?php

/**
 * Property-Level RBAC Handler
 *
 * This handler provides role-based access control (RBAC) filtering at the
 * property level. While schema-level RBAC controls access to entire objects,
 * property-level RBAC controls access to specific fields within objects.
 *
 * KEY RESPONSIBILITIES:
 * - Check if user can read specific properties on an object
 * - Check if user can update specific properties on an object
 * - Filter readable properties from outgoing data (RenderHandler)
 * - Validate writable properties on incoming data (ValidationHandler)
 *
 * AUTHORIZATION STRUCTURE:
 * Properties can define authorization rules in their schema definition:
 * {
 *   "properties": {
 *     "interneAantekening": {
 *       "type": "string",
 *       "authorization": {
 *         "read": [{ "group": "public", "match": { "_organisation": "$organisation" } }],
 *         "update": [{ "group": "public", "match": { "_organisation": "$organisation" } }]
 *       }
 *     }
 *   }
 * }
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
 * @since 2.0.0 Initial implementation for property-level RBAC
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Schema;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Property-level RBAC handler for fine-grained access control
 *
 * This class provides property-level RBAC filtering, ensuring that specific
 * fields within objects can have different access rules than the object itself.
 * Condition matching and operator evaluation are delegated to ConditionMatcher.
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 */
class PropertyRbacHandler {
	/**
	 * Constructor for PropertyRbacHandler
	 *
	 * @param IUserSession $userSession User session for current user context
	 * @param IGroupManager $groupManager Group manager for user group operations
	 * @param ConditionMatcher $conditionMatcher Condition matcher for match expressions
	 * @param LoggerInterface $logger Logger for debugging
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly ConditionMatcher $conditionMatcher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Check if the current user can read a specific property
	 *
	 * @param Schema $schema Schema containing property definition
	 * @param string $property Property name to check
	 * @param array $object Object data (for conditional matching)
	 *
	 * @return bool True if user can read the property
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#field-level-security (property-level read authorization:
	 *       evaluates the property's read rules against the current user's groups + object conditions)
	 */
	public function canReadProperty(Schema $schema, string $property, array $object): bool {
		return $this->checkPropertyAccess(
			schema: $schema,
			property: $property,
			object: $object,
			action: 'read'
		);
	}//end canReadProperty()

	/**
	 * Check if the current user can update a specific property
	 *
	 * @param Schema $schema Schema containing property definition
	 * @param string $property Property name to check
	 * @param array $object Object data (for conditional matching)
	 * @param bool $isCreate Whether this is a create operation
	 *
	 * @return bool True if user can update the property
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#field-level-security (property-level update authorization,
	 *       with create-mode organisation-match skipping per the FLS-on-create requirement)
	 */
	public function canUpdateProperty(
		Schema $schema,
		string $property,
		array $object,
		bool $isCreate = false,
	): bool {
		return $this->checkPropertyAccess(
			schema: $schema,
			property: $property,
			object: $object,
			action: 'update',
			isCreate: $isCreate
		);
	}//end canUpdateProperty()

	/**
	 * Filter an object to only include readable properties
	 *
	 * @param Schema $schema Schema containing property definitions
	 * @param array $object Object data to filter
	 *
	 * @return array Filtered object with only readable properties
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#fls-must-strip-restricted-fields-from-api-responses-and-export-outputs (strips unreadable
	 *       property-authorized fields from outgoing data; admin + no-property-auth short-circuit)
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-writeonly-properties-must-never-be-returned-on-any-read
	 *       (writeOnly stripping runs before the admin bypass — admin is NOT exempt from writeOnly)
	 */
	public function filterReadableProperties(Schema $schema, array $object): array {
		// Write-only stripping applies to EVERYONE, including admin: a write-only
		// property (secret/token) is never returned on read. This runs BEFORE the
		// admin short-circuit below precisely because admin is not exempt. It is a
		// fail-safe projection: the property may have been selected by the caller
		// (e.g. ?fields=apiToken) yet is still removed here because stripping is
		// applied after selection in the render path.
		$object = $this->stripWriteOnlyProperties(schema: $schema, object: $object);

		// If user is admin, return object as-is (writeOnly already stripped above).
		if ($this->isAdmin() === true) {
			return $object;
		}

		// If schema has no property-level authorization, return as-is.
		if ($schema->hasPropertyAuthorization() === false) {
			return $object;
		}

		// Get properties with authorization.
		// Returns associative array: propertyName => authorizationConfig.
		$propertiesWithAuth = $schema->getPropertiesWithAuthorization();

		// Filter out properties user cannot read.
		foreach (array_keys($propertiesWithAuth) as $propertyName) {
			// Only filter if the property exists in the object.
			if (array_key_exists($propertyName, $object) === false) {
				continue;
			}

			// Check if user can read this property.
			if ($this->canReadProperty(schema: $schema, property: $propertyName, object: $object) === false) {
				unset($object[$propertyName]);
				$this->logger->debug(
					message: '[PropertyRbacHandler] Filtered unreadable property',
					context: ['file' => __FILE__, 'line' => __LINE__, 'property' => $propertyName]
				);
			}
		}

		return $object;
	}//end filterReadableProperties()

	/**
	 * Strip write-only properties from outgoing object data.
	 *
	 * A property declared `writeOnly: true` (standard JSON Schema / OpenAPI
	 * keyword) is a write-only secret: it may be written but is NEVER returned
	 * on read, for ANY caller including admin. This is the correct semantic for
	 * secrets and tokens and is the platform-level answer to openregister#380
	 * (ADR-063 MCP tools have no field projection of their own, so stripping in
	 * OpenRegister's read path makes them inherit the redaction automatically).
	 *
	 * Fail-safe: only opted-in properties are removed; a schema with no
	 * writeOnly property returns the object untouched (backward compatible).
	 * The render path applies this AFTER caller-supplied field/extend selection,
	 * so a caller cannot re-surface a stripped property by naming it.
	 *
	 * @param Schema $schema Schema containing property definitions
	 * @param array $object Object data to filter
	 *
	 * @return array Object data with write-only properties removed
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-writeonly-properties-must-never-be-returned-on-any-read
	 *       (strips writeOnly properties for every reader; short-circuits when the schema has none)
	 */
	public function stripWriteOnlyProperties(Schema $schema, array $object): array {
		$hasProperties = $schema->hasWriteOnlyProperties();
		$paths = $schema->getWriteOnlyPaths();

		if ($hasProperties === false && empty($paths) === true) {
			return $object;
		}

		if ($hasProperties === true) {
			foreach ($schema->getWriteOnlyProperties() as $propertyName) {
				if (array_key_exists($propertyName, $object) === true) {
					unset($object[$propertyName]);
					$this->logger->debug(
						message: '[PropertyRbacHandler] Stripped write-only property',
						context: ['file' => __FILE__, 'line' => __LINE__, 'property' => $propertyName]
					);
				}
			}
		}

		foreach ($paths as $path) {
			$object = $this->stripWriteOnlyPath(path: $path, object: $object);
		}

		return $object;
	}//end stripWriteOnlyProperties()

	/**
	 * Remove one declared write-only dot-path from an outgoing array.
	 *
	 * This handles BOTH shapes OpenRegister renders a write-only value in,
	 * because the same declaration has to cover both and every caller of
	 * stripWriteOnlyProperties() passes one or the other:
	 *
	 * 1. The object BODY, where `configuration.authentication.client_secret`
	 *    is a nested structure to descend into and unset.
	 * 2. The `relations` search-index MIRROR, where SaveObject::scanForRelations()
	 *    flattens nested values into LITERAL dot-path keys — the mirror map is
	 *    already keyed `configuration.authentication.client_secret`. jsonSerialize()
	 *    surfaces that map as `@self.relations`, so a nested secret leaks there
	 *    even after the body strip, exactly as top-level writeOnly did in #429.
	 *
	 * Both operations are applied unconditionally and are mutually inert: a body
	 * has no literal dotted key (dots are not used in property names), and a flat
	 * mirror map has nothing to descend. That keeps this method total over every
	 * input the render path hands it, rather than relying on the caller to know
	 * which shape it holds.
	 *
	 * A declared path removes the value at that location AND its whole sub-tree —
	 * declaring `configuration.authentication` strips every key beneath it, and in
	 * the mirror that means every flattened key prefixed `configuration.authentication.`
	 * (this is what covers `configuration.authentication.keys.<apiKey>`, whose leaf
	 * segments are attacker-supplied and therefore cannot be enumerated in advance).
	 *
	 * @param string $path The declared dot-path.
	 * @param array $object The object body or relations mirror.
	 *
	 * @return array The array with the path removed.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-nested-writeonly-paths-must-never-be-returned-on-any-read
	 */
	private function stripWriteOnlyPath(string $path, array $object): array {
		// Shape 2: the flattened relations mirror. Remove an exact dot-path key
		// and every key beneath it.
		$prefix = $path . '.';
		foreach (array_keys($object) as $key) {
			$key = (string)$key;
			if ($key === $path || str_starts_with($key, $prefix) === true) {
				unset($object[$key]);
				$this->logger->debug(
					message: '[PropertyRbacHandler] Stripped write-only path from relations mirror',
					context: ['file' => __FILE__, 'line' => __LINE__, 'path' => $key]
				);
			}
		}

		// Shape 1: the nested object body. Walk the path by reference and unset
		// the final segment. Bail out the moment the path stops resolving —
		// a declared path that is absent from this object is normal (the value
		// was never set) and must not create keys on the way down.
		$segments = explode('.', $path);
		$leaf = array_pop($segments);
		$cursor = &$object;

		foreach ($segments as $segment) {
			if (is_array($cursor) === false || array_key_exists($segment, $cursor) === false) {
				unset($cursor);
				return $object;
			}

			$cursor = &$cursor[$segment];
		}

		if (is_array($cursor) === true && array_key_exists($leaf, $cursor) === true) {
			unset($cursor[$leaf]);
			$this->logger->debug(
				message: '[PropertyRbacHandler] Stripped write-only path',
				context: ['file' => __FILE__, 'line' => __LINE__, 'path' => $path]
			);
		}

		unset($cursor);

		return $object;
	}//end stripWriteOnlyPath()

	/**
	 * Collect the declared write-only locations that an incoming update payload OMITS.
	 *
	 * This is the detection half of the save-side preserve rule (openregister#463) —
	 * the exact mirror of stripWriteOnlyProperties(). The strip removes a declared
	 * location from everything going OUT; this reports which declared locations are
	 * missing from what came IN, so the caller can carry the stored value forward
	 * instead of destroying it.
	 *
	 * Why the rule has to exist at all: `writeOnly`'s own semantic ("a client may SEND
	 * this, the server never returns it") tacitly assumes the client re-sends the value.
	 * A client that was never given the value cannot. The natural round-trip — GET the
	 * object, edit one field, PUT it back — therefore re-sends a body with the secret
	 * missing, and PUT semantics (SaveObject::fillMissingSchemaPropertiesWithNull())
	 * nulls every schema property the payload omits. The read boundary was airtight and
	 * the write boundary silently deleted. That is not theoretical: nc-vue's generic
	 * CnFormDialog builds its submit body by spreading the loaded form data and has no
	 * writeOnly awareness at all, so editing ANY field of an OpenConnector Source wiped
	 * its credentials (openconnector#245).
	 *
	 * A top-level `writeOnly: true` property is treated as a single-segment dot-path, so
	 * both declaration surfaces collapse into one uniform rule and cannot drift apart.
	 *
	 * ── absent vs explicit null ──────────────────────────────────────────────────────
	 * ONLY an absent location is preserved. A location present with an explicit `null`
	 * is left exactly as the client sent it and therefore CLEARS the stored secret.
	 * The distinction is deliberate and load-bearing: if absent and explicit-null were
	 * treated alike, preserving the first would make clearing a secret IMPOSSIBLE — the
	 * value could be set and rotated but never removed, which is its own security defect
	 * (a decommissioned credential you cannot delete). Absent is the ACCIDENTAL case (the
	 * client never saw the value); explicit null is an act a client can only perform on
	 * purpose. This also keeps PUT consistent with PATCH, where ObjectsController already
	 * merges the payload over the stored object — so an omitted key is backfilled from
	 * storage and an explicit null overwrites it. Both verbs therefore agree: omit to
	 * keep, send null to clear.
	 *
	 * This MUST be called on the RAW incoming payload, BEFORE SaveObject::setDefaultValues()
	 * runs. That method applies a property's `default` when the key is absent OR null,
	 * which would materialize an omitted key and mask the omission from this check — a
	 * write-only property carrying a `default` would then silently overwrite the stored
	 * secret with its default on every update.
	 *
	 * @param Schema $schema Schema whose write-only declarations apply.
	 * @param array $incoming The raw incoming update payload.
	 *
	 * @return array<int, string> Declared dot-paths absent from the payload (possibly empty).
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-an-omitted-writeonly-value-must-be-preserved-on-save
	 */
	public function collectOmittedWriteOnlyPaths(Schema $schema, array $incoming): array {
		// A top-level writeOnly property is just a one-segment path.
		$declared = array_merge($schema->getWriteOnlyProperties(), $schema->getWriteOnlyPaths());

		if (empty($declared) === true) {
			return [];
		}

		$omitted = [];
		foreach ($declared as $path) {
			if ($this->pathExists(path: $path, object: $incoming) === false) {
				$omitted[] = $path;
			}
		}

		return $omitted;
	}//end collectOmittedWriteOnlyPaths()

	/**
	 * Carry stored write-only values forward onto an update payload that omitted them.
	 *
	 * The application half of the save-side preserve rule (openregister#463). Restores
	 * ONLY the declared locations reported absent by collectOmittedWriteOnlyPaths(), read
	 * from the stored object, leaving every other incoming value untouched.
	 *
	 * Restoring the LEAF and never its parent is the whole point of the nested case. The
	 * canonical shape is a Source's `configuration`, an untyped object holding both a
	 * secret (`configuration.authentication.client_secret`) and ordinary operator-editable
	 * settings (`configuration.endpoint`). Carrying the stored `configuration` wholesale
	 * would revert the operator's edits — a data-loss bug traded for a data-loss bug. The
	 * path walk below therefore descends INTO the incoming sub-tree and writes just the
	 * one missing leaf, so a sibling edit in the same object lands normally.
	 *
	 * $stored MUST be the RAW stored object, taken from ObjectEntity::getObject() or a
	 * `_render: false` read. A rendered read is useless here by construction: the render
	 * boundary strips write-only values unconditionally, so a rendered $stored has nothing
	 * to preserve and this method would silently no-op. `_rbac: false` is NOT sufficient —
	 * the writeOnly strip is schema-gated and deliberately ignores `_rbac` (openregister#389,
	 * #460), so an `_rbac: false` read still comes back stripped.
	 *
	 * $stored values are written through VERBATIM. For a property also flagged
	 * `x-openregister-encrypted` the stored value is ciphertext, so this must run AFTER
	 * FieldEncryptionHandler::encryptProperties() — restoring ciphertext before encryption
	 * would double-encrypt it and corrupt the secret beyond recovery.
	 *
	 * @param array $prepared The prepared update payload, mutated copy returned.
	 * @param array $stored The RAW stored object (never a rendered one).
	 * @param array<int, string> $omittedPaths Declared paths absent from the incoming payload.
	 *
	 * @return array The payload with omitted write-only values carried forward.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-an-omitted-writeonly-value-must-be-preserved-on-save
	 */
	public function restoreWriteOnlyValues(array $prepared, array $stored, array $omittedPaths): array {
		if (empty($omittedPaths) === true) {
			return $prepared;
		}

		foreach ($omittedPaths as $path) {
			// Nothing stored at this location: the value was never set. Restoring
			// would invent a key that never existed, so leave the payload alone and
			// let normal PUT semantics apply.
			if ($this->pathExists(path: $path, object: $stored) === false) {
				continue;
			}

			$prepared = $this->restoreWriteOnlyPath(
				path: $path,
				prepared: $prepared,
				value: $this->readPath(path: $path, object: $stored)
			);
		}

		return $prepared;
	}//end restoreWriteOnlyValues()

	/**
	 * Whether a dot-path resolves to an existing key in an array.
	 *
	 * Uses array_key_exists rather than isset at every segment, because a location
	 * present with an explicit null MUST report true — that is precisely the "client
	 * deliberately cleared the secret" signal that isset() would silently misreport as
	 * absent, re-preserving the value the operator just asked to delete.
	 *
	 * @param string $path The dot-path to probe.
	 * @param array $object The array to probe.
	 *
	 * @return bool True when the path resolves to an existing key.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-an-omitted-writeonly-value-must-be-preserved-on-save
	 */
	private function pathExists(string $path, array $object): bool {
		$cursor = $object;

		foreach (explode('.', $path) as $segment) {
			if (is_array($cursor) === false || array_key_exists($segment, $cursor) === false) {
				return false;
			}

			$cursor = $cursor[$segment];
		}

		return true;
	}//end pathExists()

	/**
	 * Read the value at a dot-path. Callers MUST have confirmed pathExists() first.
	 *
	 * @param string $path The dot-path to read.
	 * @param array $object The array to read from.
	 *
	 * @return mixed The value at that location (may legitimately be null).
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-an-omitted-writeonly-value-must-be-preserved-on-save
	 */
	private function readPath(string $path, array $object): mixed {
		$cursor = $object;

		foreach (explode('.', $path) as $segment) {
			$cursor = $cursor[$segment];
		}

		return $cursor;
	}//end readPath()

	/**
	 * Write one stored write-only value back into the payload at its declared path.
	 *
	 * The mirror of stripWriteOnlyPath()'s body walk, inverted: that one descends and
	 * unsets, this one descends and sets. It differs in exactly one deliberate respect —
	 * where the strip BAILS on a missing intermediate segment (never create keys on the
	 * way out), this one CREATES it. A payload that dropped the whole `authentication`
	 * block still has to get its secret back, and the parent has to exist to hold it.
	 *
	 * Bails only when an intermediate segment exists but holds a NON-array: the client
	 * replaced that sub-tree with a scalar, so there is nowhere to put the leaf. Forcing
	 * it would silently discard the value the client actually sent.
	 *
	 * @param string $path The declared dot-path.
	 * @param array $prepared The payload to restore into.
	 * @param mixed $value The stored value to write, verbatim.
	 *
	 * @return array The payload with the value restored.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-an-omitted-writeonly-value-must-be-preserved-on-save
	 */
	private function restoreWriteOnlyPath(string $path, array $prepared, mixed $value): array {
		$segments = explode('.', $path);
		$leaf = array_pop($segments);
		$cursor = &$prepared;

		foreach ($segments as $segment) {
			if (array_key_exists($segment, $cursor) === false || $cursor[$segment] === null) {
				$cursor[$segment] = [];
			}

			if (is_array($cursor[$segment]) === false) {
				// The client sent a scalar where this path expects a sub-tree.
				unset($cursor);
				return $prepared;
			}

			$cursor = &$cursor[$segment];
		}

		$cursor[$leaf] = $value;
		unset($cursor);

		// Deliberately never logs the value — only that a restore happened.
		$this->logger->debug(
			message: '[PropertyRbacHandler] Preserved omitted write-only value on save',
			context: ['file' => __FILE__, 'line' => __LINE__, 'path' => $path]
		);

		return $prepared;
	}//end restoreWriteOnlyPath()

	/**
	 * Validate writable properties on incoming data
	 *
	 * Returns an array of property names that the user is not allowed to update.
	 * The caller should handle these violations (typically throw a validation error).
	 *
	 * @param Schema $schema Schema containing property definitions
	 * @param array $object Existing object data (empty array for creates)
	 * @param array $incomingData Incoming data from client
	 * @param bool $isCreate Whether this is a create operation
	 *
	 * @return array Array of property names user cannot update
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#field-level-security (validates incoming writes against
	 *       property update rules; skips unchanged values so PATCH may resubmit protected fields)
	 */
	public function getUnauthorizedProperties(
		Schema $schema,
		array $object,
		array $incomingData,
		bool $isCreate = false,
	): array {
		// If user is admin, no restrictions.
		if ($this->isAdmin() === true) {
			return [];
		}

		// If schema has no property-level authorization, no restrictions.
		if ($schema->hasPropertyAuthorization() === false) {
			return [];
		}

		$unauthorizedProps = [];

		// Get properties with authorization.
		// Returns associative array: propertyName => authorizationConfig.
		$propertiesWithAuth = $schema->getPropertiesWithAuthorization();

		// Check each incoming property that has authorization rules.
		foreach (array_keys($propertiesWithAuth) as $propertyName) {
			// Only check properties that are being submitted.
			if (array_key_exists($propertyName, $incomingData) === false) {
				continue;
			}

			// Skip authorization check if the value hasn't actually changed.
			// This allows PATCH operations to include unchanged protected fields
			// without triggering authorization errors.
			if ($isCreate === false
				&& array_key_exists($propertyName, $object) === true
				&& $incomingData[$propertyName] === $object[$propertyName]
			) {
				continue;
			}

			// Check if user can update this property.
			if ($this->canUpdateProperty(
				schema: $schema,
				property: $propertyName,
				object: $object,
				isCreate: $isCreate
			) === false
			) {
				$unauthorizedProps[] = $propertyName;
			}
		}//end foreach

		return $unauthorizedProps;
	}//end getUnauthorizedProperties()

	/**
	 * Check if user has access to a property for a specific action
	 *
	 * @param Schema $schema Schema containing property definition
	 * @param string $property Property name
	 * @param array $object Object data for conditional matching
	 * @param string $action Action to check ('read' or 'update')
	 * @param bool $isCreate Whether this is a create operation
	 *
	 * @return bool True if user has access
	 */
	private function checkPropertyAccess(
		Schema $schema,
		string $property,
		array $object,
		string $action,
		bool $isCreate = false,
	): bool {
		// Get property authorization.
		$authorization = $schema->getPropertyAuthorization($property);

		// If no authorization is defined for this property, it follows object-level rules.
		if ($authorization === null || empty($authorization) === true) {
			return true;
		}

		// Get rules for this action.
		$rules = $authorization[$action] ?? [];

		// If action is not configured, property is accessible.
		if (empty($rules) === true) {
			return true;
		}

		// Get current user info.
		$user = $this->userSession->getUser();
		$userId = $user?->getUID();

		// Get user groups.
		$userGroups = [];
		if ($user !== null) {
			$userGroups = $this->groupManager->getUserGroupIds($user);
		}

		// Admin users bypass all checks.
		if (in_array('admin', $userGroups, true) === true) {
			return true;
		}

		// Process each rule.
		foreach ($rules as $rule) {
			if ($this->checkRule(
				rule: $rule,
				userGroups: $userGroups,
				userId: $userId,
				object: $object,
				isCreate: $isCreate
			) === true
			) {
				return true;
			}
		}

		return false;
	}//end checkPropertyAccess()

	/**
	 * Check if a single rule grants access
	 *
	 * @param mixed $rule Authorization rule
	 * @param array $userGroups User's group IDs
	 * @param string|null $userId Current user ID
	 * @param array $object Object data for conditional matching
	 * @param bool $isCreate Whether this is a create operation
	 *
	 * @return bool True if rule grants access
	 */
	private function checkRule(
		mixed $rule,
		array $userGroups,
		?string $userId,
		array $object,
		bool $isCreate,
	): bool {
		// Simple rule: just a group name string.
		if (is_string($rule) === true) {
			return $this->userQualifiesForGroup(group: $rule, userGroups: $userGroups, userId: $userId);
		}

		// Conditional rule: object with 'group' and optional 'match'.
		if (is_array($rule) === true && isset($rule['group']) === true) {
			return $this->checkConditionalRule(
				rule: $rule,
				userGroups: $userGroups,
				userId: $userId,
				object: $object,
				isCreate: $isCreate
			);
		}

		// Invalid rule format.
		$this->logger->warning(
			message: '[PropertyRbacHandler] Invalid rule format',
			context: ['file' => __FILE__, 'line' => __LINE__, 'rule' => $rule]
		);
		return false;
	}//end checkRule()

	/**
	 * Check a conditional rule with match criteria
	 *
	 * @param array $rule Rule with 'group' and optional 'match'
	 * @param array $userGroups User's group IDs
	 * @param string|null $userId Current user ID
	 * @param array $object Object data for conditional matching
	 * @param bool $isCreate Whether this is a create operation
	 *
	 * @return bool True if rule grants access
	 */
	private function checkConditionalRule(
		array $rule,
		array $userGroups,
		?string $userId,
		array $object,
		bool $isCreate,
	): bool {
		$group = $rule['group'];
		$match = $rule['match'] ?? null;

		// If user doesn't qualify for the group, this rule doesn't apply.
		if ($this->userQualifiesForGroup(group: $group, userGroups: $userGroups, userId: $userId) === false) {
			return false;
		}

		// If no match conditions, user has access via this rule.
		if ($match === null || empty($match) === true) {
			return true;
		}

		// For creates, skip organisation matching since there's no existing object.
		// Other match conditions still apply.
		if ($isCreate === true) {
			$match = $this->conditionMatcher->filterOrganisationMatchForCreate(match: $match);
			if (empty($match) === true) {
				return true;
			}
		}

		// Check if object matches all conditions.
		return $this->conditionMatcher->objectMatchesConditions(object: $object, match: $match);
	}//end checkConditionalRule()

	/**
	 * Check if a user qualifies for a specific group
	 *
	 * @param string $group Group name from the rule
	 * @param array $userGroups User's group IDs
	 * @param string|null $userId Current user ID
	 *
	 * @return bool True if user qualifies for the group
	 */
	private function userQualifiesForGroup(string $group, array $userGroups, ?string $userId): bool {
		if ($group === 'public') {
			return true;
		}

		if ($group === 'authenticated' && $userId !== null) {
			return true;
		}

		return in_array($group, $userGroups, true);
	}//end userQualifiesForGroup()

	/**
	 * Check if current user is admin
	 *
	 * @return bool True if user is in admin group
	 *
	 * @spec exclude Trivial admin-group membership check helper; no business logic.
	 */
	public function isAdmin(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		$userGroups = $this->groupManager->getUserGroupIds($user);
		return in_array('admin', $userGroups, true);
	}//end isAdmin()
}//end class
