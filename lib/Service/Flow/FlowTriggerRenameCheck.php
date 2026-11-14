<?php

/**
 * FlowTriggerRenameCheck: a schema rename warns about published triggers that watch the old name.
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

use OCA\OpenRegister\Db\Flow\FlowSelection;
use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Service\Schema\SchemaChangeSet;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Warns when a schema rename leaves a published object trigger watching the old property name.
 *
 * The publish check ({@see FlowTriggerFieldsCheck}) only sees the schema as it
 * is at publish time. A later rename turns a watched field into a name the
 * schema no longer has, and the trigger then never starts. The rename is not
 * refused for that: the warning names each flow and step so the maker can
 * update the trigger.
 *
 * @spec openspec/changes/flow-trigger-transitions-and-changed-fields/specs/flow-engine/spec.md#requirement-an-update-trigger-can-watch-named-fields
 */
class FlowTriggerRenameCheck {

	/**
	 * Flows read per page.
	 */
	private const PAGE = 100;

	/**
	 * Most pages read; beyond this the rest of the flows are not checked.
	 */
	private const MAX_PAGES = 50;

	/**
	 * Constructor.
	 *
	 * @param FlowMapperInterface         $flows     The flows.
	 * @param FlowPublishedGraphInterface $published Reads a flow's published graph.
	 * @param LoggerInterface             $logger    Logs each warning.
	 */
	public function __construct(
		private readonly FlowMapperInterface $flows,
		private readonly FlowPublishedGraphInterface $published,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The published triggers on this schema that watch a property the change set renames.
	 *
	 * @param Schema          $schema    The schema after the update.
	 * @param SchemaChangeSet $changeSet The classified update, with its declared renames.
	 *
	 * @return list<array{flow: string, step: string, field: string, renamedTo: string, message: string}>
	 *
	 * @spec openspec/changes/flow-trigger-transitions-and-changed-fields/specs/flow-engine/spec.md#requirement-an-update-trigger-can-watch-named-fields
	 */
	public function warningsFor(Schema $schema, SchemaChangeSet $changeSet): array {
		$renames = self::renamesOf(changeSet: $changeSet);
		if ($renames === []) {
			return [];
		}

		$warnings = [];
		foreach ($this->flowUuids() as $flowUuid) {
			array_push($warnings, ...$this->warningsForFlow(flowUuid: $flowUuid, schema: $schema, renames: $renames));
		}

		foreach ($warnings as $warning) {
			$this->logger->warning(
				message: '[FlowTriggerRenameCheck] ' . $warning['message'],
				context: ['flow' => $warning['flow'], 'step' => $warning['step'], 'schema_id' => $schema->getId()]
			);
		}

		return $warnings;
	}//end warningsFor()

	/**
	 * The declared renames in a change set, old name to new name.
	 *
	 * @param SchemaChangeSet $changeSet The change set.
	 *
	 * @return array<string, string>
	 */
	private static function renamesOf(SchemaChangeSet $changeSet): array {
		$renames = [];
		foreach ($changeSet->getChanges() as $change) {
			if (($change['kind'] ?? null) === 'renamed' && is_string($change['old'] ?? null) === true && is_string($change['new'] ?? null) === true) {
				$renames[$change['old']] = $change['new'];
			}
		}

		return $renames;
	}//end renamesOf()

	/**
	 * The uuid of every flow, page by page; an unreadable page ends the list.
	 *
	 * @return list<string>
	 */
	private function flowUuids(): array {
		$uuids = [];
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			try {
				$flows = $this->flows->findAllFlows(new FlowSelection(), new ListQuery(limit: self::PAGE, offset: $page * self::PAGE));
			} catch (Throwable $e) {
				$this->logger->warning(
					message: '[FlowTriggerRenameCheck] Could not list the flows; the rename was not checked against them',
					context: ['error' => $e->getMessage()]
				);
				break;
			}

			foreach ($flows as $flow) {
				$uuids[] = (string)$flow->getUuid();
			}

			if (count($flows) < self::PAGE) {
				break;
			}
		}

		return $uuids;
	}//end flowUuids()

	/**
	 * The warnings for one flow's published graph.
	 *
	 * @param string                $flowUuid The flow.
	 * @param Schema                $schema   The renamed schema.
	 * @param array<string, string> $renames  Old name to new name.
	 *
	 * @return list<array{flow: string, step: string, field: string, renamedTo: string, message: string}>
	 */
	private function warningsForFlow(string $flowUuid, Schema $schema, array $renames): array {
		try {
			$graph = $this->published->graphOf(flowId: $flowUuid);
		} catch (Throwable $e) {
			return [];
		}

		$warnings = [];
		foreach ((array)($graph['nodes'] ?? []) as $node) {
			if (is_array($node) === false || (string)($node['type'] ?? '') !== FlowTriggerDerivation::OBJECT_TRIGGER) {
				continue;
			}

			$config = (array)($node['config'] ?? []);
			if (is_array($config['changedFields'] ?? null) === false
				|| self::isSchema(schema: $schema, reference: (string)($config['schema'] ?? '')) === false
			) {
				continue;
			}

			foreach (array_map('strval', $config['changedFields']) as $field) {
				if (isset($renames[$field]) === false) {
					continue;
				}

				$step = (string)($node['id'] ?? '');
				$warnings[] = [
					'flow' => $flowUuid,
					'step' => $step,
					'field' => $field,
					'renamedTo' => $renames[$field],
					'message' => sprintf(
						'Flow "%s" step "%s" watches "%s", which this change renames to "%s"; the trigger will not start until it watches the new name.',
						$flowUuid,
						$step,
						$field,
						$renames[$field]
					),
				];
			}
		}

		return $warnings;
	}//end warningsForFlow()

	/**
	 * Whether a trigger's schema reference names this schema (slug, id or uuid).
	 *
	 * @param Schema $schema    The schema.
	 * @param string $reference The trigger's schema reference.
	 *
	 * @return bool
	 */
	private static function isSchema(Schema $schema, string $reference): bool {
		if ($reference === '') {
			return false;
		}

		return strtolower((string)$schema->getSlug()) === strtolower($reference)
			|| (string)$schema->getId() === $reference
			|| (string)$schema->getUuid() === $reference;
	}//end isSchema()
}//end class
