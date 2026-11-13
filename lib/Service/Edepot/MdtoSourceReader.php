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
use OCA\OpenRegister\Service\Archival\ObjectArchivalAnnotation;

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
 * The same two layers carry the CORE archival facts, which is what lets one
 * generator serve both exports. An object written through the retention
 * pipeline keeps them in `retention`; an object written through TMLO keeps
 * them in `tmlo`, under TMLO's own spellings (`bewaarTermijn` with its
 * capital T, `vernietigingsCategorie` for the disposal category). Reading
 * both here is why `MdtoXmlGenerator` is the only implementation of the
 * format and `TmloService` no longer has a second one.
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
	 * Constructor.
	 *
	 * @param MdtoValueReader $values The primitives every archival read shares.
	 * @param ObjectArchivalAnnotation $annotations The schema's archival annotation, evaluated for the object.
	 */
	public function __construct(
		private readonly MdtoValueReader $values,
		private readonly ObjectArchivalAnnotation $annotations,
	) {
	}//end __construct()

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
	 * The begrippenlijst name used for `informatiecategorie` when the record
	 * does not say which selectielijst its category came from.
	 */
	public const CATEGORY_LIST_FALLBACK = 'Selectielijst';


	/**
	 * The core archival facts, from whichever block carries them.
	 *
	 * One read rather than six, because they are one question: what does this
	 * object say about its own archiving. An object written through the
	 * retention pipeline keeps them in `retention`; one written through TMLO
	 * keeps them in `tmlo` under TMLO's spellings, and `vernietigingsCategorie`
	 * and `classification` are the disposal category and the classification
	 * scheme, which MDTO keeps as separate elements.
	 *
	 * @param ObjectEntity $object The object to read.
	 *
	 * @return array{appraisal: string|null, retentionPeriod: string|null,
	 *     disposalDate: string|null, disposalCategory: array{label: string, list: string}|null,
	 *     classification: string|null, description: string|null} The facts.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
	 */
	public function coreFacts(ObjectEntity $object): array {
		$source = $this->sourceOf(object: $object);

		return [
			'appraisal' => $this->declaredText(source: $source, fact: new ArchivalFactKey(abstractKey: 'archiefnominatie', tmloKey: 'archiefnominatie')),
			'retentionPeriod' => $this->declaredText(source: $source, fact: new ArchivalFactKey(abstractKey: 'bewaartermijn', tmloKey: 'bewaarTermijn')),
			'disposalDate' => $this->values->matching(
				value: $this->values->declared(source: $source, fact: new ArchivalFactKey(abstractKey: 'archiefactiedatum', tmloKey: 'archiefactiedatum')),
				pattern: MdtoValueReader::XSD_DATE
			),
			'disposalCategory' => $this->disposalCategory(object: $object),
			'classification' => $this->values->textAt(value: $object->getTmlo(), key: 'classification'),
			'description' => $this->declaredText(source: $source, fact: new ArchivalFactKey(abstractKey: 'toelichting', tmloKey: 'toelichting')),
		];
	}//end coreFacts()

	/**
	 * The disposal category and the list it came from, MDTO's `informatiecategorie`.
	 *
	 * TMLO calls this `vernietigingsCategorie`; the retention block calls it
	 * `classification`. They are the same fact, the selectielijst category
	 * that decides disposal, which is why MDTO has one element for it.
	 *
	 * @param ObjectEntity $object The object to read.
	 *
	 * @return array{label: string, list: string}|null The category, or null when there is none.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
	 */
	private function disposalCategory(ObjectEntity $object): ?array {
		$label = $this->declaredText(
			source: $this->sourceOf(object: $object),
			fact: new ArchivalFactKey(abstractKey: 'classification', tmloKey: 'vernietigingsCategorie')
		);
		if ($label === null) {
			return null;
		}

		$list = $this->values->textAt(value: $object->getRetention(), key: 'selectielijstBron');

		return ['label' => $label, 'list' => ($list ?? self::CATEGORY_LIST_FALLBACK)];
	}//end disposalCategory()

	/**
	 * The object's naam.
	 *
	 * The object's own data first, then the entity's `name` column, then the
	 * uuid. The column matters: an object exported through the TMLO endpoint
	 * carries its name there rather than in its data, and reading only the
	 * data would have labelled every such record with its uuid.
	 *
	 * @param ObjectEntity $object The object to read.
	 *
	 * @return string The name, empty when nothing supplies one.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-generate-mdto-compliant-xml-metadata-per-object
	 */
	public function name(ObjectEntity $object): string {
		$data = ($object->getObject() ?? []);
		$name = ($this->values->textAt(value: $data, key: 'title')
			?? $this->values->textAt(value: $data, key: 'naam')
			?? $this->values->textAt(value: $data, key: 'name')
			?? $this->values->text(value: $object->getName())
			?? $this->values->text(value: $object->getUuid()));

		return ($name ?? '');
	}//end name()








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
		$declared = $this->declaredFor(object: $object, fact: new ArchivalFactKey(abstractKey: 'aggregationLevel', tmloKey: 'aggregatieniveau'));

		$label = $this->values->label(value: $declared);
		if ($label === null) {
			return null;
		}

		return ['label' => $label, 'code' => $this->values->textAt(value: $declared, key: 'code')];
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
		$declared = $this->declaredFor(object: $object, fact: new ArchivalFactKey(abstractKey: 'temporalCoverage', tmloKey: 'dekkingInTijd'));
		if (is_array($declared) === false) {
			return [];
		}

		$resolved = [];
		foreach (self::coverageEntries(declared: $declared) as $entry) {
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
	 *    `RetentionService::placeHold()` does write, with a reason and a
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
		$declared = $this->declaredFor(object: $object, fact: new ArchivalFactKey(abstractKey: 'useRestriction', tmloKey: 'beperkingGebruik'));

		$label = $this->values->label(value: $declared);
		if ($label !== null) {
			return [
				'type' => $label,
				'description' => $this->values->textAt(value: $declared, key: 'description'),
				'startDate' => $this->values->matching(
					value: $this->values->textAt(value: $declared, key: 'startDate'),
					pattern: MdtoValueReader::XSD_DATE_PREFIX
				),
			];
		}

		return $this->legalHoldRestriction(object: $object);
	}//end useRestriction()

	/**
	 * The parties the schema's archival block names for this object, for `betrokkene`.
	 *
	 * Read from the evaluated annotation only: `parties` names the record's
	 * own properties, so the evaluation is where the row's values are read.
	 *
	 * @param ObjectEntity $object The object to read.
	 *
	 * @return list<array{role: string, name: string, id: string|null}> The parties; empty when none is named.
	 *
	 * @spec openspec/changes/archival-conformance/tasks.md#task-1.2
	 */
	public function parties(ObjectEntity $object): array {
		$declared = ($this->sourceOf(object: $object)->annotation['parties'] ?? null);
		if (is_array($declared) === false) {
			return [];
		}

		$parties = [];
		foreach ($declared as $party) {
			if (is_array($party) === false || is_string($party['name'] ?? null) === false || $party['name'] === '') {
				continue;
			}

			$id = null;
			if (isset($party['id']) === true) {
				$id = (string) $party['id'];
			}

			$parties[] = ['role' => (string) ($party['role'] ?? ''), 'name' => $party['name'], 'id' => $id];
		}

		return $parties;
	}//end parties()

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
		$hold      = ($retention['legalHold'] ?? null);
		if (is_array($hold) === false || ($hold['active'] ?? false) !== true) {
			return null;
		}

		return [
			'type' => self::USE_RESTRICTION_OTHER,
			'description' => $this->legalHoldDescription(hold: $hold),
			'startDate' => $this->values->matching(value: ($hold['placedDate'] ?? null), pattern: MdtoValueReader::XSD_DATE_PREFIX),
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

		$type  = $this->values->textAt(value: $entry, key: 'type');
		$start = $this->coverageDateAt(entry: $entry, key: 'start');
		if ($type === null || $start === null) {
			return null;
		}

		return ['type' => $type, 'start' => $start, 'end' => $this->coverageDateAt(entry: $entry, key: 'end')];
	}//end completeCoverageEntry()

	/**
	 * The object with its schema's evaluated archival annotation.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return ArchivalSource The source to read declared facts from.
	 */
	private function sourceOf(ObjectEntity $object): ArchivalSource {
		return new ArchivalSource(object: $object, annotation: $this->annotations->forObject(object: $object));
	}//end sourceOf()

	/**
	 * A declared value as text.
	 *
	 * @param ArchivalSource  $source The object and its annotation.
	 * @param ArchivalFactKey $fact   The fact's keys.
	 *
	 * @return string|null The text.
	 */
	private function declaredText(ArchivalSource $source, ArchivalFactKey $fact): ?string {
		return $this->values->text(value: $this->values->declared(source: $source, fact: $fact));
	}//end declaredText()

	/**
	 * A declared value, read with the object's own annotation.
	 *
	 * @param ObjectEntity    $object The object.
	 * @param ArchivalFactKey $fact   The fact's keys.
	 *
	 * @return mixed The declared value.
	 */
	private function declaredFor(ObjectEntity $object, ArchivalFactKey $fact): mixed {
		return $this->values->declared(source: $this->sourceOf(object: $object), fact: $fact);
	}//end declaredFor()

	/**
	 * A dekkingInTijd date at a key, in the date union the XSD declares, or null.
	 *
	 * @param array<mixed> $entry The coverage entry.
	 * @param string       $key   `start` or `end`.
	 *
	 * @return string|null The date.
	 */
	private function coverageDateAt(array $entry, string $key): ?string {
		return $this->values->matching(value: $this->values->textAt(value: $entry, key: $key), pattern: MdtoValueReader::XSD_DATE_UNION);
	}//end coverageDateAt()

	/**
	 * The coverage entries of a declaration: one entry, or a list of them.
	 *
	 * @param array<mixed> $declared The declaration.
	 *
	 * @return array<mixed> The entries.
	 */
	private static function coverageEntries(array $declared): array {
		if (isset($declared['type']) === true || isset($declared['start']) === true) {
			return [$declared];
		}

		return $declared;
	}//end coverageEntries()

	/**
	 * The description of a legal hold, with its reason when it has one.
	 *
	 * @param array<string, mixed> $hold The legal hold.
	 *
	 * @return string The description.
	 */
	private function legalHoldDescription(array $hold): string {
		$reason = $this->values->textAt(value: $hold, key: 'reason');
		if ($reason === null) {
			return 'Legal hold';
		}

		return 'Legal hold: ' . $reason;
	}//end legalHoldDescription()
}//end class
