<?php

/**
 * A VNG Catalogi `zaaktype` becomes a DRAFT case-plan skeleton, with a
 * report of everything it could not map.
 *
 * A pure transformation over a supplied document. No HTTP: fetching from a
 * remote ZTC is an integration concern with credentials and retries that
 * procest already owns; this class takes what someone fetched and returns a
 * definition in the case layer's own format (design D-9).
 *
 * The mapping, per element:
 *
 * - `statustypen`, ordered by `volgnummer`, become milestones in that order.
 *   To give the author something that does not complete itself on import,
 *   each status is wrapped in a stage holding one required human item whose
 *   completion reaches the milestone, and each stage enters when the
 *   previous milestone completes. That wrapping is APPROXIMATE and reported
 *   as such.
 * - `roltypen` become candidate roles; the one whose `omschrijvingGeneriek`
 *   is `behandelaar` is put on the human items, the others are carried in
 *   the settings for the author to place.
 * - `resultaattypen` become the constrained end-state set.
 * - `doorlooptijd` and `servicenorm` are CARRIED onto the items and the
 *   settings, never computed on.
 * - everything else is reported, never dropped silently and never guessed.
 *
 * The output is marked `draft: true`. Importing never makes anything live.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

use OCA\OpenRegister\Db\CaseItem;

/**
 * zaaktype -> draft skeleton + report.
 *
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
 */
class ZaaktypeCaseSkeletonMapper {

	/**
	 * Report statuses.
	 */
	public const MAPPED = 'mapped';

	public const APPROXIMATE = 'approximate';

	public const CARRIED = 'carried';

	public const UNMAPPED = 'unmapped';

	/**
	 * Zaaktype elements the mapping consumes.
	 *
	 * @var array<int, string>
	 */
	private const HANDLED = ['statustypen', 'roltypen', 'resultaattypen', 'doorlooptijd', 'servicenorm'];

	/**
	 * Identity elements that describe the zaaktype itself and are carried as
	 * the plan's name, not reported as unmapped.
	 *
	 * @var array<int, string>
	 */
	private const IDENTITY = [
		'url',
		'uuid',
		'identificatie',
		'omschrijving',
		'omschrijvingGeneriek',
		'catalogus',
		'versiedatum',
		'beginGeldigheid',
		'eindeGeldigheid',
		'concept',
	];

