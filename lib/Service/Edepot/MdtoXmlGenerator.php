<?php

/**
 * OpenRegister MDTO XML Generator
 *
 * Generates MDTO metadata for objects eligible for e-Depot transfer, valid
 * against the Nationaal Archief's MDTO-XML 1.0.1 schema.
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
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-generate-mdto-compliant-xml-metadata-per-object
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

use DOMElement;
use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Generator for MDTO `informatieobject` documents, and the entry point for a file's `bestand` document.
 *
 * ## What this generator claims, and what holds it to the claim
 *
 * Its output validates against `lib/Resources/mdto/MDTO-XML1.0.1.xsd`, a
 * verbatim copy of the Nationaal Archief's schema. `MdtoXmlGeneratorXsdTest`
 * validates documents for representative objects, with and without files and
 * optional elements, so the claim is tested rather than asserted. Where the
 * XSD cannot see a problem because the value is a free string, the rule is
 * stated at the element below.
 *
 * ## If you validate at runtime, use schemaValidateSource()
 *
 * Nextcloud's XXE protection sets libxml's external-entity loader to return
 * null, so `DOMDocument::schemaValidate($path)` cannot even read the schema
 * from disk inside a running instance: it fails with "Failed to load external
 * entity because the resolver function returned null". Read the file with
 * `file_get_contents()` and pass it to `schemaValidateSource()`, which never
 * consults the loader for the top-level schema. That is enough because the
 * vendored XSD imports and includes nothing; do not loosen the loader.
 *
 * ## Where MDTO requires a value openregister cannot source
 *
 * Two required elements can lack a source. Each is filled with the term MDTO's
 * own begrippenlijst defines for "not recorded", never with a guess:
 *
 * - `beperkingGebruik`, when no restriction is declared and no legal hold is
 *   active: `Nader te bepalen` from BeperkingGebruikTypeLijst.
 * - `waardering`, when the record's appraisal is itself undecided: `Nader te
 *   bepalen` from Waarderingen. That is a real value (`nog_niet_bepaald`), not
 *   a missing one.
 *
 * {@see self::collectMdtoRequiredElementGaps()} reports the first, so a
 * default is never mistaken for a recorded fact.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class MdtoXmlGenerator {

	/**
	 * MDTO namespace URI, kept here for existing callers.
	 */
	public const MDTO_NAMESPACE = MdtoDocumentWriter::MDTO_NAMESPACE;

	/**
	 * MDTO namespace prefix, kept here for existing callers.
	 */
	public const MDTO_PREFIX = MdtoDocumentWriter::MDTO_PREFIX;

	/**
	 * The MDTO begrippenlijst that `aggregatieniveau` labels come from.
	 */
	public const AGGREGATION_LEVEL_LIST = 'Aggregatieniveaus';

	/**
	 * The MDTO begrippenlijst that `beperkingGebruikType` labels come from.
	 */
	public const USE_RESTRICTION_LIST = 'BeperkingGebruikTypeLijst';

	/**
	 * The BeperkingGebruikTypeLijst term for a restriction nobody has recorded.
	 *
	 * Defined by the standard as "Er is mogelijk een beperking, maar de aard
	 * daarvan is niet vastgelegd als type beperking en niet vastgelegd in de
	 * metagegevens", which describes exactly an object with no declared
	 * restriction and no legal hold.
	 */
	public const USE_RESTRICTION_UNRECORDED = 'Nader te bepalen';

	/**
	 * The begrippenlijst named for `betrokkeneTypeRelatie`. MDTO leaves it free;
	 * the label is the schema property the party is kept in.
	 */
	public const PARTY_RELATION_LIST = 'Relatietypen betrokkenen';

	/**
	 * The MDTO begrippenlijst `waardering` comes from. It is CLOSED.
	 */
	public const APPRAISAL_LIST = 'Waarderingen';

	/**
	 * The begrippenlijst name used for `informatiecategorie` when the record
	 * does not say which selectielijst its category came from.
	 */
	public const CATEGORY_LIST_FALLBACK = MdtoSourceReader::CATEGORY_LIST_FALLBACK;

	/**
	 * The begrippenlijst name used for `classificatie`.
	 *
	 * MDTO leaves this list free. openregister does not record which scheme a
	 * TMLO classification code came from, so the element names the kind of
	 * list it is rather than claiming a specific one.
	 */
	public const CLASSIFICATION_LIST = 'Classificatieschema';

	/**
	 * `archiefnominatie` to the closed Waarderingen list: code and label.
	 *
	 * Waarderingen is declared "Gesloten", so only its three terms are valid,
	 * and this generator used to emit `bewaren`, `vernietigen` and `nog niet
	 * bepaald`, none of which is on it. The XSD cannot catch that, because
	 * `begripLabel` is a plain string, so the rule lives here.
	 *
	 * `vernietigen` maps to V, "Tijdelijk te bewaren", which the standard
	 * defines as "dient tijdelijk bewaard te worden en na afloop van de
	 * bewaartermijn vernietigd te worden".
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	public const APPRAISAL_MAP = [
		'bewaren' => ['B', 'Blijvend te bewaren'],
		'blijvend_bewaren' => ['B', 'Blijvend te bewaren'],
		'vernietigen' => ['V', 'Tijdelijk te bewaren'],
		'nog_niet_bepaald' => ['N', 'Nader te bepalen'],
	];

	/**
	 * Constructor.
	 *
	 * @param MdtoValueSources $sources The organisation settings, event history and declared archival values.
	 * @param MdtoDocumentWriter $writer The XML primitives.
	 * @param MdtoBestandGenerator $bestandGenerator Builder of a file's own document.
	 * @param MdtoPreconditions $preconditions What must hold before a document is worth writing.
	 */
	public function __construct(
		private readonly MdtoValueSources $sources,
		private readonly MdtoDocumentWriter $writer,
		private readonly MdtoBestandGenerator $bestandGenerator,
		private readonly MdtoPreconditions $preconditions,
	) {
	}//end __construct()

	/**
	 * Generate the MDTO `informatieobject` document for an object.
	 *
	 * The files are not embedded: each is referenced by `heeftRepresentatie`
	 * and described by its own document from {@see self::generateBestand()}.
	 * Element order follows the XSD's `informatieobjectType` sequence.
	 *
	 * @param ObjectEntity $object The object to generate XML for.
	 * @param array $files Associated file metadata (name, size, format, checksum, checksumDate).
	 *
	 * @return string The MDTO XML.
	 *
	 * @throws InvalidArgumentException If a precondition is not met.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-generate-mdto-compliant-xml-metadata-per-object
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
	 */
	public function generate(ObjectEntity $object, array $files = []): string {
		$this->checkInputs(object: $object, files: $files);
		[$dom, $root] = $this->writer->createDocument(kind: 'informatieobject');

		$run = new InformatieobjectRun(root: $root, object: $object, facts: $this->sources->sourceReader->coreFacts(object: $object));
		$this->addDescription(run: $run);
		$this->addTemporalCoverage(run: $run);
		$this->addEvents(run: $run);
		$this->addRating(run: $run);
		$this->addRetentionPeriod(run: $run);
		$this->addInformatiecategorie(run: $run);
		$this->addRepresentations(parent: $root, files: $files);
		$this->addArchiefvormer(parent: $root);
		$this->addParties(run: $run);
		$this->addUseRestriction(run: $run);

		return $this->writer->serialise(dom: $dom);
	}//end generate()

	/**
	 * Refuse to TRANSFER a record whose retention period is unknown.
	 *
	 * Delegates to {@see MdtoPreconditions::assertTransferPreconditions()};
	 * kept on the generator because the packaging path already holds one.
	 *
	 * @param ObjectEntity $object The object about to be packaged.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the object has no retention period.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-mdto-generator-must-report-the-limits-of-its-own-validation
	 */
	public function assertTransferPreconditions(ObjectEntity $object): void {
		$this->preconditions->assertTransferPreconditions(object: $object);
	}//end assertTransferPreconditions()

	/**
	 * List the MDTO-required elements a document fills with a default.
	 *
	 * @param ObjectEntity $object The object to inspect.
	 *
	 * @return list<string> One line per defaulted element; empty when there is none.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-mdto-generator-must-report-the-limits-of-its-own-validation
	 */
	public function collectMdtoRequiredElementGaps(ObjectEntity $object): array {
		return $this->preconditions->collectMdtoRequiredElementGaps(object: $object);
	}//end collectMdtoRequiredElementGaps()

	/**
	 * Generate the MDTO `bestand` document for one of the object's files.
	 *
	 * @param ObjectEntity $object The object the file represents.
	 * @param array $file The file metadata (name, size, format, checksum, checksumDate).
	 *
	 * @return string The MDTO XML.
	 *
	 * @throws InvalidArgumentException If a file input is missing or malformed.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
	 */
	public function generateBestand(ObjectEntity $object, array $file): string {
		$missing = $this->bestandGenerator->missingInputs(files: [$file]);
		if (empty($missing) === false) {
			throw new InvalidArgumentException(
				'Cannot generate the MDTO bestand document for object ' . $object->getUuid()
				. '. These inputs are missing or malformed: ' . implode(', ', $missing)
			);
		}

		return $this->bestandGenerator->generate(file: $file, represents: $this->representedObject(object: $object));
	}//end generateBestand()





	/**
	 * The object's own naam and identificatie, as a document refers to it.
	 *
	 * Used for this document's `identificatie` and for every file's
	 * `isRepresentatieVan`, so the two cannot disagree.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array{naam: string, kenmerk: string, bron: string} The reference.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
	 */
	private function representedObject(ObjectEntity $object): array {
		return [
			'naam' => $this->sources->sourceReader->name(object: $object),
			'kenmerk' => (string)$object->getUuid(),
			'bron' => $this->organisationIdentifier(),
		];
	}//end representedObject()

	/**
	 * Add the aggregatieniveau element when the object declares one.
	 *
	 * The sources and the rule for when there is no value belong to
	 * {@see MdtoSourceReader::aggregationLevel()}. Labels come from MDTO's
	 * `Aggregatieniveaus` begrippenlijst: Archief, Serie, Dossier, Archiefstuk.
	 *
	 * @param InformatieobjectRun $run The document being written: its element, object and facts.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-emit-mdto-aggregatieniveau-beperkinggebruik-and-dekkingintijd-from-their-declared-sources
	 */
	private function addAggregationLevel(InformatieobjectRun $run): void {
		$level = $this->sources->sourceReader->aggregationLevel(object: $run->object);
		$term  = MdtoBegrip::optional(label: ($level['label'] ?? null), list: self::AGGREGATION_LEVEL_LIST, code: ($level['code'] ?? null));

		$this->addOptionalBegrip(parent: $run->root, terms: ['aggregatieniveau' => $term]);
	}//end addAggregationLevel()

	/**
	 * Add dekkingInTijd elements when the object declares them.
	 *
	 * {@see MdtoSourceReader::temporalCoverage()} has already dropped every
	 * half-declared or malformed entry.
	 *
	 * @param InformatieobjectRun $run The document being written: its element, object and facts.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-emit-mdto-aggregatieniveau-beperkinggebruik-and-dekkingintijd-from-their-declared-sources
	 */
	private function addTemporalCoverage(InformatieobjectRun $run): void {
		foreach ($this->sources->sourceReader->temporalCoverage(object: $run->object) as $entry) {
			$coverage = $this->writer->element(parent: $run->root, name: 'dekkingInTijd');
			$this->writer->begrip(parent: $coverage, name: 'dekkingInTijdType', begrip: new MdtoBegrip(label: $entry['type'], list: 'DekkingInTijdType'));
			$this->writer->text(parent: $coverage, name: 'dekkingInTijdBegindatum', content: $entry['start']);

			$this->writer->textsIfPresent(parent: $coverage, texts: ['dekkingInTijdEinddatum' => $entry['end']]);
		}
	}//end addTemporalCoverage()

	/**
	 * Add event elements derived from the audit trail.
	 *
	 * The selection and the bound belong to {@see MdtoEventMapper}, which
	 * documents the mapping decision. This method only serialises.
	 *
	 * @param InformatieobjectRun $run The document being written: its element, object and facts.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-derive-mdto-event-entries-from-the-audit-trail
	 */
	private function addEvents(InformatieobjectRun $run): void {
		foreach ($this->sources->eventMapper->forObject(object: $run->object) as $event) {
			$this->addEvent(parent: $run->root, event: $event);
		}
	}//end addEvents()

	/**
	 * Refuse missing generator inputs, and log the required elements that get a default.
	 *
	 * @param ObjectEntity $object The object to export.
	 * @param array        $files  Associated file metadata.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException If a precondition is not met.
	 */
	private function checkInputs(ObjectEntity $object, array $files): void {
		$this->preconditions->assertGeneratorPreconditions(object: $object, files: $files);
		$this->preconditions->reportMdtoRequiredElementGaps(object: $object);
	}//end checkInputs()

	/**
	 * Add the identification, name, aggregation level, classification and description.
	 *
	 * @param InformatieobjectRun $run The document being written: its element, object and facts.
	 *
	 * @return void
	 */
	private function addDescription(InformatieobjectRun $run): void {
		$self = $this->representedObject(object: $run->object);
		$this->writer->identificatie(parent: $run->root, name: 'identificatie', kenmerk: $self['kenmerk'], bron: $self['bron']);
		$this->writer->text(parent: $run->root, name: 'naam', content: $self['naam']);
		$this->addAggregationLevel(run: $run);
		$this->addClassification(run: $run);
		$this->writer->textsIfPresent(parent: $run->root, texts: ['omschrijving' => $run->facts['description']]);
	}//end addDescription()

	/**
	 * Add one `event`: its type, its time and, when known, its responsible actor.
	 *
	 * @param DOMElement           $parent The informatieobject element.
	 * @param array<string, mixed> $event  The mapped event.
	 *
	 * @return void
	 */
	private function addEvent(DOMElement $parent, array $event): void {
		$element = $this->writer->element(parent: $parent, name: 'event');
		$this->writer->begrip(parent: $element, name: 'eventType', begrip: new MdtoBegrip(label: $event['type'], list: MdtoEventMapper::EVENT_TYPE_LIST));
		$this->writer->textsIfPresent(parent: $element, texts: ['eventTijd' => $event['time']]);
		if ($event['actorName'] !== null) {
			$this->addEventActor(element: $element, event: $event);
		}
	}//end addEvent()

	/**
	 * Name the actor responsible for an event.
	 *
	 * @param DOMElement           $element The event element.
	 * @param array<string, mixed> $event   The event, with an actor name.
	 *
	 * @return void
	 */
	private function addEventActor(DOMElement $element, array $event): void {
		$this->writer->verwijzing(
			parent: $element,
			name: 'eventVerantwoordelijkeActor',
			verwijzing: new MdtoVerwijzing(
				naam: $event['actorName'],
				kenmerk: $event['actorId'],
				bron: $this->organisationIdentifier()
			)
		);
	}//end addEventActor()

	/**
	 * Add `waardering`, a `begripGegevens` from the closed Waarderingen list.
	 *
	 * @param InformatieobjectRun $run The document being written: its element, object and facts.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
	 */
	private function addRating(InformatieobjectRun $run): void {
		[$code, $label] = self::APPRAISAL_MAP[(string)$run->facts['appraisal']];

		$this->writer->begrip(parent: $run->root, name: 'waardering', begrip: new MdtoBegrip(label: $label, list: self::APPRAISAL_LIST, code: $code));
	}//end addRating()

	/**
	 * Add `bewaartermijn`, a `termijnGegevens`.
	 *
	 * `termijnLooptijd` carries the duration. `termijnEinddatum` carries the
	 * archiefactiedatum when the record has one, which is the date the
	 * retention period ends: RetentionService computes it from the same
	 * duration, and the TMLO block stores it under the same name.
	 *
	 * @param InformatieobjectRun $run The document being written: its element, object and facts.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
	 */
	private function addRetentionPeriod(InformatieobjectRun $run): void {
		$period = $run->facts['retentionPeriod'];
		$end = $run->facts['disposalDate'];
		if ($period === null && $end === null) {
			return;
		}

		$term = $this->writer->element(parent: $run->root, name: 'bewaartermijn');
		$this->writer->textsIfPresent(parent: $term, texts: ['termijnLooptijd' => $period, 'termijnEinddatum' => $end]);
	}//end addRetentionPeriod()

	/**
	 * Add `informatiecategorie`, a `begripGegevens`, when the record has a category.
	 *
	 * Omitted, not defaulted, when there is none: the XSD makes the element
	 * `minOccurs="0"`, and the placeholder `onbekend` this used to write was a
	 * value nobody had recorded. The source and its begrippenlijst belong to
	 * {@see MdtoSourceReader::disposalCategory()}.
	 *
	 * @param InformatieobjectRun $run The document being written: its element, object and facts.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
	 */
	private function addInformatiecategorie(InformatieobjectRun $run): void {
		$category = $run->facts['disposalCategory'];

		$term     = MdtoBegrip::optional(label: ($category['label'] ?? null), list: ($category['list'] ?? self::CATEGORY_LIST_FALLBACK));

		$this->addOptionalBegrip(parent: $run->root, terms: ['informatiecategorie' => $term]);
	}//end addInformatiecategorie()

	/**
	 * Add `classificatie` when the object carries a classification code.
	 *
	 * This is not the disposal category: MDTO keeps the classification scheme
	 * and the selectielijst category as separate elements, and so does TMLO.
	 *
	 * @param InformatieobjectRun $run The document being written: its element, object and facts.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/tmlo-export/spec.md#requirement-mdto-compliant-xml-export
	 */
	private function addClassification(InformatieobjectRun $run): void {
		$this->addOptionalBegrip(
			parent: $run->root,
			terms: ['classificatie' => MdtoBegrip::optional(label: $run->facts['classification'], list: self::CLASSIFICATION_LIST)]
		);
	}//end addClassification()

	/**
	 * Add a `begripGegevens` element, or nothing when there is no label.
	 *
	 * Every optional begrip element follows the same rule: emit it when the
	 * object supplies a term, omit it entirely otherwise, never a placeholder.
	 * One method so that rule cannot drift between the three.
	 *
	 * @param DOMElement                      $parent The informatieobject element.
	 * @param array<string, MdtoBegrip|null> $terms  Element name (without prefix) to term, in order; a null term
	 *                                               omits its element ({@see MdtoBegrip::optional()}).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-emit-mdto-aggregatieniveau-beperkinggebruik-and-dekkingintijd-from-their-declared-sources
	 */
	private function addOptionalBegrip(DOMElement $parent, array $terms): void {
		foreach ($terms as $name => $begrip) {
			if ($begrip !== null) {
				$this->writer->begrip(parent: $parent, name: (string)$name, begrip: $begrip);
			}
		}
	}//end addOptionalBegrip()

	/**
	 * Add one `heeftRepresentatie` reference per file.
	 *
	 * The reference uses {@see MdtoBestandGenerator::identification()}, the
	 * same identification the file's own document carries.
	 *
	 * @param DOMElement $parent The informatieobject element.
	 * @param array $files The file metadata.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
	 */
	private function addRepresentations(DOMElement $parent, array $files): void {
		foreach ($files as $file) {
			$identification = $this->bestandGenerator->identification(file: $file);
			$this->writer->verwijzing(
				parent: $parent,
				name: 'heeftRepresentatie',
				verwijzing: new MdtoVerwijzing(
					naam: (string)$file['name'],
					kenmerk: $identification['kenmerk'],
					bron: $identification['bron']
				)
			);
		}
	}//end addRepresentations()

	/**
	 * Add the archiefvormer element.
	 *
	 * @param DOMElement $parent The informatieobject element.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-generate-mdto-compliant-xml-metadata-per-object
	 */
	private function addArchiefvormer(DOMElement $parent): void {
		$this->writer->verwijzing(
			parent: $parent,
			name: 'archiefvormer',
			verwijzing: new MdtoVerwijzing(
				naam: $this->sources->appConfig->getValueString('openregister', 'organisation_name', 'OpenRegister'),
				kenmerk: $this->organisationIdentifier(),
				bron: 'OpenRegister'
			)
		);
	}//end addArchiefvormer()

	/**
	 * Add one `betrokkene` per party the schema's archival block names.
	 *
	 * The relation is the property the party came from (`aanvrager`), on a
	 * free list, because MDTO prescribes none for it; the actor is the party's
	 * name, with its OpenRegister id when it has one. A schema that names no
	 * party gets no `betrokkene`, exactly as before.
	 *
	 * @param InformatieobjectRun $run The document being written: its element, object and facts.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archival-conformance/tasks.md#task-1.2
	 */
	private function addParties(InformatieobjectRun $run): void {
		foreach ($this->sources->sourceReader->parties(object: $run->object) as $party) {
			$role = $party['role'];
			if ($role === '') {
				$role = 'betrokkene';
			}

			$element = $this->writer->element(parent: $run->root, name: 'betrokkene');
			$this->writer->begrip(
				parent: $element,
				name: 'betrokkeneTypeRelatie',
				begrip: new MdtoBegrip(label: $role, list: self::PARTY_RELATION_LIST)
			);
			$this->writer->verwijzing(
				parent: $element,
				name: 'betrokkeneActor',
				verwijzing: new MdtoVerwijzing(naam: $party['name'], kenmerk: $party['id'], bron: 'OpenRegister')
			);
		}
	}//end addParties()

	/**
	 * Add `beperkingGebruik`, which the XSD requires at least once.
	 *
	 * {@see MdtoSourceReader::useRestriction()} decides whether a restriction
	 * is known. When none is, the element carries
	 * {@see self::USE_RESTRICTION_UNRECORDED}, the standard's own term for a
	 * restriction that has not been recorded.
	 *
	 * @param InformatieobjectRun $run The document being written: its element, object and facts.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-emit-mdto-aggregatieniveau-beperkinggebruik-and-dekkingintijd-from-their-declared-sources
	 */
	private function addUseRestriction(InformatieobjectRun $run): void {
		$restriction = $this->useRestrictionOf(run: $run);

		$element = $this->writer->element(parent: $run->root, name: 'beperkingGebruik');
		$this->writer->begrip(
			parent: $element,
			name: 'beperkingGebruikType',
			begrip: new MdtoBegrip(label: $restriction['type'], list: self::USE_RESTRICTION_LIST)
		);
		$this->writer->textsIfPresent(parent: $element, texts: ['beperkingGebruikNadereBeschrijving' => $restriction['description']]);
		if ($restriction['startDate'] !== null) {
			$term = $this->writer->element(parent: $element, name: 'beperkingGebruikTermijn');
			$this->writer->text(parent: $term, name: 'termijnStartdatumLooptijd', content: $restriction['startDate']);
		}
	}//end addUseRestriction()

	/**
	 * The object's use restriction, or the "unrecorded" one when none is recorded.
	 *
	 * @param InformatieobjectRun $run The information object being written.
	 *
	 * @return array{type: string, description: string|null, startDate: string|null} The restriction.
	 */
	private function useRestrictionOf(InformatieobjectRun $run): array {
		return ($this->sources->sourceReader->useRestriction(object: $run->object)
			?? ['type' => self::USE_RESTRICTION_UNRECORDED, 'description' => null, 'startDate' => null]);
	}//end useRestrictionOf()


	/**
	 * The configured organisation identifier.
	 *
	 * @return string The identifier, falling back to the app name.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-generate-mdto-compliant-xml-metadata-per-object
	 */
	private function organisationIdentifier(): string {
		return $this->sources->appConfig->getValueString('openregister', 'organisation_identifier', 'OpenRegister');
	}//end organisationIdentifier()

}//end class
