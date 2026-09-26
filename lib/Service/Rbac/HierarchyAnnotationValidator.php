<?php

/**
 * Refuses a broken `x-openregister-hierarchy` at schema save (REQ-RIC-001).
 *
 * THIS ONE THROWS RATHER THAN WARNING, and the reason is what the annotation
 * does. It names the edge a GRANT travels down. An author who points it at the
 * wrong property has not written a cosmetic mistake: `assignee` on a case
 * references a USER, so a hierarchy declared over it would hand everybody who
 * may read one object every object filed to the same person. The save is the
 * only moment that is visible, because afterwards it looks exactly like
 * working inheritance.
 *
 * WHAT IT REFUSES, and nothing beyond it:
 *
 *  - a `parent` that names no property of this schema, which inherits nothing
 *    and says nothing;
 *  - a `parent` that is not a reference at all, or references a DIFFERENT
 *    schema. One declared parent property per schema, pointing at the same
 *    schema: a graph is not a hierarchy, and an edge that crosses schemas
 *    makes the descent unbounded in a way `maxDepth` does not describe;
 *  - a `maxDepth` that is not a positive integer;
 *  - an `inheritedVerbs` that is not a list of strings.
 *
 * An UNKNOWN key inside the block is surfaced and ignored rather than refused,
 * the same rule the archival validator records one annotation over: an unknown
 * key declares nothing, so dropping it loses nothing, while refusing it costs
 * the register every object of that schema at import time.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * Validates the hierarchy annotation against the schema that carries it.
 *
 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
 */
class HierarchyAnnotationValidator {

	/**
	 * The keys the block may carry.
	 *
	 * `parentField` is here beside `parent` because a consuming app shipped
	 * that spelling before this annotation was specified, and an unknown key
	 * would be dropped in silence: the app would declare an edge, the resolver
	 * would report no inheritance, and nothing would say the declaration was
	 * never read.
	 *
	 * @var string[]
	 */
	public const KNOWN_KEYS = ['parent', 'parentField', 'maxDepth', 'inheritedVerbs'];

	/**
	 * Findings for one schema shape.
	 *
	 * @param array<string, mixed> $schema `properties`, `slug` and the annotation.
	 *
	 * @return array<int, array{code: string, message: string, severity: string}> The findings.
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	public function validate(array $schema): array {
		$annotation = ($schema[HierarchyGrantExpander::ANNOTATION] ?? null);
		if ($annotation === null) {
			return [];
		}

		if (is_array($annotation) === false) {
			return [
				$this->error(
					code: 'hierarchy.not-object',
					message: HierarchyGrantExpander::ANNOTATION . ' must be an object.'
				),
			];
		}

		$findings = $this->unknownKeyFindings(annotation: $annotation);

		$parent = trim((string)($annotation['parent'] ?? ($annotation['parentField'] ?? '')));
		if ($parent === '') {
			$findings[] = $this->error(
				code: 'hierarchy.no-parent',
				message: 'A hierarchy must name the property that points at the parent.'
			);
			return $findings;
		}

		$declaredProperties = [];
		if (is_array($schema['properties'] ?? null) === true) {
			$declaredProperties = $schema['properties'];
		}

		$findings = array_merge(
			$findings,
			$this->checkParentProperty(
				parent: $parent,
				properties: $declaredProperties,
				identities: self::identitiesOf(schema: $schema)
			)
		);

		$findings = array_merge(
			$findings,
			$this->maxDepthFindings(annotation: $annotation),
			$this->inheritedVerbsFindings(annotation: $annotation)
		);

		return $findings;
	}//end validate()

	/**
	 * A warning for every annotation key this validator does not know.
	 *
	 * An unknown key is IGNORED rather than refused, so the warning is the only
	 * thing standing between a typo and an annotation that quietly does less
	 * than its author wrote.
	 *
	 * @param array<string, mixed> $annotation The hierarchy annotation.
	 *
	 * @return array<int, array{code: string, message: string, severity: string}> The findings.
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	private function unknownKeyFindings(array $annotation): array {
		$findings = [];
		foreach (array_keys($annotation) as $key) {
			if (in_array((string)$key, self::KNOWN_KEYS, true) === false) {
				$findings[] = [
					'code' => 'hierarchy.unknown-key',
					'message' => 'Unknown key "' . (string)$key . '" was ignored.',
					'severity' => 'warning',
				];
			}
		}

		return $findings;
	}//end unknownKeyFindings()

	/**
	 * Findings for the optional `maxDepth` key.
	 *
	 * @param array<string, mixed> $annotation The hierarchy annotation.
	 *
	 * @return array<int, array{code: string, message: string, severity: string}> The findings.
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	private function maxDepthFindings(array $annotation): array {
		if (array_key_exists('maxDepth', $annotation) === false) {
			return [];
		}

		$depth = $annotation['maxDepth'];
		if (is_int($depth) === false || $depth < 1) {
			return [
				$this->error(
					code: 'hierarchy.bad-depth',
					message: 'maxDepth must be a positive integer.'
				),
			];
		}

		return [];
	}//end maxDepthFindings()

	/**
	 * Findings for the optional `inheritedVerbs` key.
	 *
	 * A non-list and a list holding a blank name are BOTH reported when both
	 * are true, which is what the sequential version did: the shape error does
	 * not stop the member check, it just leaves nothing for it to walk.
	 *
	 * @param array<string, mixed> $annotation The hierarchy annotation.
	 *
	 * @return array<int, array{code: string, message: string, severity: string}> The findings.
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	private function inheritedVerbsFindings(array $annotation): array {
		if (array_key_exists('inheritedVerbs', $annotation) === false) {
			return [];
		}

		$findings = [];
		$verbs = $annotation['inheritedVerbs'];
		if (is_array($verbs) === false) {
			$findings[] = $this->error(
				code: 'hierarchy.bad-verbs',
				message: 'inheritedVerbs must be a list of verbs.'
			);
			$verbs = [];
		}

		foreach ($verbs as $verb) {
			if (is_string($verb) === false || trim($verb) === '') {
				$findings[] = $this->error(
					code: 'hierarchy.bad-verbs',
					message: 'inheritedVerbs must hold non-empty verb names.'
				);
				break;
			}
		}

		return $findings;
	}//end inheritedVerbsFindings()

	/**
	 * Whether the named property is a reference to this same schema.
	 *
	 * @param string $parent The declared property name.
	 * @param array<string, mixed> $properties The schema's properties.
	 * @param array<int, string> $identities Every spelling that names THIS schema.
	 *
	 * @return array<int, array{code: string, message: string, severity: string}> The findings.
	 */
	private function checkParentProperty(string $parent, array $properties, array $identities): array {
		$property = ($properties[$parent] ?? null);
		if (is_array($property) === false) {
			return [
				$this->error(
					code: 'hierarchy.unknown-property',
					message: 'The parent property "' . $parent . '" is not a property of this schema.'
				),
			];
		}

		// `$ref` is how this app declares a reference, and it carries the
		// target's SLUG. `objectConfiguration.schema` is the older spelling and
		// is read too, for the same reason both parent spellings are: a schema
		// saved before the newer one existed would otherwise read as declaring
		// no reference at all and be refused on upgrade.
		$target = trim((string)($property['$ref'] ?? ($property['objectConfiguration']['schema'] ?? '')));
		if ($target === '') {
			return [
				$this->error(
					code: 'hierarchy.not-a-reference',
					message: 'The parent property "' . $parent . '" is not declared as a reference to another object.'
				),
			];
		}

		if ($identities !== [] && $this->targetsSelf(target: $target, identities: $identities) === false) {
			return [
				$this->error(
					code: 'hierarchy.foreign-reference',
					message: 'The parent property "' . $parent . '" references "' . $target
						. '" rather than this schema; a hierarchy edge must point at the same schema.'
				),
			];
		}

		return [];
	}//end checkParentProperty()

