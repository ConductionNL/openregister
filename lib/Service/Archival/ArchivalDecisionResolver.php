<?php

/**
 * The one resolved answer to "what happens to this object, and when".
 *
 * 🔴 THIS EXISTS BECAUSE THE ANSWER WAS SCATTERED AND THE ABSTRACT SLOT WAS
 * EMPTY. `@self._retention` has been declared in `ObjectEntity::getObjectArray()`
 * since add-archival-annotation-support, and `setArchivalRetention()` — the only
 * way to fill it — was called from exactly one place in the whole repository: a
 * unit test. Every consumer that asked an object what its archival constraints
 * were therefore got nothing, while the facts themselves sat in three different
 * shapes nobody had merged:
 *
 *   1. `retention.archiefnominatie` / `bewaartermijn` / `archiefactiedatum`,
 *      written by {@see \OCA\OpenRegister\Service\RetentionService::applyArchivalMetadata()}
 *      but only for schemas whose `archive` block is enabled;
 *   2. `retention.annotation`, the `x-openregister-archival` evaluation written
 *      by RenderObject, in `effectiveRetention` / `matchedRule` / `expiresAt`;
 *   3. `retention.legalHold`, which overrides both and which neither of the
 *      other two mentions.
 *
 * The cost of leaving it scattered is that every consuming app grew its OWN
 * archival fields instead — dossiq derives `archiveNomination` and
 * `archiveActionDate` onto the case record from its resultaattype — so the same
 * question has a different answer per app, and no shared surface (a metadata
 * panel, an index column, a records-officer report) can ask it once.
 *
 * This resolver is the merge, in app-neutral keys. It reads; it never writes to
 * storage and it never decides that anything may be destroyed — that stays with
 * RetentionService and the destruction pipeline.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Merges an object's stored retention block, its schema annotation evaluation
 * and any legal hold into one resolved `@self._retention` decision.
 */
class ArchivalDecisionResolver {

	/**
	 * Nomination values that mean "this object is kept forever".
	 *
	 * Both spellings occur in the wild: MDTO and the selectielijst use
	 * `blijvend_bewaren`, while a ZGW resultaattype may carry the shorter
	 * `bewaren`. They mean the same thing to an archivist, so they normalise
	 * to one value rather than reaching a reader as two.
	 *
	 * @var array<string, string>
	 */
	private const NOMINATION_ALIASES = [
		'bewaren' => 'blijvend_bewaren',
		'blijvend_bewaren' => 'blijvend_bewaren',
		'vernietigen' => 'vernietigen',
		'nog_niet_bepaald' => 'nog_niet_bepaald',
	];

