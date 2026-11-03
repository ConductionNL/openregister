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
class HandoffContractBindingValidator {
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
			return [
				[
					'code' => 'handoff-contract-incomplete',
					'message' => 'handoffContract must be an object mapping kind URIs to {contractField: ownProperty} bindings.',
				],
			];
		}

		$properties = ($schema['properties'] ?? []);
		if (is_array($properties) === false) {
			$properties = [];
		}

		$implemented = $this->implementedTypes(schema: $schema);

		$errors = [];
		foreach ($binding as $kindUri => $fieldMap) {
			$kindUri = (string)$kindUri;

			if (in_array($kindUri, $implemented, true) === false) {
				$errors[] = [
					'code' => 'handoff-contract-incomplete',
					'message' => sprintf(
						'handoffContract binds kind "%s" which the schema does not implement (declare it in `implements`).',
						$kindUri
					),
				];
				continue;
			}

			if (HandoffKindContracts::isContractKind($kindUri) === false) {
				$errors[] = [
					'code' => 'handoff-contract-incomplete',
					'message' => sprintf(
						'handoffContract binds unknown kind "%s" (known contract kinds: %s).',
						$kindUri,
						implode(', ', HandoffKindContracts::kinds())
					),
				];
				continue;
			}

			$this->validateKindBinding(
				kindUri: $kindUri,
				fieldMap: $fieldMap,
				properties: $properties,
				errors: $errors
			);
		}//end foreach

		return $errors;
	}//end validate()

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
		$fieldMap = ($binding[$kindUri] ?? null);
		if (is_array($fieldMap) === false || $fieldMap === []) {
			return false;
		}

		foreach (HandoffKindContracts::mandatoryFields($kindUri) as $mandatory) {
			$target = ($fieldMap[$mandatory] ?? null);
			if (is_string($target) === false || array_key_exists($target, $properties) === false) {
				return false;
			}
		}

		foreach ($fieldMap as $contractField => $ownProperty) {
			if (is_string($ownProperty) === false || array_key_exists($ownProperty, $properties) === false) {
				return false;
			}

			if (in_array((string)$contractField, HandoffKindContracts::allFields($kindUri), true) === false) {
				return false;
			}
		}

		return true;
	}//end isCompleteBinding()

	/**
	 * Validate a single kind's field map inside the binding block.
	 *
	 * @param string $kindUri The kind URI being bound.
	 * @param mixed $fieldMap The declared `{contractField: ownProperty}` map.
	 * @param array<string, mixed> $properties The schema's own properties map.
	 * @param array<int, array{code: string, message: string}> $errors Error accumulator (by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: Implementer omits a mandatory contract field)
	 */
	private function validateKindBinding(string $kindUri, mixed $fieldMap, array $properties, array &$errors): void {
		if (is_array($fieldMap) === false || $fieldMap === []) {
			$errors[] = [
				'code' => 'handoff-contract-incomplete',
				'message' => sprintf(
					'handoffContract["%s"] must be a non-empty {contractField: ownProperty} map; missing mandatory fields: %s.',
					$kindUri,
					implode(', ', HandoffKindContracts::mandatoryFields($kindUri))
				),
			];
			return;
		}

		$allFields = HandoffKindContracts::allFields($kindUri);
		foreach ($fieldMap as $contractField => $ownProperty) {
			if (in_array((string)$contractField, $allFields, true) === false) {
				$errors[] = [
					'code' => 'handoff-contract-incomplete',
					'message' => sprintf(
						'handoffContract["%s"] binds "%s" which is not a contract field (fields: %s).',
						$kindUri,
						(string)$contractField,
						implode(', ', $allFields)
					),
				];
				continue;
			}

			if (is_string($ownProperty) === false || array_key_exists($ownProperty, $properties) === false) {
				$offending = gettype($ownProperty);
				if (is_string($ownProperty) === true) {
					$offending = $ownProperty;
				}

				$errors[] = [
					'code' => 'handoff-contract-incomplete',
					'message' => sprintf(
						'handoffContract["%s"]["%s"] must name an existing own property; "%s" is not declared in `properties`.',
						$kindUri,
						(string)$contractField,
						$offending
					),
				];
			}
		}//end foreach

		$missing = [];
		foreach (HandoffKindContracts::mandatoryFields($kindUri) as $mandatory) {
			if (array_key_exists($mandatory, $fieldMap) === false) {
				$missing[] = $mandatory;
			}
		}

		if ($missing !== []) {
			$errors[] = [
				'code' => 'handoff-contract-incomplete',
				'message' => sprintf(
					'handoffContract["%s"] omits mandatory contract field(s): %s.',
					$kindUri,
					implode(', ', $missing)
				),
			];
		}

	}//end validateKindBinding()

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
		$types = [];

		$implements = ($schema['implements'] ?? null);
		if (is_array($implements) === true) {
			foreach ($implements as $uri) {
				if (is_string($uri) === true && $uri !== '') {
					$types[] = $uri;
				}
			}
		}

		$jsonld = ($schema['jsonld'] ?? null);
		if ($types === [] && is_array($jsonld) === true && is_string($jsonld['type'] ?? null) === true) {
			$types[] = (string)$jsonld['type'];
		}

		$schemaOrg = ($schema['x-schema-org'] ?? null);
		if (is_string($schemaOrg) === true && $schemaOrg !== '') {
			$marker = $schemaOrg;
			if (str_starts_with($marker, 'schema:') === true) {
				$marker = 'https://schema.org/' . substr($marker, strlen('schema:'));
			}

			$types[] = $marker;
		}

		return array_values(array_unique($types));
	}//end implementedTypes()
}//end class
