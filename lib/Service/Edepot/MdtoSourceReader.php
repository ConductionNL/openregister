<?php

/**
 * OpenRegister MDTO Source Reader
 *
 * Answers "where does this MDTO value come from" for the three elements the
 * export learned to emit alongside the audit-trail events.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-emit-mdto-aggregatieniveau-beperkinggebruik-and-dekkingintijd-from-their-declared-sources
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Resolves aggregatieniveau, dekkingInTijd and beperkingGebruik from the object.
 *
 * Kept apart from {@see MdtoXmlGenerator} because it answers a different
 * question. The generator decides how a value is serialised; this decides
 * whether there is a value at all, which is the half that carries the
 * archival judgement.
 *
 * The standing rule, applied by every method here: return null or an empty
 * list when no source supplies a value, so the caller omits the element.
 * Nothing here fabricates a default. An MDTO export is read as a statement
 * about the record, and a guess put in one is indistinguishable from a fact.
 *
 * Two source layers are read, in order:
 *
 * 1. The `retention` block under the abstract English key
 *    (`aggregationLevel`, `temporalCoverage`, `useRestriction`), which is the
 *    vocabulary the abstract archival layer speaks.
 * 2. The `tmlo` block under the Dutch key (`aggregatieniveau`,
 *    `dekkingInTijd`, `beperkingGebruik`).
 *
 * Measured on 2026-09-11: NOTHING in this repository writes either key for any
 * of the three, so on current data all three elements are absent. They are
 * read rather than derived because no other stored field answers the
 * question, and the reading path means a client or schema default that does
 * write one is exported instead of dropped.
 *
 * @psalm-suppress UnusedClass
 */
class MdtoSourceReader {

	/**
	 * The `beperkingGebruikType` label used for an active legal hold.
	 *
	 * "Overig" is the BeperkingGebruikTypeLijst term for a restriction that is
	 * described in the documentation but has no more specific type. A legal
	 * hold is described, since it carries a reason, and MDTO defines no
	 * legal-hold type, so this is the term the standard provides for the case.
	 */
	public const USE_RESTRICTION_OTHER = 'Overig';

	/**
	 * Resolve the object's aggregatieniveau.
	 *
	 * @param ObjectEntity $object The object to read.
	 *
	 * @return array{label: string, code: string|null}|null The level, or null when none is declared.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-emit-mdto-aggregatieniveau-beperkinggebruik-and-dekkingintijd-from-their-declared-sources
	 */
	public function aggregationLevel(ObjectEntity $object): ?array {
		$declared = $this->declaredValue(
			object: $object,
			abstractKey: 'aggregationLevel',
			tmloKey: 'aggregatieniveau'
		);

		$label = $this->labelOf(value: $declared);
		if ($label === null) {
			return null;
		}

		return ['label' => $label, 'code' => $this->codeOf(value: $declared)];
	}//end aggregationLevel()

	/**
	 * Resolve the object's dekkingInTijd entries.
	 *
	 * An entry is returned only when it supplies BOTH a type label and a start
	 * date, because the XSD makes `dekkingInTijdType` and
	 * `dekkingInTijdBegindatum` `minOccurs="1"` and there is no defensible
	 * default for either. A half-declared entry is skipped, not completed.
	 *
	 * The record's own `created` timestamp is deliberately NOT used. MDTO
	 * defines dekkingInTijd as the period the CONTENT pertains to, which is
	 * not when the row was written.
	 *
	 * @param ObjectEntity $object The object to read.
	 *
	 * @return list<array{type: string, start: string, end: string|null}> Complete entries only.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-emit-mdto-aggregatieniveau-beperkinggebruik-and-dekkingintijd-from-their-declared-sources
	 */
	public function temporalCoverage(ObjectEntity $object): array {
		$declared = $this->declaredValue(
			object: $object,
			abstractKey: 'temporalCoverage',
			tmloKey: 'dekkingInTijd'
		);

		if (is_array($declared) === false) {
			return [];
		}

		$entries = $declared;
		if (isset($declared['type']) === true || isset($declared['start']) === true) {
			$entries = [$declared];
		}

		$resolved = [];
		foreach ($entries as $entry) {
			$complete = $this->completeCoverageEntry(entry: $entry);
			if ($complete !== null) {
				$resolved[] = $complete;
			}
		}

		return $resolved;
	}//end temporalCoverage()

