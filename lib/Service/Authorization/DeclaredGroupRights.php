<?php

/**
 * OpenRegister DeclaredGroupRights
 *
 * The rights an app's configuration declares for its groups (REQ-RAH-003).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Authorization
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-a-product-owned-groups-rights-are-restored-to-what-the-product-declares-req-rah-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Authorization;

/**
 * Reads, per register and schema slug, the authorization rules a configuration
 * document gives the groups it declares.
 *
 * The shape is `{schemas: {slug: {action: [rule, ...]}}, registers: {...}}`,
 * where a rule is kept exactly as the document writes it (a bare group id, or
 * an object with a `group` and match conditions), so a restored right is the
 * declared one and not a simplification of it. Only the top-level
 * authorization block of a register or schema is read; the `roles` map and
 * flags such as `public: true` are not rights and are left out.
 *
 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-a-product-owned-groups-rights-are-restored-to-what-the-product-declares-req-rah-003
 */
final class DeclaredGroupRights {

	/**
	 * App-config key prefix under which each app's declared rights are stored,
	 * beside {@see GroupReconciler::DECLARED_GROUPS_PREFIX}.
	 *
	 * @var string
	 */
	public const PREFIX = 'declared_group_rights_';

	/**
	 * The key of the role map in an authorization block; it assigns groups to roles, it grants no action itself.
	 *
	 * @var string
	 */
	private const ROLES_KEY = 'roles';

	/**
	 * Constructor.
	 *
	 * @param RbacGroupCollector $collector Reads the group a rule names, and which groups are provisionable.
	 */
	public function __construct(
		private readonly RbacGroupCollector $collector,
	) {
	}//end __construct()

	/**
	 * The rights a configuration document declares for its groups.
	 *
	 * @param array<string, mixed> $document The decoded configuration document.
	 *
	 * @return array{schemas: array<string, array<string, list<mixed>>>, registers: array<string, array<string, list<mixed>>>}
	 *
	 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-a-product-owned-groups-rights-are-restored-to-what-the-product-declares-req-rah-003
	 */
	public function fromDocument(array $document): array {
		return [
			'schemas'   => $this->fromOwners(owners: ($document['components']['schemas'] ?? [])),
			'registers' => $this->fromOwners(owners: ($document['components']['registers'] ?? [])),
		];
	}//end fromDocument()

	/**
	 * The declared rules of one authorization block, by action, keeping only rules that name a provisionable group.
	 *
	 * @param mixed $authorization The authorization block.
	 *
	 * @return array<string, list<mixed>> Action => rules; an action without such rules is left out.
	 *
	 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-a-product-owned-groups-rights-are-restored-to-what-the-product-declares-req-rah-003
	 */
	public function fromBlock(mixed $authorization): array {
		if (is_array($authorization) === false) {
			return [];
		}

		$rights = [];
		foreach ($authorization as $action => $rules) {
			if (is_string($action) === false || $action === self::ROLES_KEY || is_array($rules) === false) {
				continue;
			}

			$kept = array_values(array_filter($rules, fn (mixed $rule): bool => $this->groupOf(rule: $rule) !== null));
			if ($kept !== []) {
				$rights[$action] = $kept;
			}
		}

		return $rights;
	}//end fromBlock()

	/**
	 * The provisionable group a rule names, or null for a reserved principal or a rule without a group.
	 *
	 * @param mixed $rule The rule.
	 *
	 * @return string|null The group id.
	 *
	 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-a-product-owned-groups-rights-are-restored-to-what-the-product-declares-req-rah-003
	 */
	public function groupOf(mixed $rule): ?string {
		$group = $this->collector->groupFromRule(rule: $rule);
		if ($group === null || $this->collector->provisionable(groups: [$group]) === []) {
			return null;
		}

		return $group;
	}//end groupOf()

	/**
	 * The declared rights of each register or schema definition, by slug.
	 *
	 * @param mixed $owners The `components.schemas` or `components.registers` map.
	 *
	 * @return array<string, array<string, list<mixed>>> Slug => action => rules; a slug without declared rules maps to [].
	 */
	private function fromOwners(mixed $owners): array {
		if (is_array($owners) === false) {
			return [];
		}

		$rights = [];
		foreach ($owners as $key => $definition) {
			if (is_array($definition) === false) {
				continue;
			}

			// Every register or schema the app ships is listed, an empty block
			// included: a right added there to a declared group is reverted too.
			$slug = $definition['slug'] ?? $key;
			if (is_string($slug) === true && $slug !== '') {
				$rights[strtolower($slug)] = $this->fromBlock(authorization: ($definition['authorization'] ?? null));
			}
		}

		return $rights;
	}//end fromOwners()

	/**
	 * A rule's identity: its JSON with object keys sorted, so `{group, match}` and `{match, group}` are one rule.
	 *
	 * @param mixed $rule The rule.
	 *
	 * @return string The key.
	 *
	 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-a-product-owned-groups-rights-are-restored-to-what-the-product-declares-req-rah-003
	 */
	public function ruleKey(mixed $rule): string {
		return (string)json_encode(self::sorted(value: $rule), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}//end ruleKey()

	/**
	 * A value with every associative array's keys sorted, recursively.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The sorted value.
	 */
	private static function sorted(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		$value = array_map(self::sorted(...), $value);
		if (array_is_list($value) === false) {
			ksort($value);
		}

		return $value;
	}//end sorted()
}//end class
