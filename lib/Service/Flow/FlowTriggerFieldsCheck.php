<?php

/**
 * FlowTriggerFieldsCheck: a published object trigger watches only properties its schema has.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use Throwable;

/**
 * Refuses a publish whose `changedFields` names a property the trigger's schema does not have.
 *
 * Such a trigger never starts and says nothing about it. The node's own
 * `validateConfig()` cannot catch it, because it does not know the schema;
 * the publish is where the schema is known. The schema is resolved inside the
 * trigger's own register, so a same-slug schema of another app is never read.
 * A register or schema that does not resolve leaves the check to the run: the
 * trigger index would not match it either.
 *
 * @spec openspec/changes/flow-trigger-transitions-and-changed-fields/specs/flow-engine/spec.md#requirement-an-update-trigger-can-watch-named-fields
 */
class FlowTriggerFieldsCheck {

	/**
	 * Constructor.
	 *
	 * @param RegisterMapperInterface $registers The registers.
	 * @param SchemaMapperInterface   $schemas   The schemas.
	 */
	public function __construct(
		private readonly RegisterMapperInterface $registers,
		private readonly SchemaMapperInterface $schemas,
	) {
	}//end __construct()

	/**
	 * Refuse the publish when a watched field is not a property of its schema.
	 *
	 * @param string               $flowId The flow.
	 * @param array<string, mixed> $graph  The graph being published.
	 *
	 * @return void
	 *
	 * @throws FlowLifecycleRefused When a trigger watches an unknown property.
	 *
	 * @spec openspec/changes/flow-trigger-transitions-and-changed-fields/specs/flow-engine/spec.md#requirement-an-update-trigger-can-watch-named-fields
	 */
	public function refuseUnknownChangedFields(string $flowId, array $graph): void {
		foreach ((array)($graph['nodes'] ?? []) as $node) {
			$config = $this->watchingConfig(node: $node);
			if ($config === null) {
				continue;
			}

			$schema = $this->schemaOf(register: (string)$config['register'], slug: (string)$config['schema']);
			if ($schema === null) {
				continue;
			}

			$properties = array_map('strval', array_keys($schema->getProperties() ?? []));
			$unknown = array_values(array_diff(array_map('strval', (array)$config['changedFields']), $properties));
			if ($unknown !== []) {
				throw new FlowLifecycleRefused(
					reason: FlowLifecycleRefused::REASON_UNKNOWN_CHANGED_FIELD,
					flowId: $flowId,
					state: null,
					detail: sprintf(
						'Step "%s" watches %s, which schema "%s" does not have.',
						(string)($node['id'] ?? ''),
						implode(', ', array_map(static fn (string $field): string => '"' . $field . '"', $unknown)),
						(string)$config['schema']
					)
				);
			}
		}
	}//end refuseUnknownChangedFields()

	/**
	 * The config of an object trigger that watches fields, or null.
	 *
	 * @param mixed $node A graph node.
	 *
	 * @return array<string, mixed>|null
	 */
	private function watchingConfig(mixed $node): ?array {
		if (is_array($node) === false || (string)($node['type'] ?? '') !== FlowTriggerDerivation::OBJECT_TRIGGER) {
			return null;
		}

		$config = (array)($node['config'] ?? []);
		if (is_array($config['changedFields'] ?? null) === false || $config['changedFields'] === []) {
			return null;
		}

		return $config + ['register' => '', 'schema' => ''];
	}//end watchingConfig()

	/**
	 * The schema with this slug among the register's schemas, or null.
	 *
	 * @param string $register The register identifier.
	 * @param string $slug     The schema slug.
	 *
	 * @return Schema|null
	 */
	private function schemaOf(string $register, string $slug): ?Schema {
		try {
			$schemaIds = $this->registers->find(id: $register, _rbac: false, _multitenancy: false)->getSchemas();
		} catch (Throwable $e) {
			return null;
		}

		foreach ($schemaIds as $schemaId) {
			try {
				$schema = $this->schemas->find(id: $schemaId, _rbac: false, _multitenancy: false);
			} catch (Throwable $e) {
				continue;
			}

			if (strtolower((string)$schema->getSlug()) === strtolower($slug) || (string)$schema->getId() === $slug || (string)$schema->getUuid() === $slug) {
				return $schema;
			}
		}

		return null;
	}//end schemaOf()
}//end class