	/**
	 * Resolve an object's archival decision.
	 *
	 * Returns null when the object has nothing to say about its own archiving —
	 * no stored retention block, no schema annotation, no hold. Null means the
	 * key is omitted from `@self` entirely, which is deliberate: an empty
	 * `_retention: {}` reads as "we looked and there is no obligation", and a
	 * records officer must be able to tell that apart from "this schema never
	 * declared one".
	 *
	 * @param ObjectEntity $entity The object to resolve for.
	 *
	 * @return array<string, mixed>|null The resolved decision, or null when there is none.
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function resolve(ObjectEntity $entity): ?array {
		$retention = ($entity->getRetention() ?? []);
		if (is_array($retention) === false) {
			$retention = [];
		}

		// The object's OWN archival properties, in the ZGW zaak vocabulary.
		//
		// 🔴 THIS IS WHAT MAKES `_retention` ABSTRACT RATHER THAN THEORETICAL.
		// `archiefnominatie` / `archiefactiedatum` / `archiefstatus` are the ZGW
		// contract, and an app that implements the zaak API declares them as
		// ordinary schema properties on its own record — dossiq derives them
		// from a case's resultaattype on close (zrc-021) and writes them there.
		// Reading only `@self.retention` would have meant `_retention` stayed
		// empty on exactly the records that HAVE an archival decision, while
		// each app kept reading its own field names, which is the per-app
		// duplication this resolver exists to end.
		//
		// These are not app-specific names: openregister already speaks the
		// same vocabulary in `retention`, in Dutch. This reads the English
		// spelling too, and normalises both into one answer.
		//
		// `@self.retention` still WINS where both are present: that is a
		// decision recorded against the object through the retention service,
		// and a schema property is what an app wrote for its own API consumers.
		$declared = $this->declaredArchivalFields(entity: $entity);
		if ($retention === [] && $declared === []) {
			return null;
		}

		$annotation = $retention['annotation'] ?? [];
		if (is_array($annotation) === false) {
			$annotation = [];
		}

		$decision = [
			'nomination' => $this->normaliseNomination(
				value: ($retention['archiefnominatie'] ?? ($declared['nomination'] ?? null))
			),
			'status' => $this->firstNonEmpty(
				values: [($retention['archiefstatus'] ?? null), ($declared['status'] ?? null)]
			),
			'classification' => $this->stringOrNull(value: ($retention['classification'] ?? null)),
			'legalHold' => $this->resolveLegalHold(entity: $entity, retention: $retention),
		];

		// The retention PERIOD and the date it falls due. The stored block and
		// the schema annotation can both supply a period; the stored one is a
		// decision somebody recorded against this object, so it wins over a
		// rule evaluated from the schema.
		$decision['period'] = $this->firstNonEmpty(
			values: [
				($retention['bewaartermijn'] ?? null),
				($annotation['effectiveRetention'] ?? null),
			]
		);

		$decision['actionDate'] = $this->firstNonEmpty(
			values: [
				($retention['archiefactiedatum'] ?? null),
				($declared['actionDate'] ?? null),
				($annotation['expiresAt'] ?? null),
			]
		);

		// WHERE the answer came from, so a reader can tell a selectielijst
		// obligation from a schema default from a hand-set date. Without this a
		// records officer sees a destruction date and cannot say who claimed it.
		$decision['basis'] = $this->resolveBasis(
			retention: $retention,
			annotation: $annotation,
			declared: $declared
		);
		$decision['source'] = $this->stringOrNull(value: ($retention['selectielijstBron'] ?? null));

		// The raw annotation evaluation is passed through rather than folded
		// away: `matchedRule` is the only thing that says WHICH rule in the
		// schema's `x-openregister-archival` block fired, and debugging a wrong
		// destruction date without it means re-evaluating the whole annotation
		// by hand.
		if ($annotation !== []) {
			$decision['annotation'] = $annotation;
		}

		// Nothing was established. Distinct from "kept forever": every field is
		// absent, not merely permissive, so the object genuinely has no
		// archival claim on it and the key is omitted.
		$established = array_filter(
			$decision,
			static function ($value) {
				return $value !== null && $value !== [];
			}
		);

		if ($established === []) {
			return null;
		}

		return $decision;
	}//end resolve()

	/**
	 * Normalise a nomination value to its canonical spelling.
	 *
	 * @param mixed $value The stored nomination.
	 *
	 * @return string|null The canonical nomination, the raw string when it is
	 *                     one this resolver does not know, or null when absent.
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	private function normaliseNomination(mixed $value): ?string {
		$raw = $this->stringOrNull(value: $value);
		if ($raw === null) {
			return null;
		}

		// An unknown value is passed through rather than dropped. Dropping it
		// would report "no nomination" for an object that carries one this
		// resolver has not been taught, which is the failure mode that hides a
		// records obligation.
		return (self::NOMINATION_ALIASES[$raw] ?? $raw);
	}//end normaliseNomination()

	/**
	 * Resolve the legal-hold state.
	 *
	 * Reports an inactive hold as `['active' => false]` rather than null when
	 * the object has hold HISTORY, because "held in the past and released" is a
	 * different fact from "never held", and an archivist reads the difference.
	 *
	 * @param ObjectEntity $entity The object being resolved.
	 * @param array<string, mixed> $retention The stored retention block.
	 *
	 * @return array<string, mixed>|null The hold state, or null when never held.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	private function resolveLegalHold(ObjectEntity $entity, array $retention): ?array {
		$hold = ($retention['legalHold'] ?? null);
		if (is_array($hold) === false) {
			return null;
		}

		$active = $entity->hasActiveLegalHold();
		$state = ['active' => $active];

		if ($active === true) {
			$state['reason'] = $this->stringOrNull(value: ($hold['reason'] ?? null));
			$state['placedBy'] = $this->stringOrNull(value: ($hold['placedBy'] ?? null));
			$state['placedDate'] = $this->stringOrNull(value: ($hold['placedDate'] ?? null));
		}

		$history = ($hold['history'] ?? []);
		if (is_array($history) === true && $history !== []) {
			$state['releasedCount'] = count($history);
		}

		return $state;
	}//end resolveLegalHold()

	/**
	 * Decide which authority the retention period rests on.
	 *
	 * @param array<string, mixed> $retention The stored retention block.
	 * @param array<string, mixed> $annotation The schema annotation evaluation.
	 *
	 * @return string|null One of `selectielijst`, `schema`, `annotation`, or null.
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	private function resolveBasis(array $retention, array $annotation, array $declared = []): ?string {
		if ($this->stringOrNull(value: ($retention['selectielijstBron'] ?? null)) !== null) {
			return 'selectielijst';
		}

		if ($this->stringOrNull(value: ($retention['bewaartermijn'] ?? null)) !== null) {
			return 'schema';
		}

		if ($declared !== []) {
			return 'record';
		}

		if ($this->stringOrNull(value: ($annotation['effectiveRetention'] ?? null)) !== null) {
			return 'annotation';
		}

		return null;
	}//end resolveBasis()

	/**
	 * Read the ZGW archival properties the object itself declares.
	 *
	 * Both spellings are accepted for each field, because an app writes
	 * whichever its own API speaks: the Dutch `archiefnominatie` when it mirrors
	 * ZGW verbatim, the English `archiveNomination` when its data model is
	 * English (dossiq's is, by its own D13 decision).
	 *
	 * Returns only the keys it could establish, so an object declaring a
	 * nomination and no date yields the nomination alone rather than a date
	 * blanked to null.
	 *
	 * @param ObjectEntity $entity The object being resolved.
	 *
	 * @return array<string, string> The declared fields, keyed abstractly.
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	private function declaredArchivalFields(ObjectEntity $entity): array {
		$object = $entity->getObject();
		if (is_array($object) === false) {
			return [];
		}

		$spellings = [
			'nomination' => ['archiveNomination', 'archiefnominatie'],
			'actionDate' => ['archiveActionDate', 'archiefactiedatum'],
			'status' => ['archiveStatus', 'archiefstatus'],
		];

		$found = [];
		foreach ($spellings as $key => $names) {
			foreach ($names as $name) {
				$value = $this->stringOrNull(value: ($object[$name] ?? null));
				if ($value !== null) {
					$found[$key] = $value;
					break;
				}
			}
		}

		return $found;
	}//end declaredArchivalFields()

	/**
	 * Return the first value that is a non-empty string.
	 *
	 * @param array<int, mixed> $values Candidates in priority order.
	 *
	 * @return string|null The first usable value, or null.
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	private function firstNonEmpty(array $values): ?string {
		foreach ($values as $value) {
			$candidate = $this->stringOrNull(value: $value);
			if ($candidate !== null) {
				return $candidate;
			}
		}

		return null;
	}//end firstNonEmpty()

	/**
	 * Coerce a value to a non-empty string, or null.
	 *
	 * @param mixed $value The value to coerce.
	 *
	 * @return string|null The trimmed string, or null when empty or not scalar.
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	private function stringOrNull(mixed $value): ?string {
		if (is_string($value) === false) {
			return null;
		}

		$trimmed = trim($value);
		if ($trimmed === '') {
			return null;
		}

		return $trimmed;
	}//end stringOrNull()

}//end class
