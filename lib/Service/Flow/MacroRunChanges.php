<?php

/**
 * What a macro run changed, read off the run's step trace.
 *
 * The `action.macro` audit entry names the pressed action and its run; this is
 * what lets it also say what the run did ("2 changes: term suspended, message
 * sent") without a reader opening the run. Each completed step that wrote an
 * object, sent a message or created a task counts as one change, named by the
 * step's own label, which the flow's author writes for people. Steps that only
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
 * @spec openspec/changes/macro-run-changes/specs/declared-actions/spec.md#requirement-a-macro-runs-audit-entry-lists-the-changes-it-made
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Summarises the changes in a flow run's log.
 *
 * @spec openspec/changes/macro-run-changes/specs/declared-actions/spec.md#requirement-a-macro-runs-audit-entry-lists-the-changes-it-made
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
	 * @param array<int, mixed> $log The run's step trace (FlowRun::getLog()).
	 *
	 * @return array<int, array{step: string, kind: string}> One entry per completed changing step.
	 *
	 * @spec openspec/changes/macro-run-changes/specs/declared-actions/spec.md#requirement-a-macro-runs-audit-entry-lists-the-changes-it-made
	 */
	public static function fromLog(array $log): array {
		$changes = [];
		foreach ($log as $entry) {
			if (is_array($entry) === false || ($entry['status'] ?? '') !== 'completed') {
				continue;
			}

			$kind = (self::KINDS[(string)($entry['type'] ?? '')] ?? null);
			if ($kind === null) {
				continue;
			}

			$changes[] = [
				'step' => (string)($entry['transition'] ?? ''),
				'kind' => $kind,
			];
		}

		return $changes;
	}//end fromLog()
}//end class
