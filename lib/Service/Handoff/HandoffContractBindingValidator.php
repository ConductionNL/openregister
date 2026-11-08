<?php

/**
 * OpenRegister Handoff Contract Binding Validator
 *
 * Save-time validator for the `handoffContract` binding block on PROVIDER
 * schemas (ADR-051 semantic-object-handoff). A schema whose implemented
 * semantic types (its `implements[]` / `jsonld.type` / `x-schema-org`
 * markers, ADR-048) include a contract-carrying kind MAY declare a
 * `handoffContract` block binding each kind-contract field name to one of its
 * own properties. When the block is present, every MANDATORY contract field
 * must bind to an existing own property — otherwise the schema is rejected
 * with `handoff-contract-incomplete` listing the missing fields.
 *
 * A schema that implements a kind WITHOUT a binding block is deliberately NOT
 * an error: it implements the kind for ADR-048 reference-resolution purposes
 * only and is simply not a handoff provider (HandoffService filters on
 * "has a complete binding").
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Handoff
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Handoff;

/**
 * Validate the `handoffContract` binding block on an implementing schema.
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 *   (Requirement: Kind contract binding on the implementing schema)
 */
class HandoffContractBindingValidator implements HandoffContractBindingValidatorInterface {

	/**
	 * The error code every binding problem is reported under.
	 *
	 * @var string
	 */
	private const ERROR_CODE = 'handoff-contract-incomplete';

	/**
	 * The `x-schema-org` prefix that abbreviates `https://schema.org/`.
	 *
	 * @var string
	 */
	private const SCHEMA_ORG_PREFIX = 'schema:';

	/**
	 * Validate the binding block.
	 *
	 * Expects the shape SchemaMapper hands the annotation validators, with the
	 * configuration-level semantic markers alongside `properties`:
	 * `['properties' => [...], 'implements' => [...]?, 'jsonld' => [...]?,
	 *   'x-schema-org' => ...?, 'handoffContract' => [...]?]`.
	 *
	 * The binding block maps kind URIs to `{contractField: ownProperty}` maps:
	 * `"handoffContract": {"https://openregister.app/ns#Case": {"title": "onderwerp", ...}}`.
	 *
	 * @param array<string, mixed> $schema The schema shape to validate.
	 *
	 * @return array<int, array{code: string, message: string}> Aggregated errors (empty = valid).
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: Implementer omits a mandatory contract field)
	 */
	public function validate(array $schema): array {
		$binding = ($schema['handoffContract'] ?? null);
		if ($binding === null) {
			// No binding block: not a handoff provider — never an error.
			return [];
		}

		if (is_array($binding) === false) {
			return [$this->error(message: 'handoffContract must be an object mapping kind URIs to {contractField: ownProperty} bindings.')];
		}

		return $this->bindingErrors(binding: $binding, schema: $schema);
	}//end validate()

	/**
	 * The errors of every kind in a well-formed binding block.
	 *
	 * @param array<mixed, mixed>  $binding The `handoffContract` block.
	 * @param array<string, mixed> $schema  The schema shape it belongs to.
	 *
	 * @return array<int, array{code: string, message: string}> Aggregated errors.
	 */
	private function bindingErrors(array $binding, array $schema): array {
		$properties = $this->ownProperties(schema: $schema);
		$implemented = $this->implementedTypes(schema: $schema);
		$errors = [];
		foreach ($binding as $kindUri => $fieldMap) {
			$kind = new HandoffKindBinding(kindUri: (string) $kindUri, fieldMap: $fieldMap, properties: $properties);
			array_push($errors, ...$this->kindErrors(binding: $kind, implemented: $implemented));
		}

		return $errors;
	}//end bindingErrors()

