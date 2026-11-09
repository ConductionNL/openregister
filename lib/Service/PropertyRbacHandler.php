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
use OCA\OpenRegister\Service\Rbac\ExplicitReveal;
use OCA\OpenRegister\Service\Rbac\RevealCollector;
use OCA\OpenRegister\Service\Rbac\WriteOnlyPathWalker;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRuleResolver;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRules;
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
class PropertyRbacHandler implements PropertyRbacHandlerInterface {

	/**
	 * Walks one declared write-only dot-path through a body.
	 *
	 * @var WriteOnlyPathWalker
	 */
	private readonly WriteOnlyPathWalker $paths;

	/**
	 * User session for current user context.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Group manager for user group operations.
	 *
	 * @var IGroupManager
	 */
	private readonly IGroupManager $groupManager;

	/**
	 * Condition matcher for match expressions.
	 *
	 * @var ConditionMatcher
	 */
	private readonly ConditionMatcher $conditionMatcher;

	/**
	 * Resolver for the lifecycle state's field rules.
	 *
	 * @var StateFieldRuleResolver
	 */
	private readonly StateFieldRuleResolver $stateFieldRules;

	/**
	 * Constructor for PropertyRbacHandler
	 *
	 * @param PropertyRbacCaller $caller The user session and the group manager.
	 * @param PropertyRbacRules $rules The condition matcher and the lifecycle state's field-rule resolver.
	 * @param LoggerInterface $logger Logger for debugging
	 * @param RevealCollector|null $reveals Records which governed properties were revealed to the caller.
	 *                                      Nullable and last; absent, nothing is recorded and the
	 *                                      verdict is unchanged
	 */
	public function __construct(
		PropertyRbacCaller $caller,
		PropertyRbacRules $rules,
		private readonly LoggerInterface $logger,
		private readonly ?RevealCollector $reveals = null,
	) {
		$this->userSession = $caller->userSession;
		$this->groupManager = $caller->groupManager;
		$this->conditionMatcher = $rules->conditionMatcher;
		$this->stateFieldRules = $rules->stateFieldRules;
		$this->paths = new WriteOnlyPathWalker(logger: $logger);
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
			rules: $this->actionRules(schema: $schema, property: $property)['read'] ?? [],
			request: new PropertyAccessRequest(object: $object)
		);
	}//end canReadProperty()

	/**
	 * Whether the current user could read a property on some row.
	 *
	 * Asks the groups of the read rule and leaves out its `match` conditions, because
	 * no row is at hand: a rule that admits the caller on their own rows answers yes.
	 * What a row actually shows stays with canReadProperty().
	 *
	 * @param Schema $schema Schema containing property definition
	 * @param string $property Property name to check
	 *
	 * @return bool True unless no row could ever show this property to the user
	 *
	 * @spec openspec/changes/schema-shape-exposure/specs/rbac-scopes/spec.md
	 */
	public function couldReadProperty(Schema $schema, string $property): bool {
		$rules = $this->actionRules(schema: $schema, property: $property)['read'] ?? [];
		if (empty($rules) === true) {
			return true;
		}

		$request = $this->forCurrentUser(request: new PropertyAccessRequest(object: []));
		if ($request->isAdmin() === true) {
			return true;
		}

		$groups = array_filter(array_map($this->ruleGroup(...), $rules), is_string(...));

		return array_filter($groups, $request->qualifiesFor(...)) !== [];
	}//end couldReadProperty()

	/**
	 * The group a read rule names.
	 *
	 * @param mixed $rule Authorization rule
	 *
	 * @return mixed The group, or null for a rule without one
	 */
	private function ruleGroup(mixed $rule): mixed {
		if (is_array($rule) === true) {
			return ($rule['group'] ?? null);
		}

		return $rule;
	}//end ruleGroup()

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
			rules: $this->actionRules(schema: $schema, property: $property)['update'] ?? [],
			request: new PropertyAccessRequest(object: $object, isCreate: $isCreate)
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

		// A field the object's lifecycle state hides is stripped here, on the
		// same boundary and before the admin short-circuit. It runs before the
		// short-circuit because a state rule is not a privilege grant: the case
		// type says the field is not part of this state's record, and an
		// administrator reading it back would see a field no form can write.
		// An author who wants a role spared says so with `groups`.
		$object = $this->stripStateHiddenProperties(schema: $schema, object: $object);

		// An admin skips the group read rules (writeOnly and state rules were
		// applied above). Explicit-reveal masking is not done here: the
		// ExplicitRevealMasker applies it on the render routes, admins included.
		// A schema with no property-level authorization is returned as-is.
		if ($this->isAdmin() === true || $schema->hasPropertyAuthorization() === false) {
			return $object;
		}

		// Returns associative array: propertyName => authorizationConfig.
		foreach ($schema->getPropertiesWithAuthorization() as $propertyName => $authorization) {
			// Only filter if the property exists in the object.
			if (array_key_exists($propertyName, $object) === true) {
				$object = $this->keepIfReadable(schema: $schema, property: $propertyName, authorization: $authorization, object: $object);
			}
		}

		return $object;
	}//end filterReadableProperties()

	/**
	 * Drop one property the user cannot read; record the reveal of one they can.
	 *
	 * @param Schema               $schema        The schema being read.
	 * @param string               $property      The property, present in the object.
	 * @param mixed                $authorization The property's authorization block.
	 * @param array<string, mixed> $object        The object being read.
	 *
	 * @return array<string, mixed> The object, without the property when it is unreadable.
	 */
	private function keepIfReadable(Schema $schema, string $property, mixed $authorization, array $object): array {
		if ($this->canReadProperty(schema: $schema, property: $property, object: $object) === false) {
			unset($object[$property]);
			$this->logger->debug(
				message: '[PropertyRbacHandler] Filtered unreadable property',
				context: ['file' => __FILE__, 'line' => __LINE__, 'property' => $property]
			);
			return $object;
		}

		// 🔴 THE REVEAL IS RECORDED HERE, WHERE THE VALUE SURVIVES THE
		// FILTER, and not where the check runs (ledger row 5.6, D-1). Those
		// are the same line today and will not always be, and only one of
		// them is the fact being recorded: a denial is already logged, and
		// what a data protection officer asks is who SAW the BSN.
		//
		// A stripped property reaches the `continue` above and writes
		// nothing, which is the spec's third scenario and the one an
		// implementation that recorded before the check would get backwards.
		$this->recordReveal(schema: $schema, property: $property, authorization: $authorization, object: $object);

		return $object;
	}//end keepIfReadable()

	/**
	 * Record a reveal, when the property asked for one.
	 *
	 * Never throws and never blocks the read. An audit that can refuse to show
	 * somebody a field they are entitled to see is an availability bug wearing
	 * a compliance badge, and this collector holds rows in memory: the failure
	 * it could plausibly have is running out of them, which must not cost the
	 * reader their page.
	 *
	 * @param Schema $schema The schema being read.
	 * @param string $property The property that survived the filter.
	 * @param mixed $authorization The property's authorization block.
	 * @param array<string, mixed> $object The object being read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
	 */
	private function recordReveal(Schema $schema, string $property, mixed $authorization, array $object): void {
		if ($this->revealIsAudited(authorization: $authorization) === false) {
			return;
		}

		try {
			$userId = (string)$this->userSession->getUser()?->getUID();
			$objectUuid = $this->objectUuid(object: $object);
			$this->reveals?->record(userId: $userId, objectUuid: $objectUuid, property: $property, schemaId: $schema->getId());
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[PropertyRbacHandler] Could not record a reveal; the read is unaffected',
				context: ['file' => __FILE__, 'line' => __LINE__, 'property' => $property, 'error' => $e->getMessage()]
			);
		}
	}//end recordReveal()

	/**
	 * Whether a surviving property's reveal is to be recorded.
	 *
	 * @param mixed $authorization The property's authorization block.
	 *
	 * @return bool True when a collector is wired and the block asks for an audit.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ExplicitReveal's rules are pure functions.
	 */
	private function revealIsAudited(mixed $authorization): bool {
		// An explicit-reveal property is masked unless granted, and the
		// ExplicitRevealMasker records the granted reveal itself.
		if ($this->reveals === null || is_array($authorization) === false || ExplicitReveal::isExplicitRules(rules: $authorization) === true) {
			return false;
		}

		return $this->reveals->isAudited(propertyRules: $authorization);
	}//end revealIsAudited()

	/**
	 * The object's id as a reveal names it.
	 *
	 * @param array<string, mixed> $object The object being read.
	 *
	 * @return string The id, uuid or `@self.id`, else the empty string.
	 */
	private function objectUuid(array $object): string {
		return (string)($object['id'] ?? ($object['uuid'] ?? ($object['@self']['id'] ?? '')));
	}//end objectUuid()

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
			$object = $this->stripWriteOnlyTopLevel(schema: $schema, object: $object);
		}

		foreach ($paths as $path) {
			$object = $this->paths->strip(path: $path, object: $object);
		}

		return $object;
	}//end stripWriteOnlyProperties()

	/**
	 * Remove the top-level `writeOnly: true` properties from outgoing object data.
	 *
	 * @param Schema $schema Schema containing property definitions
	 * @param array  $object Object data to filter
	 *
	 * @return array Object data without its write-only properties
	 */
	private function stripWriteOnlyTopLevel(Schema $schema, array $object): array {
		foreach ($schema->getWriteOnlyProperties() as $propertyName) {
			if (array_key_exists($propertyName, $object) === true) {
				unset($object[$propertyName]);
				$this->logger->debug(
					message: '[PropertyRbacHandler] Stripped write-only property',
					context: ['file' => __FILE__, 'line' => __LINE__, 'property' => $propertyName]
				);
			}
		}

		return $object;
	}//end stripWriteOnlyTopLevel()

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
			if ($this->paths->exists(path: $path, object: $incoming) === false) {
				$omitted[] = $path;
			}
		}

		return $omitted;
	}//end collectOmittedWriteOnlyPaths()

	/**
	 * Properties an update payload omits that the writer may not read (openregister#4170) or may not update.
	 *
	 * The read path strips a property whose `authorization.read` the caller
	 * fails, so a caller doing the natural GET, edit, PUT round trip sends a
	 * body without it, and the PUT null-fill would erase a value the caller was
	 * never shown. These are carried forward exactly as omitted write-only
	 * values are, through {@see restoreWriteOnlyValues()}.
	 *
	 * Omitting one the caller may not update would be the write the rule refuses.
	 * Decided on the STORED record's match envelope; call it on the RAW payload.
	 *
	 * @param Schema $schema   Schema whose property authorization applies.
	 * @param array  $incoming The raw incoming update payload.
	 * @param array  $stored   ObjectEntity::getMatchEnvelope() of the stored record (values come from the raw one).
	 *
	 * @return array<int, string> Top-level property names to carry forward (possibly empty).
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-an-update-that-omits-a-property-the-caller-may-not-update-keeps-it
	 */
	public function collectOmittedProtectedProperties(Schema $schema, array $incoming, array $stored): array {
		if ($schema->hasPropertyAuthorization() === false || $this->isAdmin() === true) {
			return [];
		}

		$omitted = [];
		foreach (array_keys($schema->getPropertiesWithAuthorization()) as $propertyName) {
			$propertyName = (string) $propertyName;
			if ($this->carriesForward(schema: $schema, property: $propertyName, incoming: $incoming, stored: $stored) === true) {
				$omitted[] = $propertyName;
			}
		}

		return $omitted;
	}//end collectOmittedProtectedProperties()

	/**
	 * Whether a stored property the payload omits is one the writer may not read, or may not update.
	 *
	 * @param Schema $schema   Schema whose property authorization applies.
	 * @param string $property The governed property.
	 * @param array  $incoming The raw incoming update payload.
	 * @param array  $stored   The stored record's match envelope.
	 *
	 * @return bool True when its omission must be carried forward.
	 */
	private function carriesForward(Schema $schema, string $property, array $incoming, array $stored): bool {
		if (array_key_exists($property, $incoming) === true || array_key_exists($property, $stored) === false) {
			return false;
		}

		return $this->canReadProperty(schema: $schema, property: $property, object: $stored) === false
			|| $this->canUpdateProperty(schema: $schema, property: $property, object: $stored) === false;
	}//end carriesForward()

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
		foreach ($omittedPaths as $path) {
			// Nothing stored at this location: the value was never set. Restoring
			// would invent a key that never existed, so leave the payload alone and
			// let normal PUT semantics apply.
			if ($this->paths->exists(path: $path, object: $stored) === true) {
				$value = $this->paths->read(path: $path, object: $stored);
				$prepared = $this->paths->restore(path: $path, prepared: $prepared, value: $value);
			}
		}

		return $prepared;
	}//end restoreWriteOnlyValues()

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
	public function getUnauthorizedProperties(Schema $schema, array $object, array $incomingData, bool $isCreate = false): array {
		// A field the object's state HIDES is refused here and survives both
		// short-circuits below, so GraphQL and every other caller of this
		// method inherit the refusal without a call-site change. It belongs on
		// this path because a hidden field genuinely is one the caller may not
		// touch, and "not authorized to modify" is the true sentence for it.
		//
		// `readOnly` deliberately does NOT come through here. It is not an
		// authorization question, and this method's caller turns its answer
		// into "you are not authorized to modify", which would be the wrong
		// sentence for a field the user can see and normally edit.
		// StateFieldRuleListener refuses that one with 422 naming the field and
		// the state, which is what the requirement's scenario reads.
		$unauthorizedProps = $this->stateHiddenWrites(schema: $schema, object: $object, incomingData: $incomingData);

		// An admin, or a schema without property-level authorization, has no further restrictions.
		if ($this->isAdmin() === true || $schema->hasPropertyAuthorization() === false) {
			return $unauthorizedProps;
		}

		$request = new PropertyAccessRequest(object: $object, isCreate: $isCreate);
		$refused = $this->refusedWrites(schema: $schema, request: $request, incomingData: $incomingData);

		return array_values(array_unique([...$unauthorizedProps, ...$refused]));
	}//end getUnauthorizedProperties()

	/**
	 * The submitted governed properties the user may not write.
	 *
	 * @param Schema                $schema       Schema containing property definitions
	 * @param PropertyAccessRequest $request      The existing object and create flag
	 * @param array                 $incomingData Incoming data from client
	 *
	 * @return array<int, int|string> The refused property names, in schema order
	 */
	private function refusedWrites(Schema $schema, PropertyAccessRequest $request, array $incomingData): array {
		$refused = [];
		// Check each incoming property that has authorization rules; only submitted ones.
		foreach (array_keys($schema->getPropertiesWithAuthorization()) as $name) {
			if (array_key_exists($name, $incomingData) === true
				&& $this->refusesChange(schema: $schema, property: $name, request: $request, incoming: $incomingData[$name]) === true
			) {
				$refused[] = $name;
			}
		}

		return $refused;
	}//end refusedWrites()

	/**
	 * Whether the user may not write a submitted value to one property.
	 *
	 * @param Schema                $schema   Schema containing property definitions
	 * @param int|string            $property The submitted property
	 * @param PropertyAccessRequest $request  The existing object and create flag
	 * @param mixed                 $incoming The submitted value
	 *
	 * @return bool True when the write is refused
	 */
	private function refusesChange(Schema $schema, int|string $property, PropertyAccessRequest $request, mixed $incoming): bool {
		// Skip authorization check if the value hasn't actually changed.
		// This allows PATCH operations to include unchanged protected fields
		// without triggering authorization errors.
		if ($request->isCreate === false
			&& array_key_exists($property, $request->object) === true
			&& $incoming === $request->object[$property]
		) {
			return false;
		}

		return $this->canUpdateProperty(
			schema: $schema,
			property: $property,
			object: $request->object,
			isCreate: $request->isCreate
		) === false;
	}//end refusesChange()

	/**
	 * The lifecycle state's field rules for one object, already decided.
	 *
	 * The render path publishes these as `@self.fieldRules`. It asks through
	 * this handler rather than holding the resolver of its own, so the rules a
	 * form is shown and the rules the strip applies can never be two different
	 * readings of the same declaration.
	 *
	 * @param Schema $schema Schema containing the lifecycle annotation
	 * @param array $object Object data to resolve against
	 *
	 * @return StateFieldRules The hidden, read-only and required lists
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function stateFieldRulesFor(Schema $schema, array $object): StateFieldRules {
		return $this->stateFieldRules->resolve(schema: $schema, data: $object);
	}//end stateFieldRulesFor()

	/**
	 * Remove the properties the object's lifecycle state hides.
	 *
	 * The state is read off the object's own data, so one call answers for
	 * whichever state it is in. A schema with no `states` block resolves to
	 * nothing and the object is returned untouched, which is why this costs
	 * a map lookup on every render rather than a rule walk.
	 *
	 * @param Schema $schema Schema containing the lifecycle annotation
	 * @param array $object Object data to filter
	 *
	 * @return array Object data with state-hidden properties removed
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	private function stripStateHiddenProperties(Schema $schema, array $object): array {
		$rules = $this->stateFieldRules->resolve(schema: $schema, data: $object);
		foreach ($rules->getHidden() as $property) {
			if (array_key_exists($property, $object) === false) {
				continue;
			}

			unset($object[$property]);
			$this->logger->debug(
				message: '[PropertyRbacHandler] Filtered property hidden by lifecycle state',
				context: ['file' => __FILE__, 'line' => __LINE__, 'property' => $property, 'state' => $rules->getState()]
			);
		}

		return $object;
	}//end stripStateHiddenProperties()

	/**
	 * The incoming properties the object's current lifecycle state hides.
	 *
	 * Only `hidden` answers here. `readOnly` and `required` are refusals ABOUT
	 * the write rather than about a property the caller may not touch, and both
	 * name the state in their message, so both live in StateFieldRuleListener.
	 *
	 * @param Schema $schema Schema containing the lifecycle annotation
	 * @param array $object Existing object data (empty array for creates)
	 * @param array $incomingData Incoming data from client
	 *
	 * @return array Array of property names the state hides
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	private function stateHiddenWrites(Schema $schema, array $object, array $incomingData): array {
		$source = $object;
		if ($source === []) {
			$source = $incomingData;
		}

		$hidden = $this->stateFieldRules->resolve(schema: $schema, data: $source)->getHidden();

		// Resubmitting the stored value is not a change, exactly as the
		// property-authorization loop below allows, so a PATCH may carry
		// the whole object back without tripping over its frozen fields.
		$changed = static fn ($property): bool => array_key_exists($property, $incomingData) === true
			&& ($object[$property] ?? null) !== $incomingData[$property];

		return array_values(array_filter($hidden, $changed));
	}//end stateHiddenWrites()

	/**
	 * A property's authorization block, by action; empty when none is defined,
	 * in which case the property follows the object-level rules.
	 *
	 * @param Schema $schema   Schema containing property definition
	 * @param string $property Property name
	 *
	 * @return array<string, mixed> Rules per action.
	 */
	private function actionRules(Schema $schema, string $property): array {
		return $schema->getPropertyAuthorization($property) ?? [];
	}//end actionRules()

	/**
	 * Check if the current user has access under a property's rules for one action
	 *
	 * @param mixed                 $rules   The rules for the action; empty means no opinion.
	 * @param PropertyAccessRequest $request The object and create flag.
	 *
	 * @return bool True if user has access
	 */
	private function checkPropertyAccess(mixed $rules, PropertyAccessRequest $request): bool {

		// 🔴 "ACCESSIBLE" WAS THE WRONG WORD, AND IT READ AS A FAIL-OPEN.
		//
		// This returns true meaning "this layer has no opinion", NOT "anyone may
		// do it". A property block is a NARROWING on top of the object cascade,
		// so an action it does not name falls through to the object's own rules,
		// which still have to pass. The schema cascade is the opposite kind of
		// declaration: it is the last word, so `MagicRbacHandler::hasPermission()`
		// returns FALSE on an empty list, denied, because there is nothing left
		// to fall through to.
		//
		// 🔑 SO THE SAME LITERAL MEANS DIFFERENT THINGS IN THE TWO LAYERS, AND
		// THAT IS CORRECT RATHER THAN A BUG TO HARMONISE. Making this one
		// fail-closed would not tighten a leak; it would make every action a
		// property block does not name UNWRITABLE, and measured across the
		// installed fleet on 2026-09-18 there are 8 property-level blocks and
		// ALL 8 ARE PARTIAL. Not one names all four actions. A naive
		// harmonisation would break every one of them, in decidiq and stackiq.
		//
		// 🔴 WHAT IS GENUINELY SHARP HERE, and is NOT fixed by this comment: a
		// property that restricts `read` and says nothing about `update` can be
		// WRITTEN by anyone who may write the object, including somebody who may
		// not read it. `stackiq organization.contactpersonen` is exactly that
		// shape today. That is a blind write, not a disclosure, so it is left as
		// a declaration each schema author must make deliberately rather than
		// something this layer guesses at.
		if (empty($rules) === true) {
			return true;
		}

		$request = $this->forCurrentUser(request: $request);

		// Admin users bypass all checks.
		if ($request->isAdmin() === true) {
			return true;
		}

		foreach ($rules as $rule) {
			if ($this->checkRule(rule: $rule, request: $request) === true) {
				return true;
			}
		}

		return false;
	}//end checkPropertyAccess()

	/**
	 * The request for the user of this session.
	 *
	 * @param PropertyAccessRequest $request The object and create flag.
	 *
	 * @return PropertyAccessRequest The request with the user and their groups
	 */
	private function forCurrentUser(PropertyAccessRequest $request): PropertyAccessRequest {
		$user = $this->userSession->getUser();
		$userGroups = [];
		if ($user !== null) {
			$userGroups = $this->groupManager->getUserGroupIds($user);
		}

		return $request->withUser(userId: $user?->getUID(), userGroups: $userGroups);
	}//end forCurrentUser()

	/**
	 * Check if a single rule grants access
	 *
	 * @param mixed                 $rule    Authorization rule
	 * @param PropertyAccessRequest $request The user, object and create flag
	 *
	 * @return bool True if rule grants access
	 */
	private function checkRule(mixed $rule, PropertyAccessRequest $request): bool {
		// Simple rule: just a group name string.
		if (is_string($rule) === true) {
			return $request->qualifiesFor(group: $rule);
		}

		// Conditional rule: object with 'group' and optional 'match'.
		if (is_array($rule) === true && isset($rule['group']) === true) {
			return $this->checkConditionalRule(rule: $rule, request: $request);
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
	 * @param array                 $rule    Rule with 'group' and optional 'match'
	 * @param PropertyAccessRequest $request The user, object and create flag
	 *
	 * @return bool True if rule grants access
	 */
	private function checkConditionalRule(array $rule, PropertyAccessRequest $request): bool {
		$group = $rule['group'];
		$match = $rule['match'] ?? null;

		// If user doesn't qualify for the group, this rule doesn't apply.
		if ($request->qualifiesFor(group: $group) === false) {
			return false;
		}

		// If no match conditions, user has access via this rule.
		if ($match === null || empty($match) === true) {
			return true;
		}

		return $this->objectMatches(match: $match, request: $request);
	}//end checkConditionalRule()

	/**
	 * Whether the object meets a rule's match conditions.
	 *
	 * @param mixed                 $match   The rule's non-empty match conditions
	 * @param PropertyAccessRequest $request The user, object and create flag
	 *
	 * @return bool True if the conditions hold
	 */
	private function objectMatches(mixed $match, PropertyAccessRequest $request): bool {
		// For creates, skip organisation matching since there's no existing object.
		// Other match conditions still apply.
		if ($request->isCreate === true) {
			$match = $this->conditionMatcher->filterOrganisationMatchForCreate(match: $match);
			if (empty($match) === true) {
				return true;
			}
		}

		// Check if object matches all conditions.
		return $this->conditionMatcher->objectMatchesConditions(object: $request->object, match: $match);
	}//end objectMatches()

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
