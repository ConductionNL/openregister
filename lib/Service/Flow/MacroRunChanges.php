<?php

/**
 * What a macro run changed, read off the run's step trace.
 *
 * The `action.macro` audit entry names the pressed action and its run; this is
 * what lets it also say what the run did ("2 changes: term suspended, message
 * sent") without a reader opening the run. Each completed step that wrote an
 * object, sent a message or created a task counts as one change, named by its
 * node's `label` when the flow's author gave one (written for people,
 * "termijn opgeschort"), else by the node's id. A step of another app's
 * node type counts when its author labelled it, as `step`: OpenRegister
 * cannot tell what an app's node does, and the label says it is worth naming.
 * Steps that only
 * read, route or reshape items change nothing and are left out.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/declared-actions/spec.md#requirement-a-macro-runs-audit-entry-lists-the-changes-it-made
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Summarises the changes in a flow run's log.
 *
 * @spec openspec/specs/declared-actions/spec.md#requirement-a-macro-runs-audit-entry-lists-the-changes-it-made
 */
final class MacroRunChanges {

	/**
	 * Step types that change something outside the run, by the kind of change.
	 *
	 * @var array<string, string>
	 */
	private const KINDS = [
		'openregister.object-write'      => 'write',
		'openregister.send-email'        => 'message',
		'openregister.send-notification' => 'message',
		'openregister.send-talk-message' => 'message',
		'openregister.user-task'         => 'task',
		'openregister.portal-task'       => 'task',
	];

	/**
	 * The changes a run made, in the order its steps ran.
	 *
	 * @param array<int, mixed> $log   The run's step trace (FlowRun::getLog()).
	 * @param array<int, mixed> $nodes The flow's nodes, for their labels.
	 *
	 * @return array<int, array{step: string, kind: string}> One entry per completed changing step.
	 *
	 * @spec openspec/specs/declared-actions/spec.md#requirement-a-macro-runs-audit-entry-lists-the-changes-it-made
	 */
	public function fromLog(array $log, array $nodes = []): array {
		$labels  = $this->labelsOf(nodes: $nodes);
		$changes = [];
		foreach ($log as $entry) {
			$change = $this->changeOf(entry: $entry, labels: $labels);
			if ($change !== null) {
				$changes[] = $change;
			}
		}

		return $changes;
	}//end fromLog()

	/**
	 * The labels the flow's author gave its nodes, by node id.
	 *
	 * @param array<int, mixed> $nodes The flow's nodes.
	 *
	 * @return array<string, string> Label by node id.
	 */
	private function labelsOf(array $nodes): array {
		$labels = [];
		foreach ($nodes as $node) {
			if (is_array($node) === true && is_string($node['label'] ?? null) === true && $node['label'] !== '') {
				$labels[(string)($node['id'] ?? '')] = $node['label'];
			}
		}

		return $labels;
	}//end labelsOf()

	/**
	 * The change one logged step made, or null when it changed nothing.
	 *
	 * @param mixed                 $entry  One entry of the run's log.
	 * @param array<string, string> $labels Label by node id.
	 *
	 * @return array{step: string, kind: string}|null The change.
	 */
	private function changeOf(mixed $entry, array $labels): ?array {
		if (is_array($entry) === false || ($entry['status'] ?? '') !== 'completed') {
			return null;
		}

		$node = (string)($entry['transition'] ?? '');
		$kind = (self::KINDS[(string)($entry['type'] ?? '')] ?? null);
		if ($kind === null && isset($labels[$node]) === true) {
			$kind = 'step';
		}

		if ($kind === null) {
			return null;
		}

		return [
			'step' => ($labels[$node] ?? $node),
			'kind' => $kind,
		];
	}//end changeOf()
}//end class