	/**
	 * Determine whether a binding covers every mandatory field of a kind and
	 * binds only existing own properties.
	 *
	 * Static convenience used by the engine to filter resolver results to
	 * schemas with a COMPLETE binding (design: "no binding block ⇒ not a
	 * handoff provider").
	 *
	 * @param string $kindUri The kind URI to check.
	 * @param array<string, mixed> $binding The full `handoffContract` block.
	 * @param array<string, mixed> $properties The schema's own properties map.
	 *
	 * @return bool True when the kind is completely + validly bound.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: Implementer binds all mandatory contract fields)
	 */
	public static function isCompleteBinding(string $kindUri, array $binding, array $properties): bool {
		$kind = new HandoffKindBinding(kindUri: $kindUri, fieldMap: ($binding[$kindUri] ?? null), properties: $properties);
		if ($kind->hasFields() === false) {
			return false;
		}

		if ($kind->bindsAllOf(contractFields: HandoffKindContracts::mandatoryFields($kindUri)) === false) {
			return false;
		}

		return $kind->bindsOnlyWithin(allowed: HandoffKindContracts::allFields($kindUri));
	}//end isCompleteBinding()

	/**
	 * The errors for one kind of the binding block.
	 *
	 * A kind the schema does not implement, or that carries no contract, is
	 * reported once and its field map is not inspected.
	 *
	 * @param HandoffKindBinding $binding     The kind's entry in the block.
	 * @param array<int, string> $implemented The schema's own implemented type URIs.
	 *
	 * @return array<int, array{code: string, message: string}> The kind's errors.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: Implementer omits a mandatory contract field)
	 */
	private function kindErrors(HandoffKindBinding $binding, array $implemented): array {
		$kindUri = $binding->kindUri;
		if (in_array($kindUri, $implemented, true) === false) {
			$format = 'handoffContract binds kind "%s" which the schema does not implement (declare it in `implements`).';
			return [$this->error(message: sprintf($format, $kindUri))];
		}

		if (HandoffKindContracts::isContractKind($kindUri) === false) {
			$known = implode(', ', HandoffKindContracts::kinds());
			return [$this->error(message: sprintf('handoffContract binds unknown kind "%s" (known contract kinds: %s).', $kindUri, $known))];
		}

		return $this->fieldMapErrors(binding: $binding);
	}//end kindErrors()

	/**
	 * Validate a single kind's field map inside the binding block.
	 *
	 * @param HandoffKindBinding $binding The kind's entry in the block.
	 *
	 * @return array<int, array{code: string, message: string}> The field map's errors.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: Implementer omits a mandatory contract field)
	 */
	private function fieldMapErrors(HandoffKindBinding $binding): array {
		if ($binding->hasFields() === false) {
			$format = 'handoffContract["%s"] must be a non-empty {contractField: ownProperty} map; missing mandatory fields: %s.';
			$mandatory = implode(', ', HandoffKindContracts::mandatoryFields($binding->kindUri));
			return [$this->error(message: sprintf($format, $binding->kindUri, $mandatory))];
		}

		$errors = array_map(
			fn (string $contractField): ?array => $this->entryError(binding: $binding, contractField: $contractField),
			$binding->contractFields()
		);
		$errors = array_values(array_filter($errors, static fn (?array $error): bool => $error !== null));

		return array_merge($errors, $this->omissionErrors(binding: $binding));
	}//end fieldMapErrors()

	/**
	 * The error for one `contractField => ownProperty` entry, if any.
	 *
	 * @param HandoffKindBinding $binding       The kind's entry in the block.
	 * @param string             $contractField The contract field the entry declares.
	 *
	 * @return array{code: string, message: string}|null The error, or null when the entry is valid.
	 */
	private function entryError(HandoffKindBinding $binding, string $contractField): ?array {
		$allFields = HandoffKindContracts::allFields($binding->kindUri);
		if (in_array($contractField, $allFields, true) === false) {
			$format = 'handoffContract["%s"] binds "%s" which is not a contract field (fields: %s).';
			return $this->error(message: sprintf($format, $binding->kindUri, $contractField, implode(', ', $allFields)));
		}

		$ownProperty = $binding->target(contractField: $contractField);
		if ($binding->namesOwnProperty(ownProperty: $ownProperty) === true) {
			return null;
		}

		$format = 'handoffContract["%s"]["%s"] must name an existing own property; "%s" is not declared in `properties`.';
		return $this->error(message: sprintf($format, $binding->kindUri, $contractField, $this->describe(value: $ownProperty)));
	}//end entryError()

