<?php

/**
 * OpenRegister SemanticRoleHandler
 *
 * A schema says which of its properties MEANS the title, the status, the
 * assignee and the term. That is a promise to a list component, not a rename
 * (design.md D-5): nothing about the data changes, and one list view can then
 * render a schema it has never seen.
 *
 * It is the cheapest half of a configurable list, and it is the half a leaf
 * app hard-codes today: dossiq knows that `onderwerp` is the title of a case
 * because someone wrote that in a Vue component, which is why the same list
 * cannot serve a second case type without a second component.
 *
 * The same class carries administered help text, because it has the same
 * shape: a per-property annotation, resolved per language, read at schema
 * read. A field called `archiefnominatie` is a guess without help text, and a
 * wrong guess is a records-management error nobody catches for seven years.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/runtime-schema-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

/**
 * Reads, validates and resolves the semantic roles and help text a schema declares.
 */
class SemanticRoleHandler {

	/**
	 * The property annotation naming a semantic role.
	 *
	 * @var string
	 */
	public const ROLE_ANNOTATION = 'x-openregister-role';

	/**
	 * The property annotation carrying administered help text.
	 *
	 * @var string
	 */
	public const HELP_ANNOTATION = 'x-openregister-help';

	/**
	 * The roles a property may declare.
	 *
	 * Deliberately closed. An open list would let two apps invent two names
	 * for the same meaning, which is the situation a semantic role exists to
	 * end.
	 *
	 * @var array<int,string>
	 */
	public const ROLES = ['title', 'status', 'assignee', 'term'];

	/**
	 * The roles a schema declares, keyed by role name.
	 *
	 * @param array<string,mixed> $properties The schema's property map.
	 *
	 * @return array<string,string> The property name holding each role.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/runtime-schema-api/spec.md
	 */
	public function roles(array $properties): array {
		$roles = [];
		foreach ($properties as $name => $property) {
			$role = $this->roleOf(property: $property);
			if ($role === null) {
				continue;
			}

			// First declaration wins in the READ. A schema carrying two is
			// refused at SAVE, so this only matters for rows written before
			// the refusal existed, and answering with one is more useful than
			// answering with neither.
			if (isset($roles[$role]) === false) {
				$roles[$role] = (string)$name;
			}
		}

		return $roles;
	}//end roles()

	/**
	 * The reasons a schema's role declarations cannot be saved.
	 *
	 * Two properties claiming one role is the refusal the spec names, and the
	 * message names both, because "duplicate role" without the names sends
	 * someone reading a forty-property schema by eye.
	 *
	 * @param array<string,mixed> $properties The schema's property map.
	 *
	 * @return array<int,string> The refusals, empty when the declarations are valid.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/runtime-schema-api/spec.md
	 */
	public function violations(array $properties): array {
		$byRole = [];
		$errors = [];

		foreach ($properties as $name => $property) {
			$declared = $this->rawRoleOf(property: $property);
			if ($declared === null) {
				continue;
			}

			if (in_array($declared, self::ROLES, true) === false) {
				$errors[] = sprintf(
					'The property "%s" declares the role "%s", which is not one of: %s.',
					(string)$name,
					$declared,
					implode(', ', self::ROLES)
				);
				continue;
			}

			$byRole[$declared][] = (string)$name;
		}//end foreach

		foreach ($byRole as $role => $names) {
			if (count($names) < 2) {
				continue;
			}

			$errors[] = sprintf(
				'The properties "%s" both declare the role "%s", and a schema may declare each role once.',
				implode('" and "', $names),
				(string)$role
			);
		}

		return $errors;
	}//end violations()

	/**
	 * The help text of every property that carries it, in one language.
	 *
	 * @param array<string,mixed> $properties The schema's property map.
	 * @param string $language The BCP-47 tag asked for.
	 *
	 * @return array<string,string> The resolved help text, keyed by property name.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/runtime-schema-api/spec.md
	 */
	public function helpTexts(array $properties, string $language): array {
		$help = [];
		foreach ($properties as $name => $property) {
			$resolved = $this->helpTextOf(property: $property, language: $language);
			if ($resolved !== null) {
				$help[(string)$name] = $resolved;
			}
		}

		return $help;
	}//end helpTexts()

	/**
	 * One property's help text in the asked-for language, or null.
	 *
	 * A plain string is help text in every language, which is what an
	 * organisation with one language writes. A map is resolved by exact tag,
	 * then by its primary subtag, then by Dutch, then by whatever is there:
	 * showing help in the wrong language beats showing none.
	 *
	 * @param mixed $property The schema property definition.
	 * @param string $language The BCP-47 tag asked for.
	 *
	 * @return string|null The help text.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/runtime-schema-api/spec.md
	 */
	public function helpTextOf(mixed $property, string $language): ?string {
		$raw = $this->annotationOf(property: $property, annotation: self::HELP_ANNOTATION);

		if (is_string($raw) === true) {
			$raw = trim($raw);
			return ($raw === '') ? null : $raw;
		}

		if (is_object($raw) === true) {
			$raw = (array)$raw;
		}

		if (is_array($raw) === false) {
			return null;
		}

		return $this->localisedHelp(map: $raw, language: $language);
	}//end helpTextOf()

	/**
	 * Pick a help string from a per-language map: exact tag, then primary
	 * subtag, then Dutch, then English, then whatever non-empty string is
	 * there — showing help in the wrong language beats showing none.
	 *
	 * @param array<mixed> $map The per-language help map.
	 * @param string $language The BCP-47 tag asked for.
	 *
	 * @return string|null The help text, or null when the map holds none.
	 */
	private function localisedHelp(array $map, string $language): ?string {
		foreach ([$language, substr($language, 0, 2), 'nl', 'en'] as $tag) {
			$candidate = ($map[$tag] ?? null);
			if (is_string($candidate) === true && trim($candidate) !== '') {
				return trim($candidate);
			}
		}

		foreach ($map as $candidate) {
			if (is_string($candidate) === true && trim($candidate) !== '') {
				return trim($candidate);
			}
		}

		return null;
	}//end localisedHelp()

	/**
	 * One property's declared role, when it is a valid one.
	 *
	 * @param mixed $property The schema property definition.
	 *
	 * @return string|null The role.
	 */
	private function roleOf(mixed $property): ?string {
		$role = $this->rawRoleOf(property: $property);
		if ($role === null || in_array($role, self::ROLES, true) === false) {
			return null;
		}

		return $role;
	}//end roleOf()

	/**
	 * One property's declared role, valid or not.
	 *
	 * @param mixed $property The schema property definition.
	 *
	 * @return string|null The raw role.
	 */
	private function rawRoleOf(mixed $property): ?string {
		$role = $this->annotationOf(property: $property, annotation: self::ROLE_ANNOTATION);
		if (is_string($role) === false) {
			return null;
		}

		$role = trim($role);

		if ($role === '') {
			return null;
		}

		return $role;
	}//end rawRoleOf()

	/**
	 * Read one annotation off a property, array- or object-shaped.
	 *
	 * @param mixed $property The schema property definition.
	 * @param string $annotation The annotation key.
	 *
	 * @return mixed The annotation's value, or null.
	 */
	private function annotationOf(mixed $property, string $annotation): mixed {
		if (is_array($property) === true) {
			return ($property[$annotation] ?? null);
		}

		if (is_object($property) === true) {
			return ($property->{$annotation} ?? null);
		}

		return null;
	}//end annotationOf()
}//end class