	/**
	 * Produce the draft skeleton and the report.
	 *
	 * @param array<string, mixed> $zaaktype The zaaktype document as stored.
	 *
	 * @return array{draft: bool, definition: array<string, mixed>, report: array<int, array<string, string>>} The result.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	public function map(array $zaaktype): array {
		$report = [];
		$settings = [
			'name' => (string)($zaaktype['omschrijving'] ?? $zaaktype['identificatie'] ?? 'zaaktype'),
			'source' => ['zaaktype' => (string)($zaaktype['url'] ?? $zaaktype['identificatie'] ?? '')],
			'writeThrough' => [
				'statusField' => 'status',
				'statusAtField' => 'statusReachedAt',
				'resultField' => 'resultaat',
				'resultAtField' => 'resultaatReachedAt',
			],
		];
		$report[] = $this->entry(
			element: 'authorization',
			status: self::UNMAPPED,
			reason: 'No zaaktype element says who may administer the plan.',
			action: 'Declare `settings.authorization` (groups, user:<uid>, role:<name>) before publishing; until then only administrators may act.'
		);
		$report[] = $this->entry(
			element: 'writeThrough',
			status: self::APPROXIMATE,
			reason: 'Field names `status`, `statusReachedAt`, `resultaat`, `resultaatReachedAt` were assumed for the write-through.',
			action: 'Check them against the zaak schema; rename or remove the mapping.'
		);

		$behandelaar = $this->mapRoles(zaaktype: $zaaktype, settings: $settings, report: $report);
		$this->mapResults(zaaktype: $zaaktype, settings: $settings, report: $report);
		$terms = $this->mapTerms(zaaktype: $zaaktype, settings: $settings, report: $report);
		$items = $this->mapStatuses(zaaktype: $zaaktype, behandelaar: $behandelaar, terms: $terms, report: $report);
		$this->reportRest(zaaktype: $zaaktype, report: $report);

		return [
			'draft' => true,
			'definition' => ['settings' => $settings, 'items' => $items],
			'report' => $report,
		];
	}//end map()

	/**
	 * `statustypen` in `volgnummer` order -> milestones, each wrapped in a
	 * stage with one human item, chained in sequence.
	 *
	 * @param array<string, mixed> $zaaktype The document.
	 * @param string|null $behandelaar The behandelaar role name, when any.
	 * @param array<string, string|null> $terms doorlooptijd / servicenorm.
	 * @param array<int, array<string, string>> $report The report (by reference).
	 *
	 * @return array<int, array<string, mixed>> The items.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	private function mapStatuses(array $zaaktype, ?string $behandelaar, array $terms, array &$report): array {
		$statuses = ($zaaktype['statustypen'] ?? []);
		if (is_array($statuses) === false || $statuses === []) {
			$report[] = $this->entry(
				element: 'statustypen',
				status: self::UNMAPPED,
				reason: 'The zaaktype carries no statustypen, so no milestones could be produced.',
				action: 'Add milestones by hand.'
			);

			return [];
		}

		$items = [];
		$previousMilestone = null;
		foreach ($this->orderStatuses(statuses: $statuses, report: $report) as $position => $status) {
			$stage = $this->stageFor(status: $status, number: $position + 1, behandelaar: $behandelaar, terms: $terms, previousMilestone: $previousMilestone);
			$items[] = $stage;
			$previousMilestone = $stage['children'][1]['key'];
			$this->reportStatus(status: $status, stage: $stage, report: $report);
		}

		return $items;
	}//end mapStatuses()

	/**
	 * Numbered statuses in `volgnummer` order, unnumbered ones after them
	 * (reported), non-objects dropped (reported).
	 *
	 * @param array<int, mixed> $statuses The statustypen as stored.
	 * @param array<int, array<string, string>> $report The report (by reference).
	 *
	 * @return array<int, array<string, mixed>> The ordered statuses.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	private function orderStatuses(array $statuses, array &$report): array {
		$ordered = [];
		$unnumbered = [];
		foreach ($statuses as $index => $status) {
			if (is_array($status) === false) {
				$report[] = $this->entry(
					element: 'statustypen[' . (int)$index . ']',
					status: self::UNMAPPED,
					reason: 'Not an object.',
					action: 'Correct the source document.'
				);
				continue;
			}

			if (isset($status['volgnummer']) === false || is_numeric($status['volgnummer']) === false) {
				$unnumbered[] = $status;
				$report[] = $this->entry(
					element: 'statustypen[' . (int)$index . ']',
					status: self::APPROXIMATE,
					reason: 'No `volgnummer`; the status was placed after every numbered one.',
					action: 'Move the milestone to its place in the sequence.'
				);
				continue;
			}

			$ordered[] = $status;
		}

		usort($ordered, static fn (array $a, array $b): int => (int)$a['volgnummer'] <=> (int)$b['volgnummer']);

		return array_merge($ordered, $unnumbered);
	}//end orderStatuses()

	/**
	 * One status as a stage holding a placeholder human item and the milestone.
	 *
	 * @param array<string, mixed> $status The statustype.
	 * @param int $number Its position in the sequence, from 1.
	 * @param string|null $behandelaar The behandelaar role, when any.
	 * @param array<string, string|null> $terms doorlooptijd / servicenorm.
	 * @param string|null $previousMilestone The milestone key this stage waits for.
	 *
	 * @return array<string, mixed> The stage node.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	private function stageFor(array $status, int $number, ?string $behandelaar, array $terms, ?string $previousMilestone): array {
		$label = (string)($status['omschrijving'] ?? ('status ' . $number));
		$slug = $this->slug(text: $label);
		$stageKey = sprintf('fase-%d-%s', $number, $slug);
		$taskKey = sprintf('behandel-%d-%s', $number, $slug);
		$milestoneKey = sprintf('status-%d-%s', $number, $slug);

		$task = [
			'key' => $taskKey,
			'type' => CaseItem::TYPE_HUMAN_TASK,
			'name' => sprintf('Behandelen: %s', $label),
			'required' => true,
			'doorlooptijd' => $terms['doorlooptijd'],
			'servicenorm' => $terms['servicenorm'],
		];
		if ($behandelaar !== null) {
			$task['candidateRole'] = $behandelaar;
		}

		$entry = [];
		if ($previousMilestone !== null) {
			$entry = [['id' => $stageKey . ':entry', 'on' => ['event' => 'case.item.completed', 'item' => $previousMilestone]]];
		}

		return [
			'key' => $stageKey,
			'type' => CaseItem::TYPE_STAGE,
			'name' => $label,
			'required' => true,
			'entryCriteria' => $entry,
			'children' => [
				$task,
				[
					'key' => $milestoneKey,
					'type' => CaseItem::TYPE_MILESTONE,
					'name' => $label,
					'required' => true,
					'entryCriteria' => [
						['id' => $milestoneKey . ':entry', 'on' => ['event' => 'case.item.completed', 'item' => $taskKey]],
					],
				],
			],
		];
	}//end stageFor()

	/**
	 * Report the approximate wrapping of one status, and every attribute of
	 * it that has no counterpart.
	 *
	 * @param array<string, mixed> $status The statustype.
	 * @param array<string, mixed> $stage The stage it became.
	 * @param array<int, array<string, string>> $report The report (by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	private function reportStatus(array $status, array $stage, array &$report): void {
		$number = (string)($status['volgnummer'] ?? '-');
		$report[] = $this->entry(
			element: sprintf('statustypen (volgnummer %s)', $number),
			status: self::APPROXIMATE,
			reason: sprintf(
				"Milestone '%s' was wrapped in stage '%s' with one required human item '%s' so the sequence does not complete itself on import.",
				(string)$stage['children'][1]['key'],
				(string)$stage['key'],
				(string)$stage['children'][0]['key']
			),
			action: 'Replace the placeholder human item with the real work of this phase, or drop the stage and give the milestone a real entry criterion.'
		);

		foreach (['statustekst', 'informeren', 'toelichting', 'doorlooptijd', 'checklistitemStatustype'] as $extra) {
			if (isset($status[$extra]) === true && $status[$extra] !== '') {
				$report[] = $this->entry(
					element: sprintf('statustypen (volgnummer %s).%s', $number, $extra),
					status: self::UNMAPPED,
					reason: 'A statustype attribute with no counterpart on a milestone.',
					action: 'Carry it in the milestone description if it matters to the caseworker.'
				);
			}
		}
	}//end reportStatus()

	/**
	 * `roltypen` -> candidate roles; the behandelaar goes on the human items.
	 *
	 * @param array<string, mixed> $zaaktype The document.
	 * @param array<string, mixed> $settings The settings (by reference).
	 * @param array<int, array<string, string>> $report The report (by reference).
	 *
	 * @return string|null The behandelaar role name, when one is declared.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	private function mapRoles(array $zaaktype, array &$settings, array &$report): ?string {
		$roles = ($zaaktype['roltypen'] ?? []);
		if (is_array($roles) === false || $roles === []) {
			return null;
		}

		$behandelaar = null;
		$settings['candidateRoles'] = [];
		foreach ($roles as $index => $role) {
			if (is_array($role) === false) {
				$report[] = $this->entry(
					element: 'roltypen[' . (int)$index . ']',
					status: self::UNMAPPED,
					reason: 'Not an object.',
					action: 'Correct the source document.',
				);
				continue;
			}

			$name = trim((string)($role['omschrijving'] ?? ''));
			$generic = trim((string)($role['omschrijvingGeneriek'] ?? ''));
			if ($name === '') {
				$report[] = $this->entry(
					element: 'roltypen[' . (int)$index . ']',
					status: self::UNMAPPED,
					reason: 'No `omschrijving`.',
					action: 'Name the role.',
				);
				continue;
			}

			$settings['candidateRoles'][] = ['role' => $name, 'generic' => $generic];
			if ($generic === 'behandelaar' && $behandelaar === null) {
				$behandelaar = $name;
				$report[] = $this->entry(
					element: sprintf("roltypen '%s'", $name),
					status: self::MAPPED,
					reason: 'The behandelaar role became the candidate role of every human item.',
					action: sprintf("Make sure a group named '%s' exists, or the role will not resolve.", $name)
				);
				continue;
			}

			$report[] = $this->entry(
				element: sprintf("roltypen '%s'", $name),
				status: self::CARRIED,
				reason: sprintf("Carried in settings.candidateRoles with generic designation '%s'; not placed on any item.", $generic),
				action: 'Assign it as candidateRole to the items this role performs.'
			);
		}//end foreach

		return $behandelaar;
	}//end mapRoles()

	/**
	 * `resultaattypen` -> the constrained end-state set; archival terms carried.
	 *
	 * @param array<string, mixed> $zaaktype The document.
	 * @param array<string, mixed> $settings The settings (by reference).
	 * @param array<int, array<string, string>> $report The report (by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	private function mapResults(array $zaaktype, array &$settings, array &$report): void {
		$results = ($zaaktype['resultaattypen'] ?? []);
		if (is_array($results) === false || $results === []) {
			$report[] = $this->entry(
				element: 'resultaattypen',
				status: self::UNMAPPED,
				reason: 'No resultaattypen, so the case has no constrained end-state set.',
				action: 'Declare `settings.results` by hand, or any result will be refused.'
			);

			return;
		}

		$settings['results'] = [];
		$settings['resultMetadata'] = [];
		foreach ($results as $index => $result) {
			$name = '';
			if (is_array($result) === true) {
				$name = trim((string)($result['omschrijving'] ?? ''));
			}

			if ($name === '') {
				$report[] = $this->entry(
					element: 'resultaattypen[' . (int)$index . ']',
					status: self::UNMAPPED,
					reason: 'No `omschrijving`.',
					action: 'Name the result.',
				);
				continue;
			}

			$settings['results'][] = $name;
			$metadata = [];
			foreach (['archiefnominatie', 'archiefactietermijn', 'brondatumArchiefprocedure', 'selectielijstklasse'] as $term) {
				if (isset($result[$term]) === true) {
					$metadata[$term] = $result[$term];
				}
			}

			if ($metadata !== []) {
				$settings['resultMetadata'][$name] = $metadata;
				$report[] = $this->entry(
					element: sprintf("resultaattypen '%s' archival terms", $name),
					status: self::CARRIED,
					reason: 'Archival terms are carried as metadata; the archival capabilities act on them, not the case layer.',
					action: 'Nothing here; configure archival separately.'
				);
			}
		}//end foreach

		$report[] = $this->entry(
			element: 'resultaattypen',
			status: self::MAPPED,
			reason: sprintf('%d result(s) became the constrained end-state set.', count($settings['results'])),
			action: 'Completing the case with any other result is refused.'
		);
	}//end mapResults()

	/**
	 * `doorlooptijd` / `servicenorm`: carried, not computed on.
	 *
	 * @param array<string, mixed> $zaaktype The document.
	 * @param array<string, mixed> $settings The settings (by reference).
	 * @param array<int, array<string, string>> $report The report (by reference).
	 *
	 * @return array<string, string|null> The two terms.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	private function mapTerms(array $zaaktype, array &$settings, array &$report): array {
		$terms = [];
		foreach (['doorlooptijd', 'servicenorm'] as $term) {
			$value = trim((string)($zaaktype[$term] ?? ''));
			$terms[$term] = null;
			if ($value === '') {
				continue;
			}

			$terms[$term] = $value;
			$settings[$term] = $value;
			$report[] = $this->entry(
				element: $term,
				status: self::CARRIED,
				reason: sprintf("'%s' is carried onto every human item and the settings; nothing computes on it here.", $value),
				action: 'flow-business-timers acts on it; nothing to do in the case plan.'
			);
		}

		return $terms;
	}//end mapTerms()

	/**
	 * Everything not handled is reported, never dropped silently.
	 *
	 * @param array<string, mixed> $zaaktype The document.
	 * @param array<int, array<string, string>> $report The report (by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	private function reportRest(array $zaaktype, array &$report): void {
		foreach ($zaaktype as $element => $value) {
			$name = (string)$element;
			if (in_array($name, self::HANDLED, true) === true || in_array($name, self::IDENTITY, true) === true) {
				continue;
			}

			if ($value === null || $value === '' || $value === []) {
				continue;
			}

			$report[] = $this->entry(
				element: $name,
				status: self::UNMAPPED,
				reason: 'The case-plan mapping has no counterpart for this zaaktype element.',
				action: 'Decide whether it belongs on the zaak schema, on a plan item, or nowhere; it was not carried.'
			);
		}
	}//end reportRest()

	/**
	 * One report line.
	 *
	 * @param string $element What.
	 * @param string $status mapped | approximate | carried | unmapped.
	 * @param string $reason Why.
	 * @param string $action What the author should do.
	 *
	 * @return array<string, string> The line.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	private function entry(string $element, string $status, string $reason, string $action): array {
		return ['element' => $element, 'status' => $status, 'reason' => $reason, 'action' => $action];
	}//end entry()

	/**
	 * A key-safe slug of a label.
	 *
	 * @param string $text The label.
	 *
	 * @return string Lower-case letters, digits and dashes.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	private function slug(string $text): string {
		$slug = strtolower(trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', $text), '-'));
		if ($slug === '') {
			return 'status';
		}

		return substr($slug, 0, 60);
	}//end slug()
}//end class