	/**
	 * The error for mandatory contract fields the map omits, if any.
	 *
	 * @param HandoffKindBinding $binding The kind's entry in the block.
	 *
	 * @return array<int, array{code: string, message: string}> One error, or none.
	 */
	private function omissionErrors(HandoffKindBinding $binding): array {
		$missing = $binding->missingOf(mandatory: HandoffKindContracts::mandatoryFields($binding->kindUri));
		if ($missing === []) {
			return [];
		}

		$format = 'handoffContract["%s"] omits mandatory contract field(s): %s.';
		return [$this->error(message: sprintf($format, $binding->kindUri, implode(', ', $missing)))];
	}//end omissionErrors()

	/**
	 * How an offending binding target is named in an error message.
	 *
	 * @param mixed $value The declared target.
	 *
	 * @return string The string itself, or its type when it is not a string.
	 */
	private function describe(mixed $value): string {
		if (is_string($value) === true) {
			return $value;
		}

		return gettype($value);
	}//end describe()

	/**
	 * Build one error entry.
	 *
	 * @param string $message The human-readable message.
	 *
	 * @return array{code: string, message: string} The error.
	 */
	private function error(string $message): array {
		return ['code' => self::ERROR_CODE, 'message' => $message];
	}//end error()

	/**
	 * The schema's own properties map, or an empty map when it is malformed.
	 *
	 * @param array<string, mixed> $schema The schema shape.
	 *
	 * @return array<string, mixed> The properties map.
	 */
	private function ownProperties(array $schema): array {
		$properties = ($schema['properties'] ?? []);
		if (is_array($properties) === false) {
			return [];
		}

		return $properties;
	}//end ownProperties()

	/**
	 * Compute a schema's OWN implemented semantic-type markers from the
	 * configuration shape (ADR-048: `implements[]`, else `jsonld.type`, plus
	 * `x-schema-org` markers). This intentionally mirrors
	 * {@see \OCA\OpenRegister\Service\JsonLd\JsonLdContextService::getImplementedTypes()}
	 * without requiring DI (annotation validators are constructed with `new`
	 * in the SchemaMapper save path).
	 *
	 * @param array<string, mixed> $schema The schema shape.
	 *
	 * @return array<int, string> The implemented type URIs (own markers only).
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Requirement: Kind contract binding on the implementing schema)
	 */
	private function implementedTypes(array $schema): array {
		$types = $this->declaredImplements(schema: $schema);
		if ($types === []) {
			$types = $this->jsonLdType(schema: $schema);
		}

		$marker = $this->schemaOrgMarker(schema: $schema);
		if ($marker !== null) {
			$types[] = $marker;
		}

		return array_values(array_unique($types));
	}//end implementedTypes()

	/**
	 * The non-empty string entries of an `implements` list.
	 *
	 * @param array<string, mixed> $schema The schema shape.
	 *
	 * @return array<int, string> The declared type URIs.
	 */
	private function declaredImplements(array $schema): array {
		$implements = ($schema['implements'] ?? null);
		if (is_array($implements) === false) {
			return [];
		}

		return array_values(array_filter($implements, static fn ($uri): bool => is_string($uri) === true && $uri !== ''));
	}//end declaredImplements()

	/**
	 * The `jsonld.type` marker, when it is a string.
	 *
	 * @param array<string, mixed> $schema The schema shape.
	 *
	 * @return array<int, string> The type, or nothing.
	 */
	private function jsonLdType(array $schema): array {
		$jsonld = ($schema['jsonld'] ?? null);
		if (is_array($jsonld) === true && is_string($jsonld['type'] ?? null) === true) {
			return [(string) $jsonld['type']];
		}

		return [];
	}//end jsonLdType()

	/**
	 * The `x-schema-org` marker as a full URI, expanding the `schema:` prefix.
	 *
	 * @param array<string, mixed> $schema The schema shape.
	 *
	 * @return string|null The marker, or null when absent or empty.
	 */
	private function schemaOrgMarker(array $schema): ?string {
		$schemaOrg = ($schema['x-schema-org'] ?? null);
		if (is_string($schemaOrg) === false || $schemaOrg === '') {
			return null;
		}

		if (str_starts_with($schemaOrg, self::SCHEMA_ORG_PREFIX) === true) {
			return 'https://schema.org/'.substr($schemaOrg, strlen(self::SCHEMA_ORG_PREFIX));
		}

		return $schemaOrg;
	}//end schemaOrgMarker()
}//end class
