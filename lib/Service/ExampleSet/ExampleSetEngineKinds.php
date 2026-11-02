<?php

/**
 * OpenRegister Example Set Engine Kinds
 *
 * The kinds of row an example set writes outside the registers (tasks, saved
 * views), handled together by the installer and the remover: checked,
 * written, proved and deleted the same way. A kind this OpenRegister cannot
 * write refuses the rows that declare it, so nothing is skipped silently.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * Tasks and saved views of an example set, together.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) It holds one writer per fragment part; each is a separate kind, so the count grows with the parts.
 */
class ExampleSetEngineKinds {
	/**
	 * The parts written after a fragment's objects and before its changes, so a change may quote one
	 * (`{{flow:key.uuid}}`, `{{accessLink:key.url}}`).
	 */
	public const BEFORE_CHANGES = ['flows', 'accessLinks', 'grants'];

	/**
	 * Constructor.
	 *
	 * @param ExampleSetTasks|null $tasks Writes the tasks a fragment puts on its objects; null refuses them.
	 * @param ExampleSetViews|null $views Writes the saved views a fragment declares; null refuses them.
	 * @param ExampleSetWatchers|null $watchers Writes the follows a fragment declares; null refuses them.
	 * @param ExampleSetParties|null $parties Writes the party links a fragment declares; null refuses them.
	 * @param ExampleSetNotes|null $notes Writes the notes a fragment leaves on its objects; null refuses them.
	 * @param ExampleSetReadStates|null $readStates Writes the objects a fragment's accounts opened; null refuses them.
	 * @param ExampleSetFlows|null $flows Writes the flows a fragment ships; null refuses them.
	 * @param ExampleSetExportRuns|null $exportRuns Writes the exports a fragment gives its accounts; null refuses them.
	 * @param ExampleSetArchival|null $archival Writes the destruction-list keys of the archival setting; null refuses them.
	 * @param ExampleSetDeckCards|null $deckCards Writes the Deck cards a fragment puts on its objects; null refuses them.
	 * @param ExampleSetAppointments|null $appointments Writes the appointments a fragment puts on its objects; null refuses them.
	 * @param ExampleSetCasePlans|null $casePlans Writes the case plans a fragment puts on its objects; null refuses them.
	 * @param ExampleSetCollectivesPages|null $collectivesPages Writes the Collectives pages a fragment ships; null refuses them.
	 * @param ExampleSetTalkConversations|null $talkConversations Writes the Talk conversations a fragment puts on its objects; null refuses them.
	 * @param ExampleSetAuditEntries|null $auditEntries Writes the history a fragment gives its objects; null refuses it.
	 * @param ExampleSetTimelineEntries|null $timelineEntries Writes the timeline a fragment gives its objects; null refuses it.
	 * @param ExampleSetEmailLinks|null $emailLinks Writes the mail a fragment links to its objects; null refuses it.
	 * @param ExampleSetAccessLinks|null $accessLinks Mints the access links a fragment puts on its objects; null refuses them.
	 * @param ExampleSetGrants|null $grants Makes the grants a fragment puts on its objects; null refuses them.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) One optional writer per fragment part, so a part an OpenRegister lacks is refused, not crashed on.
	 */
	public function __construct(
		private readonly ?ExampleSetTasks $tasks=null,
		private readonly ?ExampleSetViews $views=null,
		private readonly ?ExampleSetWatchers $watchers=null,
		private readonly ?ExampleSetParties $parties=null,
		private readonly ?ExampleSetNotes $notes=null,
		private readonly ?ExampleSetReadStates $readStates=null,
		private readonly ?ExampleSetFlows $flows=null,
		private readonly ?ExampleSetExportRuns $exportRuns=null,
		private readonly ?ExampleSetArchival $archival=null,
		private readonly ?ExampleSetDeckCards $deckCards=null,
		private readonly ?ExampleSetAppointments $appointments=null,
		private readonly ?ExampleSetCasePlans $casePlans=null,
		private readonly ?ExampleSetCollectivesPages $collectivesPages=null,
		private readonly ?ExampleSetTalkConversations $talkConversations=null,
		private readonly ?ExampleSetAuditEntries $auditEntries=null,
		private readonly ?ExampleSetTimelineEntries $timelineEntries=null,
		private readonly ?ExampleSetEmailLinks $emailLinks=null,
		private readonly ?ExampleSetAccessLinks $accessLinks=null,
		private readonly ?ExampleSetGrants $grants=null,
	) {
	}//end __construct()