	/**
	 * Resolve the object's use restriction, or null when none is known.
	 *
	 * Two real sources, in order:
	 *
	 * 1. A declared restriction under `retention.useRestriction` or
	 *    `tmlo.beperkingGebruik`.
	 * 2. An ACTIVE legal hold in `retention.legalHold`, which
	 *    `RetentionService::placeLegalHold()` does write, with a reason and a
	 *    placed date. A hold restricts what may be done with the record, so it
	 *    is reported as a restriction on use.
	 *
	 * When neither is present this returns null and the element is omitted.
	 * MDTO offers "Nader te bepalen" for exactly this state and emitting it
	 * would make the element present; that is deliberately NOT done, because a
	 * restriction openregister has not been told about is better reported as a
	 * gap than asserted in the XML.
	 *
	 * @param ObjectEntity $object The object to read.
	 *
	 * @return array{type: string, description: string|null, startDate: string|null}|null The restriction.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-emit-mdto-aggregatieniveau-beperkinggebruik-and-dekkingintijd-from-their-declared-sources
	 */
	public function useRestriction(ObjectEntity $object): ?array {
		$declared = $this->declaredValue(
			object: $object,
			abstractKey: 'useRestriction',
			tmloKey: 'beperkingGebruik'
		);

		$label = $this->labelOf(value: $declared);
		if ($label !== null) {
			return [
				'type' => $label,
				'description' => $this->stringAt(value: $declared, key: 'description'),
				'startDate' => $this->dateOnly(value: $this->stringAt(value: $declared, key: 'startDate')),
			];
		}

		return $this->legalHoldRestriction(object: $object);
	}//end useRestriction()

	/**
	 * Report an ACTIVE legal hold as a use restriction.
	 *
	 * @param ObjectEntity $object The object to read.
	 *
	 * @return array{type: string, description: string|null, startDate: string|null}|null The restriction.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-emit-mdto-aggregatieniveau-beperkinggebruik-and-dekkingintijd-from-their-declared-sources
	 */
	private function legalHoldRestriction(ObjectEntity $object): ?array {
		$retention = ($object->getRetention() ?? []);
		$hold = ($retention['legalHold'] ?? null);
		if (is_array($hold) === false || ($hold['active'] ?? false) !== true) {
			return null;
		}

		$description = 'Legal hold';
		$reason = $this->stringAt(value: $hold, key: 'reason');
		if ($reason !== null) {
			$description = 'Legal hold: ' . $reason;
		}

		return [
			'type' => self::USE_RESTRICTION_OTHER,
			'description' => $description,
			'startDate' => $this->dateOnly(value: ($hold['placedDate'] ?? null)),
		];
	}//end legalHoldRestriction()

	/**
	 * Accept one dekkingInTijd entry only when it is complete and well-formed.
	 *
	 * Both dates must be an `xsd:gYear`, `xsd:gYearMonth` or `xsd:date`, the
	 * union the XSD declares. A start in any other form makes the entry
	 * incomplete, so it is dropped; an end in any other form is dropped alone.
	 *
	 * @param mixed $entry The declared entry.
	 *
	 * @return array{type: string, start: string, end: string|null}|null The entry, or null when incomplete.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-emit-mdto-aggregatieniveau-beperkinggebruik-and-dekkingintijd-from-their-declared-sources
	 */
	private function completeCoverageEntry(mixed $entry): ?array {
		if (is_array($entry) === false) {
			return null;
		}

		$type = $this->stringAt(value: $entry, key: 'type');
		$start = $this->coverageDate(value: $this->stringAt(value: $entry, key: 'start'));
		if ($type === null || $start === null) {
			return null;
		}

		return ['type' => $type, 'start' => $start, 'end' => $this->coverageDate(value: $this->stringAt(value: $entry, key: 'end'))];
	}//end completeCoverageEntry()

