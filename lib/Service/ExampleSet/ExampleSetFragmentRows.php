<?php

/**
 * OpenRegister Example Set Fragment Rows
 *
 * The rows of a fragment's engine parts (views, tasks, flows) that its
 * placeholders quote: as the set's record holds them after the install wrote
 * them, or stand-ins for the check, since none is written yet.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-every-placeholder-resolves-or-the-set-is-refused-req-exs-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * The rows a fragment's placeholders quote, by key.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-every-placeholder-resolves-or-the-set-is-refused-req-exs-002
 */
class ExampleSetFragmentRows {
	/**
	 * The views a fragment declares, by key.
	 *
	 * @param array<string, mixed>      $fragment The fragment.
	 * @param array<string, mixed>|null $record   The set's record, or null for the check.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-preference-may-quote-a-view-of-its-fragment
	 */
	public function viewsOf(array $fragment, ?array $record): array {
		return $this->writtenRowsOf(fragment: $fragment, part: ['part' => 'views', 'noun' => 'view', 'record' => $record]);
	}//end viewsOf()

	/**
	 * The tasks a fragment declares, by key.
	 *
	 * @param array<string, mixed>      $fragment The fragment.
	 * @param array<string, mixed>|null $record   The set's record, or null for the check.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-note-or-read-state-may-quote-a-task-of-its-fragment
	 */
	public function tasksOf(array $fragment, ?array $record): array {
		return $this->writtenRowsOf(fragment: $fragment, part: ['part' => 'tasks', 'noun' => 'task', 'record' => $record]);
	}//end tasksOf()

	/**
	 * The flows a fragment declares, by key; a stand-in names its keyed runs, and the step each waiting one waits on.
	 *
	 * @param array<string, mixed>      $fragment The fragment.
	 * @param array<string, mixed>|null $record   The set's record, or null for the check.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-change-may-quote-a-flow-of-its-fragment
	 */
	public function flowsOf(array $fragment, ?array $record): array {
		$flows = $this->writtenRowsOf(fragment: $fragment, part: ['part' => 'flows', 'noun' => 'flow', 'record' => $record]);
		if ($record !== null) {
			return $flows;
		}

		foreach (($fragment['flows'] ?? []) as $flow) {
			$flows = $this->withStandInRuns(flows: $flows, flow: $flow);
		}

		return $flows;
	}//end flowsOf()

	/**
	 * The stand-in flows with the stand-in uuid of each keyed run of one declared flow, and the step each waiting run
	 * waits on.
	 *
	 * @param array<string, array<string, mixed>> $flows The stand-in flows, by key.
	 * @param mixed                               $flow  One declared flow.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function withStandInRuns(array $flows, mixed $flow): array {
		foreach ($this->keyedRuns(flow: $flow) as $run) {
			$standIn = 'example-set-stand-in:run:'.$flow['key'].'.'.$run['key'];
			$flows[(string) $flow['key']]['runs'][$run['key']] = $standIn;
			if (($run['status'] ?? null) === 'suspended') {
				$flows[(string) $flow['key']]['waitsOn'][$standIn] = (string) ($run['node'] ?? '');
			}
		}

		return $flows;
	}//end withStandInRuns()

	/**
	 * The runs a declared flow carries that have a key.
	 *
	 * @param mixed $flow One declared flow.
	 *
	 * @return array<int|string, array<string, mixed>>
	 */
	private function keyedRuns(mixed $flow): array {
		$runs = array_map(static fn (mixed $run): array => (array) $run, (array) ($flow['data']['runs'] ?? []));

		return array_filter($runs, static fn (array $run): bool => is_string($run['key'] ?? null) === true);
	}//end keyedRuns()

	/**
	 * The rows of one engine part a fragment declares, by key: as the record holds them, or stand-ins for the check.
	 *
	 * @param array<string, mixed>                                            $fragment The fragment.
	 * @param array{part: string, noun: string, record: array<string, mixed>|null} $part     The fragment part, such as
	 *                                                                                  `tasks`, the word for one row
	 *                                                                                  in a stand-in uuid, and the
	 *                                                                                  set's record (null for the
	 *                                                                                  check).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function writtenRowsOf(array $fragment, array $part): array {
		$rows = [];
		foreach (($fragment[$part['part']] ?? []) as $row) {
			$key     = (string) $row['key'];
			$written = $this->writtenRow(fragment: $fragment, part: ['key' => $key] + $part);
			if ($written !== null) {
				$rows[$key] = $written;
			}
		}

		return $rows;
	}//end writtenRowsOf()

	/**
	 * One row of an engine part: as the record holds it, a stand-in for the check, or null when the record holds none.
	 *
	 * @param array<string, mixed> $fragment The fragment.
	 * @param array<string, mixed> $part     As for writtenRowsOf(), with the row's `key`.
	 *
	 * @return array<string, mixed>|null
	 */
	private function writtenRow(array $fragment, array $part): ?array {
		if ($part['record'] === null) {
			return ['uuid' => 'example-set-stand-in:'.$part['noun'].':'.$part['key']];
		}

		$written = ($part['record'][$part['part']][$fragment['app'].'/'.$part['key']] ?? null);
		if (is_array($written) === true) {
			return $written;
		}

		return null;
	}//end writtenRow()
}//end class