	/**
	 * Check the tasks and views of one fragment.
	 *
	 * @param array<string, mixed> $fragment The fragment.
	 * @param array<string, mixed> $context  The context placeholders draw on.
	 * @param array<int, string>   $users    The account ids the set creates or adopts.
	 * @param ExampleSetValues     $values   Fills placeholders.
	 *
	 * @return array<int, string> Refusals, also for each row of a kind this OpenRegister cannot write.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	public function check(array $fragment, array $context, array $users, ExampleSetValues $values): array {
		$refusals = $this->addressRefusals(fragment: $fragment);
		$context['tasks'] = $values->tasksOf(fragment: $fragment, record: null);
		foreach ($this->kinds() as $part => [$rows, $noun]) {
			if ($rows === null) {
				foreach (($fragment[$part] ?? []) as $entry) {
					$refusals[] = $fragment['file'].': '.$noun.' "'.$entry['key'].'": this OpenRegister cannot write '.$part;
				}

				continue;
			}

			$refusals = array_merge($refusals, $rows->checkFragment(fragment: $fragment, context: $context, users: $users, values: $values));
		}

		return $refusals;
	}//end check()

	/**
	 * Where a fragment quotes an access link's address (`{{accessLink:key.url}}`) other than as the text of a change's
	 * `set` field: only there does the record keep it as a fingerprint, so anywhere else it is refused.
	 *
	 * @param array<string, mixed> $fragment The fragment.
	 *
	 * @return array<int, string> One refusal per part that quotes it elsewhere.
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-change-may-quote-an-access-link-of-its-fragment
	 */
	private function addressRefusals(array $fragment): array {
		$parts = array_diff_key($fragment, ['app' => true, 'file' => true, 'dir' => true, 'changes' => true]);
		foreach ((array) ($fragment['changes'] ?? []) as $index => $change) {
			$parts['changes'][$index] = array_diff_key((array) $change, ['set' => true]);
			foreach ((array) ($change['set'] ?? []) as $path => $value) {
				if (is_string($value) === false) {
					$parts['changes'][$index]['set'][$path] = $value;
				}
			}
		}

		$refusals = [];
		foreach ($parts as $part => $declared) {
			if ($this->quotesAddress(value: $declared) === true) {
				$refusals[] = $fragment['file'].': '.$part.' quotes an access link address ({{accessLink:key.url}}); '
					.'only a change may, as the text of a set field';
			}
		}

		return $refusals;
	}//end addressRefusals()

	/**
	 * Whether a declared value holds `{{accessLink:key.url}}` anywhere inside it.
	 *
	 * @param mixed $value The declared value.
	 *
	 * @return bool
	 */
	private function quotesAddress(mixed $value): bool {
		if (is_string($value) === true) {
			return preg_match('/\{\{\s*accessLink:[^}]*\.url\s*\}\}/', $value) === 1;
		}

		if (is_array($value) === true) {
			foreach ($value as $key => $item) {
				if ($this->quotesAddress(value: $key) === true || $this->quotesAddress(value: $item) === true) {
					return true;
				}
			}
		}

		return false;
	}//end quotesAddress()

	/**
	 * Write the tasks and views of one fragment that the record does not hold.
	 *
	 * @param array<string, mixed> $fragment The fragment.
	 * @param array<string, mixed> $context  The context placeholders draw on.
	 * @param array<string, mixed> $report   The report; gains `tasks`, `views` and `dropped`.
	 * @param array<string, mixed> $record   The record; gains the rows written.
	 * @param ExampleSetValues     $values   Fills placeholders.
	 *
	 * @return bool Whether the record changed and is to be written.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	public function install(array $fragment, array $context, array &$report, array &$record, ExampleSetValues $values): bool {
		$changed = false;
		foreach ($this->kinds() as $part => [$rows]) {
			// Written earlier, by installBeforeChanges(), so a change may quote one.
			if (in_array($part, self::BEFORE_CHANGES, true) === true) {
				continue;
			}

			if ($rows?->installFragment(fragment: $fragment, context: $context, report: $report, record: $record, values: $values) === true) {
				$changed = true;
			}

			// The parts after the tasks may quote one (`{{task:key.uuid}}`).
			if ($part === 'tasks') {
				$context['tasks'] = $values->tasksOf(fragment: $fragment, record: $record);
			}
		}

		return $changed;
	}//end install()

	/**
	 * Write the flows, access links and grants of one fragment that the record does not hold, before its changes, so
	 * a change may quote one (`{{flow:key.uuid}}`, `{{accessLink:key.url}}`).
	 *
	 * @param array<string, mixed> $fragment The fragment.
	 * @param array<string, mixed> $context  The context placeholders draw on.
	 * @param array<string, mixed> $report   The report; gains `flows`, `accessLinks` and `grants`.
	 * @param array<string, mixed> $record   The record; gains the rows written.
	 * @param ExampleSetValues     $values   Fills placeholders.
	 *
	 * @return bool Whether the record changed and is to be written.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-change-may-quote-a-flow-of-its-fragment
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-change-may-quote-an-access-link-of-its-fragment
	 */
	public function installBeforeChanges(array $fragment, array $context, array &$report, array &$record, ExampleSetValues $values): bool {
		$changed = false;
		$kinds   = $this->kinds();
		foreach (self::BEFORE_CHANGES as $part) {
			if ($kinds[$part][0]?->installFragment(fragment: $fragment, context: $context, report: $report, record: $record, values: $values) === true) {
				$changed = true;
			}
		}

		return $changed;
	}//end installBeforeChanges()

