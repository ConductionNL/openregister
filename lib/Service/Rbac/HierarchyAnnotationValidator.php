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

		$parent = trim((string)($annotation['parent'] ?? ($annotation['parentField'] ?? '')));
		if ($parent === '') {
			$findings[] = $this->error(
				code: 'hierarchy.no-parent',
				message: 'A hierarchy must name the property that points at the parent.'
			);
			return $findings;
		}

		$findings = array_merge(
			$findings,
			$this->checkParentProperty(
				parent: $parent,
				properties: (is_array($schema['properties'] ?? null) === true ? $schema['properties'] : []),
				slug: trim((string)($schema['slug'] ?? ''))
			)
		);

		if (array_key_exists('maxDepth', $annotation) === true) {
			$depth = $annotation['maxDepth'];
			if (is_int($depth) === false || $depth < 1) {
				$findings[] = $this->error(
					code: 'hierarchy.bad-depth',
					message: 'maxDepth must be a positive integer.'
				);
			}
		}

		if (array_key_exists('inheritedVerbs', $annotation) === true) {
			$verbs = $annotation['inheritedVerbs'];
			if (is_array($verbs) === false) {
				$findings[] = $this->error(
					code: 'hierarchy.bad-verbs',
					message: 'inheritedVerbs must be a list of verbs.'
				);
			} else {
				foreach ($verbs as $verb) {
					if (is_string($verb) === false || trim($verb) === '') {
						$findings[] = $this->error(
							code: 'hierarchy.bad-verbs',
							message: 'inheritedVerbs must hold non-empty verb names.'
						);
						break;
					}
				}
			}
		}

		return $findings;
	}//end validate()

	/**
	 * Whether the named property is a reference to this same schema.
	 *
	 * @param string $parent The declared property name.
	 * @param array<string, mixed> $properties The schema's properties.
	 * @param string $slug This schema's slug.
	 *
	 * @return array<int, array{code: string, message: string, severity: string}> The findings.
	 */
	private function checkParentProperty(string $parent, array $properties, string $slug): array {
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

		if ($slug !== '' && $this->targetsSelf(target: $target, slug: $slug) === false) {
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
	 * Whether a reference target names this schema.
	 *
	 * A `$ref` is written as a bare slug in this app's own registers and as a
	 * path in an imported one, so the tail is compared rather than the whole
	 * string. Comparing the whole string would refuse a perfectly good
	 * declaration on any schema that arrived through an import.
	 *
	 * @param string $target The declared reference target.
	 * @param string $slug This schema's slug.
	 *
	 * @return bool True when the reference points at this schema.
	 */
	private function targetsSelf(string $target, string $slug): bool {
		if ($target === $slug) {
			return true;
		}

		$tail = substr($target, (strrpos($target, '/') === false ? 0 : (int)strrpos($target, '/') + 1));

		return ($tail === $slug);
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
