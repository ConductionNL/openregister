<?php

/**
 * What a register's RBAC declarations mean for the generated OpenAPI document.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Oas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/oas-generation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Oas;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Authorization\RbacGroupCollector;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\Rbac\AggregateVisibility;
use OCA\OpenRegister\Service\Rbac\EffectiveAuthorization;
use Psr\Log\LoggerInterface;

/**
 * Turns a schema's authorization block into scopes, security requirements and
 * a describable property list.
 *
 * 🔴 A DESCRIPTION IS A DISCLOSURE. The document names every property of
 * every schema, so a property the caller may not read must not appear in it
 * either: `maySummarise()` is the same question the aggregation surface asks,
 * deliberately, because two answers to "may this be named" is how a field
 * stays hidden in one place and listed in another.
 *
 * Kept apart from {@see OasService} because that class is about the SHAPE of
 * the document — paths, operations, parameters, references — and this is
 * about who may see what. They were one class only because the generator
 * grew the RBAC questions as it went.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) Carried from `OasService`, unchanged:
 * the named declaration readers this leans on are the ones `phpmd.xml`
 * excepts by name.
 *
 * @spec openspec/specs/oas-generation/spec.md
 */
class OasRbacAnnotator {

	/**
	 * The block a schema is actually governed by.
	 *
	 * @var EffectiveAuthorization
	 */
	private EffectiveAuthorization $authorization;

	/**
	 * Constructor.
	 *
	 * @param RegisterMapper           $registerMapper Resolves the register a schema's authorization falls back to.
	 * @param LoggerInterface|null     $logger         Where an unreadable rule is noted.
	 * @param PropertyRbacHandler|null $propertyRbac   Withholds a property the caller may not read.
	 */
	public function __construct(
		RegisterMapper $registerMapper,
		private readonly ?LoggerInterface $logger = null,
		private readonly ?PropertyRbacHandler $propertyRbac = null,
	) {
		$this->authorization = new EffectiveAuthorization(registerMapper: $registerMapper);
	}//end __construct()

	/**
	 * Extract unique RBAC groups from schema-level and property-level authorization rules
	 *
	 * Collects groups from the schema's authorization field (CRUD-level access control)
	 * and from individual property authorization rules (field-level access control).
	 *
	 * @param object $schema The schema object
	 *
	 * @return array{createGroups: string[], readGroups: string[], updateGroups: string[], deleteGroups: string[]}
	 *                                                                                                             Unique groups per CRUD action
	 *
	 * @spec openspec/specs/deprecate-published-metadata/spec.md
	 */
	public function extractSchemaGroups(object $schema): array {
		$perAction = ['create' => [], 'read' => [], 'update' => [], 'delete' => []];

		// Step 1: the effective authorization (schema-level, or the register cascade).
		$effectiveAuth = $this->authorization->forSchema(schema: $schema);
		if (is_array($effectiveAuth) === true && empty($effectiveAuth) === false) {
			$this->collectGroups(block: $effectiveAuth, into: $perAction);
		}

		// Step 2: the property-level authorization, which can name groups the
		// schema-level block does not.
		foreach (($schema->getProperties() ?? []) as $propertyDefinition) {
			if (is_array($propertyDefinition) === false) {
				continue;
			}

			$auth = ($propertyDefinition['authorization'] ?? null);
			if (is_array($auth) === false) {
				continue;
			}

			$this->collectGroups(block: $auth, into: $perAction);
		}//end foreach

		return [
			'createGroups' => array_values(array_unique($perAction['create'])),
			'readGroups' => array_values(array_unique($perAction['read'])),
			'updateGroups' => array_values(array_unique($perAction['update'])),
			'deleteGroups' => array_values(array_unique($perAction['delete'])),
		];
	}//end extractSchemaGroups()