	/**
	 * The access links a fragment declares, by key, for `{{accessLink:key.id|uuid|url}}`: as minted, or stand-ins
	 * for the check (`$record` null).
	 *
	 * @param array<string, mixed>      $fragment The fragment.
	 * @param array<string, mixed>|null $record   The set's record, or null for the check.
	 *
	 * @return array<string, array{id: int, uuid: string, url: string}> None when this OpenRegister cannot mint links.
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-change-may-quote-an-access-link-of-its-fragment
	 */
	public function accessLinksOf(array $fragment, ?array $record): array {
		return ($this->accessLinks?->placeholders(fragment: $fragment, record: $record) ?? []);
	}//end accessLinksOf()

	/**
	 * Read back the tasks and views the record holds.
	 *
	 * @param array<string, mixed> $report The report so far.
	 * @param array<string, mixed> $record The record as written.
	 *
	 * @return array<string, mixed> The report with each row that is gone under `missing`.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	public function prove(array $report, array $record): array {
		foreach ($this->kinds() as $part => [$rows, $noun]) {
			foreach (($record[$part] ?? []) as $name => $entry) {
				if ($rows !== null && $rows->lost(entry: $entry) === true) {
					$report['missing'][] = $noun.' '.$name;
				}
			}
		}

		return $report;
	}//end prove()

	/**
	 * Delete the tasks and views the record holds, newest first.
	 *
	 * @param array<string, mixed> $record  The record; loses each row deleted.
	 * @param array<string, mixed> $report  The report; gains counts and failures.
	 * @param callable             $written Called after each deletion, so the record is kept.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-the-tasks-a-set-wrote
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-the-views-a-set-wrote
	 */
	public function delete(array &$record, array &$report, callable $written): void {
		foreach ($this->kinds() as $part => [$rows, $noun]) {
			foreach (array_reverse(($record[$part] ?? []), true) as $name => $entry) {
				if ($rows === null) {
					$report['failed'][] = $noun.' '.$name.': this OpenRegister cannot delete '.$part;
					continue;
				}

				if ($rows->revert(entry: $entry) === 'failed') {
					$report['failed'][] = $noun.' '.$name;
					continue;
				}

				unset($record[$part][$name]);
				$report['removed'][$part] = (($report['removed'][$part] ?? 0) + 1);
				$written($record);
			}
		}
	}//end delete()

	/**
	 * Each kind by its part: the writer (null when absent) and the word for one row.
	 *
	 * @return array<string, array{0: ExampleSetEngineRows|null, 1: string}>
	 */
	private function kinds(): array {
		return [
			'tasks'      => [$this->tasks, 'task'],
			'views'      => [$this->views, 'view'],
			'watchers'   => [$this->watchers, 'watcher'],
			'parties'    => [$this->parties, 'party'],
			'notes'      => [$this->notes, 'note'],
			'readStates' => [$this->readStates, 'read state'],
			'flows'      => [$this->flows, 'flow'],
			'exportRuns' => [$this->exportRuns, 'export'],
			'archival'   => [$this->archival, 'archival setting'],
			'deckCards'  => [$this->deckCards, 'deck card'],
			'appointments' => [$this->appointments, 'appointment'],
			'casePlans'  => [$this->casePlans, 'case plan'],
			'collectivesPages' => [$this->collectivesPages, 'collectives page'],
			'talkConversations' => [$this->talkConversations, 'talk conversation'],
			'auditEntries' => [$this->auditEntries, 'audit entry'],
			'timelineEntries' => [$this->timelineEntries, 'timeline entry'],
			'emailLinks' => [$this->emailLinks, 'email link'],
			'accessLinks' => [$this->accessLinks, 'access link'],
			'grants'     => [$this->grants, 'grant'],
		];
	}//end kinds()
}//end class
