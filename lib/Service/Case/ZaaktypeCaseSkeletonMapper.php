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
 * @spec openspec/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

/**
 * zaaktype -> draft skeleton + report.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
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
	 * Statustype attributes that have no counterpart on a milestone.
	 *
	 * @var array<int, string>
	 */
	private const STATUS_EXTRAS = ['statustekst', 'informeren', 'toelichting', 'doorlooptijd', 'checklistitemStatustype'];

	/**
	 * Resultaattype archival terms carried as result metadata.
	 *
	 * @var array<int, string>
	 */
	private const ARCHIVAL_TERMS = ['archiefnominatie', 'archiefactietermijn', 'brondatumArchiefprocedure', 'selectielijstklasse'];

	/**
	 * The fixed report lines: status, reason, action.
	 */
	private const NOTE_AUTHORIZATION = [
		self::UNMAPPED,
		'No zaaktype element says who may administer the plan.',
		'Declare `settings.authorization` (groups, user:<uid>, role:<name>) before publishing; until then only administrators may act.',
	];

	private const NOTE_WRITE_THROUGH = [
		self::APPROXIMATE,
		'Field names `status`, `statusReachedAt`, `resultaat`, `resultaatReachedAt` were assumed for the write-through.',
		'Check them against the zaak schema; rename or remove the mapping.',
	];

	private const NOTE_NO_STATUSES = [
		self::UNMAPPED,
		'The zaaktype carries no statustypen, so no milestones could be produced.',
		'Add milestones by hand.',
	];

	public const NOTE_NOT_OBJECT = [self::UNMAPPED, 'Not an object.', 'Correct the source document.'];

	private const NOTE_NO_VOLGNUMMER = [
		self::APPROXIMATE,
		'No `volgnummer`; the status was placed after every numbered one.',
		'Move the milestone to its place in the sequence.',
	];

	private const NOTE_STATUS_EXTRA = [
		self::UNMAPPED,
		'A statustype attribute with no counterpart on a milestone.',
		'Carry it in the milestone description if it matters to the caseworker.',
	];

	private const NOTE_NO_RESULTS = [
		self::UNMAPPED,
		'No resultaattypen, so the case has no constrained end-state set.',
		'Declare `settings.results` by hand, or any result will be refused.',
	];

	private const NOTE_RESULT_UNNAMED = [self::UNMAPPED, 'No `omschrijving`.', 'Name the result.'];

	private const NOTE_ARCHIVAL = [
		self::CARRIED,
		'Archival terms are carried as metadata; the archival capabilities act on them, not the case layer.',
		'Nothing here; configure archival separately.',
	];

	private const NOTE_REST = [
		self::UNMAPPED,
		'The case-plan mapping has no counterpart for this zaaktype element.',
		'Decide whether it belongs on the zaak schema, on a plan item, or nowhere; it was not carried.',
	];

	/**
	 * Constructor.
	 *
	 * @param ZaaktypeStageBuilder $stages Builds the stage, human item and milestone of each status.
	 * @param ZaaktypeRoleMapper   $roles  Maps the roltypen onto candidate roles.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	public function __construct(
		private readonly ZaaktypeStageBuilder $stages = new ZaaktypeStageBuilder(),
		private readonly ZaaktypeRoleMapper $roles = new ZaaktypeRoleMapper(),
	) {
	}//end __construct()

	/**
	 * Produce the draft skeleton and the report.
	 *
	 * @param array<string, mixed> $zaaktype The zaaktype document as stored.
	 *
	 * @return array{draft: bool, definition: array<string, mixed>, report: array<int, array<string, string>>} The result.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	public function map(array $zaaktype): array {
		$draft = new ZaaktypeSkeletonDraft(settings: self::baseSettings(zaaktype: $zaaktype));
		$draft->note(element: 'authorization', line: self::NOTE_AUTHORIZATION);
		$draft->note(element: 'writeThrough', line: self::NOTE_WRITE_THROUGH);

		$this->roles->mapRoles(zaaktype: $zaaktype, draft: $draft);
		$this->mapResults(zaaktype: $zaaktype, draft: $draft);
		$this->mapTerms(zaaktype: $zaaktype, draft: $draft);
		$this->mapStatuses(zaaktype: $zaaktype, draft: $draft);
		$this->reportRest(zaaktype: $zaaktype, draft: $draft);

		return $draft->result();
	}//end map()

	/**
	 * The plan settings every skeleton starts from: name, source and the assumed write-through.
	 *
	 * @param array<string, mixed> $zaaktype The document.
	 *
	 * @return array<string, mixed> The settings.
	 */
	private static function baseSettings(array $zaaktype): array {
		return [
			'name' => (string)($zaaktype['omschrijving'] ?? $zaaktype['identificatie'] ?? 'zaaktype'),
			'source' => ['zaaktype' => (string)($zaaktype['url'] ?? $zaaktype['identificatie'] ?? '')],
			'writeThrough' => [
				'statusField' => 'status',
				'statusAtField' => 'statusReachedAt',
				'resultField' => 'resultaat',
				'resultAtField' => 'resultaatReachedAt',
			],
		];
	}//end baseSettings()

	/**
	 * `statustypen` in `volgnummer` order -> milestones, each wrapped in a
	 * stage with one human item, chained in sequence.
	 *
	 * @param array<string, mixed>  $zaaktype The document.
	 * @param ZaaktypeSkeletonDraft $draft    The skeleton being built.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	private function mapStatuses(array $zaaktype, ZaaktypeSkeletonDraft $draft): void {
		$statuses = ($zaaktype['statustypen'] ?? []);
		if (is_array($statuses) === false || $statuses === []) {
			$draft->note(element: 'statustypen', line: self::NOTE_NO_STATUSES);
			return;
		}

		foreach ($this->orderStatuses(statuses: $statuses, draft: $draft) as $status) {
			$draft->items[] = $this->stages->stageFor(status: $status, draft: $draft);
			$this->reportStatus(status: $status, draft: $draft);
		}
	}//end mapStatuses()

	/**
	 * Numbered statuses in `volgnummer` order, unnumbered ones after them
	 * (reported), non-objects dropped (reported).
	 *
	 * @param array<int, mixed>     $statuses The statustypen as stored.
	 * @param ZaaktypeSkeletonDraft $draft    The skeleton being built.
	 *
	 * @return array<int, array<string, mixed>> The ordered statuses.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	private function orderStatuses(array $statuses, ZaaktypeSkeletonDraft $draft): array {
		foreach ($statuses as $index => $status) {
			$note = self::statusShapeNote(status: $status);
			if ($note !== null) {
				$draft->note(element: 'statustypen[' . (int)$index . ']', line: $note);
			}
		}

		$numbered   = array_values(array_filter($statuses, static fn (mixed $status): bool => self::isNumbered(status: $status)));
		$unnumbered = array_values(
			array_filter($statuses, static fn (mixed $status): bool => is_array($status) === true && self::isNumbered(status: $status) === false)
		);
		usort($numbered, static fn (array $a, array $b): int => (int)$a['volgnummer'] <=> (int)$b['volgnummer']);

		return array_merge($numbered, $unnumbered);
	}//end orderStatuses()

	/**
	 * The report line for a status that is not an object or has no numeric `volgnummer`, or null.
	 *
	 * @param mixed $status The statustype as stored.
	 *
	 * @return array{0: string, 1: string, 2: string}|null The line.
	 */
	private static function statusShapeNote(mixed $status): ?array {
		if (is_array($status) === false) {
			return self::NOTE_NOT_OBJECT;
		}

		if (self::isNumbered(status: $status) === false) {
			return self::NOTE_NO_VOLGNUMMER;
		}

		return null;
	}//end statusShapeNote()

	/**
	 * Whether a status is an object with a numeric `volgnummer`.
	 *
	 * @param mixed $status The statustype as stored.
	 *
	 * @return bool True when it is.
	 */
	private static function isNumbered(mixed $status): bool {
		return is_array($status) === true && isset($status['volgnummer']) === true && is_numeric($status['volgnummer']) === true;
	}//end isNumbered()

	/**
	 * Report the approximate wrapping of one status, and every attribute of
	 * it that has no counterpart.
	 *
	 * @param array<string, mixed>  $status The statustype.
	 * @param ZaaktypeSkeletonDraft $draft  The skeleton being built, its last stage the one the status became.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	private function reportStatus(array $status, ZaaktypeSkeletonDraft $draft): void {
		$number = (string)($status['volgnummer'] ?? '-');
		$draft->note(element: sprintf('statustypen (volgnummer %s)', $number), line: self::wrapNote(stage: $draft->lastStage()));

		foreach (self::STATUS_EXTRAS as $extra) {
			if (isset($status[$extra]) === true && $status[$extra] !== '') {
				$draft->note(element: sprintf('statustypen (volgnummer %s).%s', $number, $extra), line: self::NOTE_STATUS_EXTRA);
			}
		}
	}//end reportStatus()

	/**
	 * The report line explaining how a status was wrapped in a stage.
	 *
	 * @param array<string, mixed> $stage The stage the status became.
	 *
	 * @return array{0: string, 1: string, 2: string} The line.
	 */
	private static function wrapNote(array $stage): array {
		return [
			self::APPROXIMATE,
			sprintf(
				"Milestone '%s' was wrapped in stage '%s' with one required human item '%s' so the sequence does not complete itself on import.",
				(string)$stage['children'][1]['key'],
				(string)$stage['key'],
				(string)$stage['children'][0]['key']
			),
			'Replace the placeholder human item with the real work of this phase, or drop the stage and give the milestone a real entry criterion.',
		];
	}//end wrapNote()

	/**
	 * `resultaattypen` -> the constrained end-state set; archival terms carried.
	 *
	 * @param array<string, mixed>  $zaaktype The document.
	 * @param ZaaktypeSkeletonDraft $draft    The skeleton being built.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	private function mapResults(array $zaaktype, ZaaktypeSkeletonDraft $draft): void {
		$results = ($zaaktype['resultaattypen'] ?? []);
		if (is_array($results) === false || $results === []) {
			$draft->note(element: 'resultaattypen', line: self::NOTE_NO_RESULTS);
			return;
		}

		$draft->settings['results']        = [];
		$draft->settings['resultMetadata'] = [];
		foreach ($results as $index => $result) {
			$this->placeResult(result: ['index' => (int)$index, 'declared' => $result], draft: $draft);
		}

		$reason = sprintf('%d result(s) became the constrained end-state set.', count($draft->settings['results']));
		$draft->note(element: 'resultaattypen', line: [self::MAPPED, $reason, 'Completing the case with any other result is refused.']);
	}//end mapResults()

	/**
	 * Add one result to the end-state set and carry its archival terms, or report it unnamed.
	 *
	 * @param array{index: int, declared: mixed} $result The result's position and the resultaattype as stored.
	 * @param ZaaktypeSkeletonDraft              $draft  The skeleton being built.
	 *
	 * @return void
	 */
	private function placeResult(array $result, ZaaktypeSkeletonDraft $draft): void {
		$name = self::resultName(result: $result['declared']);
		if ($name === '') {
			$draft->note(element: 'resultaattypen[' . $result['index'] . ']', line: self::NOTE_RESULT_UNNAMED);
			return;
		}

		$draft->settings['results'][] = $name;
		$metadata = self::archivalTerms(result: $result['declared']);
		if ($metadata !== []) {
			$draft->settings['resultMetadata'][$name] = $metadata;
			$draft->note(element: sprintf("resultaattypen '%s' archival terms", $name), line: self::NOTE_ARCHIVAL);
		}
	}//end placeResult()

	/**
	 * A result's trimmed `omschrijving`, or '' when it is not an object or has none.
	 *
	 * @param mixed $result The resultaattype as stored.
	 *
	 * @return string The name.
	 */
	private static function resultName(mixed $result): string {
		if (is_array($result) === false) {
			return '';
		}

		return trim((string)($result['omschrijving'] ?? ''));
	}//end resultName()

	/**
	 * The archival terms a result declares, in the order {@see ARCHIVAL_TERMS} lists them.
	 *
	 * @param array<string, mixed> $result The resultaattype.
	 *
	 * @return array<string, mixed> The set terms.
	 */
	private static function archivalTerms(array $result): array {
		$terms = [];
		foreach (self::ARCHIVAL_TERMS as $term) {
			if (isset($result[$term]) === true) {
				$terms[$term] = $result[$term];
			}
		}

		return $terms;
	}//end archivalTerms()

	/**
	 * `doorlooptijd` / `servicenorm`: carried, not computed on.
	 *
	 * @param array<string, mixed>  $zaaktype The document.
	 * @param ZaaktypeSkeletonDraft $draft    The skeleton being built.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	private function mapTerms(array $zaaktype, ZaaktypeSkeletonDraft $draft): void {
		foreach (array_keys($draft->terms) as $term) {
			$value = trim((string)($zaaktype[$term] ?? ''));
			if ($value === '') {
				continue;
			}

			$draft->terms[$term]    = $value;
			$draft->settings[$term] = $value;
			$reason = sprintf("'%s' is carried onto every human item and the settings; nothing computes on it here.", $value);
			$draft->note(element: $term, line: [self::CARRIED, $reason, 'flow-business-timers acts on it; nothing to do in the case plan.']);
		}
	}//end mapTerms()

	/**
	 * Everything not handled is reported, never dropped silently.
	 *
	 * @param array<string, mixed>  $zaaktype The document.
	 * @param ZaaktypeSkeletonDraft $draft    The skeleton being built.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	private function reportRest(array $zaaktype, ZaaktypeSkeletonDraft $draft): void {
		foreach ($zaaktype as $element => $value) {
			if (self::isUnhandled(element: (string)$element, value: $value) === true) {
				$draft->note(element: (string)$element, line: self::NOTE_REST);
			}
		}
	}//end reportRest()

	/**
	 * Whether a non-empty zaaktype element is neither consumed by the mapping nor part of its identity.
	 *
	 * @param string $element The element name.
	 * @param mixed  $value   Its value.
	 *
	 * @return bool True when it must be reported.
	 */
	private static function isUnhandled(string $element, mixed $value): bool {
		return in_array($element, [...self::HANDLED, ...self::IDENTITY], true) === false
			&& in_array($value, [null, '', []], true) === false;
	}//end isUnhandled()

}//end class
