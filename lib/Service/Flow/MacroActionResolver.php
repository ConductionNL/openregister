<?php

/**
 * Which flow a schema binds to a declared action, and where it leaves you.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
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
 * @spec openspec/changes/macro-flows-with-next-item/specs/declared-actions/spec.md#requirement-a-declared-action-may-run-a-manual-flow-as-a-macro
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use Psr\Log\LoggerInterface;

/**
 * Resolves a macro action against the schema's own declarations.
 *
 * 🔴 READ FROM THE DECLARATIONS, NEVER FROM THE REQUEST. A caller naming an
 * action the schema does not bind gets null here and a refusal from the
 * endpoint, not a flow of their choosing. Keeping that lookup in one object
 * is what stops a second, laxer one appearing beside it.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) `MacroActionBinding::parse()` and
 * `FlowNextHint::declared()` are the two named readers phpmd.xml already
 * excepts by name: both are stateless declaration readers with no
 * collaborators, and several call paths must reach the same answer.
 *
 * @spec openspec/changes/macro-flows-with-next-item/specs/declared-actions/spec.md#requirement-a-declared-action-may-run-a-manual-flow-as-a-macro
 */
class MacroActionResolver {

	/**
	 * Constructor.
	 *
	 * @param SchemaMapper     $schemas    Loads a schema by id or slug.
	 * @param FlowService      $flows      Reads the bound flow, for its `next` hint.
	 * @param AuditTrailMapper $auditTrail Writes the entry that names the action and the run.
	 * @param LoggerInterface  $logger     Diagnostics.
	 */
	public function __construct(
		private readonly SchemaMapper $schemas,
		private readonly FlowService $flows,
		private readonly AuditTrailMapper $auditTrail,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The macro binding a schema declares for this action.
	 *
	 * Read from the DECLARATIONS, never from what the request asked for: a
	 * caller naming an action the schema does not bind gets a refusal, not a
	 * flow of their choosing.
	 *
	 * @param Schema $schema The subject's schema.
	 * @param string $action The action.
	 *
	 * @return MacroActionBinding|null The binding.
	 *
	 * @spec openspec/changes/macro-flows-with-next-item/specs/declared-actions/spec.md#requirement-a-declared-action-may-run-a-manual-flow-as-a-macro
	 */
	public function bindingFor(Schema $schema, string $action): ?MacroActionBinding {
		foreach (MacroActionBinding::parse(configuration: ($schema->getConfiguration() ?? [])) as $binding) {
			if ($binding->action === $action) {
				return $binding;
			}
		}

		return null;
	}//end bindingFor()

	/**
	 * The `next` hint the flow declares.
	 *
	 * @param string $flowUuid The flow.
	 *
	 * @return string One of FlowNextHint::HINTS.
	 *
	 * @spec openspec/changes/macro-flows-with-next-item/specs/declared-actions/spec.md#requirement-a-declared-action-may-run-a-manual-flow-as-a-macro
	 */
	public function nextFor(string $flowUuid): string {
		try {
			return FlowNextHint::declared(nodes: ($this->flows->find(uuid: $flowUuid)->getNodes() ?? []));
		} catch (\Throwable) {
			// A hint nobody can read is `stay`, which is what happened before
			// hints existed and is the only answer that cannot move somebody
			// somewhere they did not ask to go.
			return FlowNextHint::STAY;
		}
	}//end nextFor()

	/**
	 * The `next` hint after a macro ran on a selection.
	 *
	 * After a selection the person is on a list, so `list` unless the flow
	 * declares otherwise; with no bound flow found at all, `list`.
	 *
	 * @param string|null $flowUuid The bound flow, when any object had one.
	 *
	 * @return string One of FlowNextHint::HINTS.
	 *
	 * @spec openspec/changes/macro-flows-with-next-item/specs/declared-actions/spec.md#requirement-a-declared-action-may-run-a-manual-flow-as-a-macro
	 */
	public function nextAfterSelection(?string $flowUuid): string {
		if ($flowUuid === null) {
			return FlowNextHint::LIST;
		}

		return $this->nextFor(flowUuid: $flowUuid);
	}//end nextAfterSelection()

	/**
	 * The EFFECTIVE `next` hint of a finished run.
	 *
	 * The end node the run reached wins when it declares a hint; an end node
	 * that declares nothing is silence, and the manual trigger's hint stands.
	 * The reached end node is the last end-node step in the run's log.
	 *
	 * @param string  $flowUuid The flow.
	 * @param FlowRun $run      The run.
	 *
	 * @return string One of FlowNextHint::HINTS.
	 *
	 * @spec openspec/changes/macro-flows-with-next-item/specs/flow-engine/spec.md#requirement-a-manual-trigger-declares-where-the-person-goes-next
	 */
	public function nextForRun(string $flowUuid, FlowRun $run): string {
		try {
			$nodes = ($this->flows->find(uuid: $flowUuid)->getNodes() ?? []);
		} catch (\Throwable) {
			return FlowNextHint::STAY;
		}

		$reached = null;
		foreach (($run->getLog() ?? []) as $entry) {
			if (is_array($entry) === true && (string)($entry['type'] ?? '') === FlowNextHint::END_NODE) {
				$reached = (string)($entry['transition'] ?? '');
			}
		}

		$endNode = null;
		foreach ($nodes as $node) {
			if ($reached !== null && is_array($node) === true && (string)($node['id'] ?? '') === $reached) {
				$endNode = $node;
				break;
			}
		}

		return FlowNextHint::effective(nodes: $nodes, endNode: $endNode);
	}//end nextForRun()

	/**
	 * The audit entry that ties the pressed action and its run together by name.
	 *
	 * The writes inside the run audit as usual; this row is what lets a reader
	 * of the object's trail see that they came from one pressed action. A
	 * failure to write it is logged and does not undo the run, which has
	 * already happened.
	 *
	 * @param ObjectEntity $object The subject.
	 * @param string       $action The declared action.
	 * @param string       $flow   The bound flow's uuid.
	 * @param string       $run    The run's uuid.
	 * @param string|null  $userId The acting user.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/macro-flows-with-next-item/specs/declared-actions/spec.md#requirement-a-declared-action-may-run-a-manual-flow-as-a-macro
	 */
	public function recordRun(ObjectEntity $object, string $action, string $flow, string $run, ?string $userId): void {
		try {
			$this->auditTrail->createAuditTrailEntry(
				object: $object,
				action: 'action.macro',
				context: ['declaredAction' => $action, 'flow' => $flow, 'run' => $run],
				actorId: $userId
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[MacroActionResolver] Could not write the audit entry for macro "' . $action . '": ' . $e->getMessage(),
				context: ['action' => $action, 'run' => $run]
			);
		}
	}//end recordRun()

	/**
	 * Load a schema by id or slug.
	 *
	 * @param string $schema The schema identifier.
	 *
	 * @return Schema|null The schema.
	 *
	 * @spec openspec/changes/macro-flows-with-next-item/specs/declared-actions/spec.md#requirement-a-declared-action-may-run-a-manual-flow-as-a-macro
	 */
	public function loadSchema(string $schema): ?Schema {
		try {
			return $this->schemas->find($schema, _multitenancy: false, _rbac: false);
		} catch (\Throwable) {
			return null;
		}
	}//end loadSchema()
}//end class