	/**
	 * Read a declared archival value from the abstract or the TMLO block.
	 *
	 * @param ObjectEntity $object The source object.
	 * @param string $abstractKey The English key on the retention block.
	 * @param string $tmloKey The Dutch key on the TMLO block.
	 *
	 * @return mixed The declared value, or null when neither block carries one.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-emit-mdto-aggregatieniveau-beperkinggebruik-and-dekkingintijd-from-their-declared-sources
	 */
	private function declaredValue(ObjectEntity $object, string $abstractKey, string $tmloKey): mixed {
		$retention = ($object->getRetention() ?? []);
		if (isset($retention[$abstractKey]) === true) {
			return $retention[$abstractKey];
		}

		$tmlo = ($object->getTmlo() ?? []);
		if (is_array($tmlo) === true && isset($tmlo[$tmloKey]) === true) {
			return $tmlo[$tmloKey];
		}

		return null;
	}//end declaredValue()

	/**
	 * Read the begripLabel out of a declared value.
	 *
	 * A declared value may be a bare label string or an array carrying a
	 * `label` or `type` key. Anything else yields null and the caller omits
	 * the element.
	 *
	 * @param mixed $value The declared value.
	 *
	 * @return string|null The label, or null when there is none.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-emit-mdto-aggregatieniveau-beperkinggebruik-and-dekkingintijd-from-their-declared-sources
	 */
	private function labelOf(mixed $value): ?string {
		if (is_string($value) === true && $value !== '') {
			return $value;
		}

		return ($this->stringAt(value: $value, key: 'label') ?? $this->stringAt(value: $value, key: 'type'));
	}//end labelOf()

	/**
	 * Read the begripCode out of a declared value.
	 *
	 * @param mixed $value The declared value.
	 *
	 * @return string|null The code, or null when there is none.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-emit-mdto-aggregatieniveau-beperkinggebruik-and-dekkingintijd-from-their-declared-sources
	 */
	private function codeOf(mixed $value): ?string {
		return $this->stringAt(value: $value, key: 'code');
	}//end codeOf()

	/**
	 * Read a non-empty string at a key of an array value.
	 *
	 * @param mixed $value The value, which need not be an array.
	 * @param string $key The key to read.
	 *
	 * @return string|null The string, or null when it is absent or empty.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-emit-mdto-aggregatieniveau-beperkinggebruik-and-dekkingintijd-from-their-declared-sources
	 */
	private function stringAt(mixed $value, string $key): ?string {
		if (is_array($value) === false) {
			return null;
		}

		$candidate = ($value[$key] ?? null);
		if (is_string($candidate) === true && $candidate !== '') {
			return $candidate;
		}

		return null;
	}//end stringAt()

	/**
	 * Accept a date only in a form the XSD's dekkingInTijd union allows.
	 *
	 * @param string|null $value The declared date.
	 *
	 * @return string|null The value when it is a gYear, gYearMonth or date; null otherwise.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
	 */
	private function coverageDate(?string $value): ?string {
		if ($value === null || preg_match('/^\d{4}(-\d{2}(-\d{2})?)?$/', $value) !== 1) {
			return null;
		}

		return $value;
	}//end coverageDate()

	/**
	 * Reduce an ISO-8601 timestamp to the xsd:date the termijn element needs.
	 *
	 * @param mixed $value The stored timestamp.
	 *
	 * @return string|null The date part, or null when the value is unusable.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-emit-mdto-aggregatieniveau-beperkinggebruik-and-dekkingintijd-from-their-declared-sources
	 */
	private function dateOnly(mixed $value): ?string {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $matches) !== 1) {
			return null;
		}

		return $matches[1];
	}//end dateOnly()
}//end class