	/**
	 * Every spelling by which a `$ref` can name this schema.
	 *
	 * 🔴 THE SLUG IS NOT THE ONLY ONE, and assuming it was cost dossiq its
	 * whole case register. `Configuration\ImportHandler` REWRITES every `$ref`
	 * from the slug the file carries to the schema's numeric id once the
	 * target is known, so dossiq ships `"$ref": "case"` on the `case` schema
	 * and this validator was handed `"$ref": "169"`. Comparing that to `case`
	 * refused the import of a declaration that is perfectly correct as
	 * written, and the refusal is total: the whole schema fails to import.
	 * Measured on a live instance 2026-09-19.
	 *
	 * The id and the uuid are therefore as much this schema's name as the slug
	 * is. The title is here for the same reason both parent spellings are
	 * accepted: an author who writes the human name has still named this
	 * schema and nothing else.
	 *
	 * @param array<string, mixed> $schema The schema shape being validated.
	 *
	 * @return array<int, string> The identities, without empties.
	 */
	private static function identitiesOf(array $schema): array {
		$identities = [];
		foreach (['slug', 'id', 'uuid', 'title'] as $key) {
			$value = ($schema[$key] ?? null);
			if (is_string($value) === false && is_int($value) === false) {
				continue;
			}

			$value = trim((string)$value);
			if ($value !== '' && in_array($value, $identities, true) === false) {
				$identities[] = $value;
			}
		}

		return $identities;
	}//end identitiesOf()

	/**
	 * Whether a reference target names this schema.
	 *
	 * A `$ref` is written as a bare slug in this app's own registers, as the
	 * numeric id after an import has resolved it, and as a path in an imported
	 * one, so the tail is compared as well as the whole string. Comparing only
	 * the whole string would refuse a perfectly good declaration on any schema
	 * that arrived through an import.
	 *
	 * @param string $target The declared reference target.
	 * @param array<int, string> $identities Every spelling that names this schema.
	 *
	 * @return bool True when the reference points at this schema.
	 */
	private function targetsSelf(string $target, array $identities): bool {
		if (in_array($target, $identities, true) === true) {
			return true;
		}

		$lastSlash = strrpos($target, '/');
		$offset = 0;
		if ($lastSlash !== false) {
			$offset = ($lastSlash + 1);
		}

		$tail = substr($target, $offset);

		return in_array($tail, $identities, true);
	}//end targetsSelf()

	/**
	 * Split findings into the fatal ones and the rest.
	 *
	 * @param array<int, array{code: string, message: string, severity: string}> $findings The findings.
	 *
	 * @return array{errors: array<int, array<string, string>>, warnings: array<int, array<string, string>>} The split.
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	public static function partition(array $findings): array {
		$errors = [];
		$warnings = [];
		foreach ($findings as $finding) {
			if (($finding['severity'] ?? 'error') === 'warning') {
				$warnings[] = $finding;
				continue;
			}

			$errors[] = $finding;
		}

		return ['errors' => $errors, 'warnings' => $warnings];
	}//end partition()

	/**
	 * One fatal finding.
	 *
	 * @param string $code The code.
	 * @param string $message The message.
	 *
	 * @return array{code: string, message: string, severity: string} The finding.
	 */
	private function error(string $code, string $message): array {
		return ['code' => $code, 'message' => $message, 'severity' => 'error'];
	}//end error()
}//end class