	/**
	 * Add one authorization block's groups to the per-action lists.
	 *
	 * `manage` is deliberately not among the four: it is not a CRUD action,
	 * and a scope named for it would appear on operations it does not govern.
	 *
	 * @param array<string, mixed>              $block The authorization block.
	 * @param array<string, array<int, string>> $into  The per-action lists, added to in place.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	private function collectGroups(array $block, array &$into): void {
		foreach (array_keys($into) as $action) {
			foreach (($block[$action] ?? []) as $rule) {
				$group = $this->extractGroupFromRule(rule: $rule);
				if ($group !== null) {
					$into[$action][] = $group;
				}
			}
		}
	}//end collectGroups()

	/**
	 * Extract group name from an authorization rule
	 *
	 * Rules can be either a plain string (group name) or an object with a 'group' key.
	 *
	 * @param mixed $rule The authorization rule (string or array)
	 *
	 * @return string|null The group name, or null if not extractable
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	public function extractGroupFromRule($rule): ?string {
		// Delegated so the OAS scope map, the configuration export and group
		// provisioning all read an authorization rule the same way — a divergence
		// here would mean OR advertises one scope set and enforces another.
		return (new RbacGroupCollector())->groupFromRule(rule: $rule);
	}//end extractGroupFromRule()

	/**
	 * Get a human-readable description for an OAuth2 scope based on group name
	 *
	 * @param string $group The Nextcloud group name
	 *
	 * @return string The scope description
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	public function getScopeDescription(string $group): string {
		if ($group === 'admin') {
			return 'Full administrative access';
		}

		if ($group === 'public') {
			return 'Public (unauthenticated) access';
		}

		return 'Access for ' . $group . ' group';
	}//end getScopeDescription()

	/**
	 * Apply RBAC information to an operation
	 *
	 * Always includes `admin` since admin users have access to all endpoints.
	 * Merges in any schema-specific groups for this CRUD action and:
	 *  - appends a human-readable `**Required scopes:**` block to the operation
	 *    description (Markdown rendered by Swagger UI / Redoc);
	 *  - adds a 403 response definition pointing at the standard Error schema;
	 *  - emits a per-operation OpenAPI 3.0 `security` requirement enumerating
	 *    the groups as OAuth2 scopes alongside `basicAuth` as fallback. This
	 *    makes the OAS a machine-readable access audit (see the Scope Audit
	 *    requirement in the rbac-scopes spec) and lets generated client SDKs
	 *    request the right scope set.
	 *
	 * The `security` block is OR-semantics across alternatives in the array
	 * (per the OpenAPI 3.0 spec), so a caller can either present a Bearer token
	 * with one of the listed oauth2 scopes OR fall back to Basic auth. The
	 * registered Nextcloud OAuth2 scope vocabulary is populated globally from
	 * the union of every schema's groups in createOas().
	 *
	 * @param array $operation The operation array (passed by reference)
	 * @param string[] $groups The schema-specific groups that have access to this operation
	 *
	 * @return void
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	public function applyRbacToOperation(array &$operation, array $groups): void {
		// Admin always has access to every endpoint.
		if (in_array('admin', $groups, true) === false) {
			array_unshift($groups, 'admin');
		}

		// Deduplicate while preserving order — admin first, then schema groups.
		$groups = array_values(array_unique($groups));

		// Build scope list as inline code fragments.
		$scopeList = implode(
			', ',
			array_map(
				static function (string $group): string {
					return '`' . $group . '`';
				},
				$groups
			)
		);

		$operation['description'] .= "\n\n**Required scopes:** " . $scopeList;

		// Add 403 response.
		$operation['responses']['403'] = [
			'description' => 'Forbidden — user does not have the required group membership for this action',
			'content' => [
				'application/json' => [
					'schema' => ['$ref' => '#/components/schemas/Error'],
				],
			],
		];

		// Emit per-operation security requirement: oauth2 with the resolved
		// scope set, OR basicAuth fallback. Two array entries = OR semantics
		// in OpenAPI 3.0.
		$operation['security'] = [
			['oauth2' => $groups],
			['basicAuth' => []],
		];
	}//end applyRbacToOperation()

	/**
	 * Whether this caller may be told that a property exists.
	 *
	 * Asks the ONE thing that already decides property reads, through the same
	 * `AggregateVisibility` #3938 introduced for exactly this. Neither this
	 * class nor the GraphQL mapper holds a rule of its own; two answers to
	 * "may this person see this field" drift, and the wider one discloses.
	 *
	 * An administrator receives the complete description, because they already
	 * bypass property-level reads everywhere else. Making the OpenAPI document
	 * the one place they cannot see the schema would be a second answer to a
	 * question `PropertyRbacHandler` already answers.
	 *
	 * @param object $schema   The schema.
	 * @param string $property The property name.
	 *
	 * @return bool Whether it may be described.
	 *
	 * @spec openspec/changes/schema-shape-exposure/specs/rbac-scopes/spec.md
	 */
	public function mayDescribe(object $schema, string $property): bool {
		if (($schema instanceof Schema) === false) {
			return true;
		}

		return $this->shapeVisibility()->maySummarise(schema: $schema, property: $property);
	}//end mayDescribe()

	/**
	 * The required list, filtered to what this document still describes.
	 *
	 * @param object               $schema    The schema.
	 * @param array<string, mixed> $described The properties this document carries.
	 *
	 * @return array<int, string> The required names.
	 */
	public function describableRequired(object $schema, array $described): array {
		if (method_exists($schema, 'getRequired') === false) {
			return [];
		}

		$required = $schema->getRequired();
		if (is_array($required) === false) {
			return [];
		}

		$kept = [];
		foreach ($required as $name) {
			if (array_key_exists((string)$name, $described) === true) {
				$kept[] = (string)$name;
			}
		}

		return $kept;
	}//end describableRequired()

	/**
	 * The shared answer to "may this person see this field".
	 *
	 * @return AggregateVisibility The answer.
	 */
	public function shapeVisibility(): AggregateVisibility {
		return new AggregateVisibility(rbac: $this->propertyRbac, logger: $this->logger);
	}//end shapeVisibility()

}//end class
