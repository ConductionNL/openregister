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
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

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
	 * @param IAppConfig $appConfig The app configuration for organisation settings.
	 * @param LoggerInterface $logger Logger for error and info messages.
	 * @param MdtoEventMapper $eventMapper Source of the MDTO event history.
	 * @param MdtoSourceReader $sourceReader Resolver for the declared archival values.
	 * @param MdtoDocumentWriter $writer The XML primitives.
	 * @param MdtoBestandGenerator $bestandGenerator Builder of a file's own document.
	 * @param MdtoPreconditions $preconditions What must hold before a document is worth writing.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly MdtoEventMapper $eventMapper,
		private readonly MdtoSourceReader $sourceReader,
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
		$this->preconditions->assertGeneratorPreconditions(object: $object, files: $files);
		$this->preconditions->reportMdtoRequiredElementGaps(object: $object);

		[$dom, $root] = $this->writer->createDocument(kind: 'informatieobject');

		$facts = $this->sourceReader->coreFacts(object: $object);
		$self = $this->representedObject(object: $object);
		$this->writer->identificatie(parent: $root, name: 'identificatie', kenmerk: $self['kenmerk'], bron: $self['bron']);
		$this->writer->text(parent: $root, name: 'naam', content: $self['naam']);
		$this->addAggregationLevel(parent: $root, object: $object);
		$this->addClassification(parent: $root, facts: $facts);

		$this->writer->textIfPresent(
			parent: $root,
			name: 'omschrijving',
			content: $facts['description']
		);

		$this->addTemporalCoverage(parent: $root, object: $object);
		$this->addEvents(parent: $root, object: $object);
		$this->addRating(parent: $root, facts: $facts);
		$this->addRetentionPeriod(parent: $root, facts: $facts);
		$this->addInformatiecategorie(parent: $root, facts: $facts);
		$this->addRepresentations(parent: $root, files: $files);
		$this->addArchiefvormer(parent: $root);
		$this->addUseRestriction(parent: $root, object: $object);

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
			'naam' => $this->sourceReader->name(object: $object),
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
	 * @param DOMElement $parent The informatieobject element.
	 * @param ObjectEntity $object The source object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-emit-mdto-aggregatieniveau-beperkinggebruik-and-dekkingintijd-from-their-declared-sources
	 */
	private function addAggregationLevel(DOMElement $parent, ObjectEntity $object): void {
		$level = $this->sourceReader->aggregationLevel(object: $object);

		$this->addOptionalBegrip(
			parent: $parent,
			name: 'aggregatieniveau',
			label: ($level['label'] ?? null),
			code: ($level['code'] ?? null),
			list: self::AGGREGATION_LEVEL_LIST
		);
	}//end addAggregationLevel()

	/**
	 * Add dekkingInTijd elements when the object declares them.
	 *
	 * {@see MdtoSourceReader::temporalCoverage()} has already dropped every
	 * half-declared or malformed entry.
	 *
	 * @param DOMElement $parent The informatieobject element.
	 * @param ObjectEntity $object The source object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-emit-mdto-aggregatieniveau-beperkinggebruik-and-dekkingintijd-from-their-declared-sources
	 */
	private function addTemporalCoverage(DOMElement $parent, ObjectEntity $object): void {
		foreach ($this->sourceReader->temporalCoverage(object: $object) as $entry) {
			$coverage = $this->writer->element(parent: $parent, name: 'dekkingInTijd');
			$this->writer->begrip(
				parent: $coverage,
				name: 'dekkingInTijdType',
				label: $entry['type'],
				code: null,
				list: 'DekkingInTijdType'
			);
			$this->writer->text(parent: $coverage, name: 'dekkingInTijdBegindatum', content: $entry['start']);

			$this->writer->textIfPresent(parent: $coverage, name: 'dekkingInTijdEinddatum', content: $entry['end']);
		}
	}//end addTemporalCoverage()

	/**
	 * Add event elements derived from the audit trail.
	 *
	 * The selection and the bound belong to {@see MdtoEventMapper}, which
	 * documents the mapping decision. This method only serialises.
	 *
	 * @param DOMElement $parent The informatieobject element.
	 * @param ObjectEntity $object The source object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-derive-mdto-event-entries-from-the-audit-trail
	 */
	private function addEvents(DOMElement $parent, ObjectEntity $object): void {
		foreach ($this->eventMapper->forObject(object: $object) as $event) {
			$element = $this->writer->element(parent: $parent, name: 'event');

			$this->writer->begrip(
				parent: $element,
				name: 'eventType',
				label: $event['type'],
				code: null,
				list: MdtoEventMapper::EVENT_TYPE_LIST
			);

			$this->writer->textIfPresent(parent: $element, name: 'eventTijd', content: $event['time']);

			if ($event['actorName'] !== null) {
				$this->writer->verwijzing(
					parent: $element,
					name: 'eventVerantwoordelijkeActor',
					verwijzingNaam: $event['actorName'],
					kenmerk: $event['actorId'],
					bron: $this->organisationIdentifier()
				);
			}
		}
	}//end addEvents()

	/**
	 * Add `waardering`, a `begripGegevens` from the closed Waarderingen list.
	 *
	 * @param DOMElement $parent The informatieobject element.
	 * @param array $facts The object's core archival facts.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
	 */
	private function addRating(DOMElement $parent, array $facts): void {
		[$code, $label] = self::APPRAISAL_MAP[(string)$facts['appraisal']];

		$this->writer->begrip(parent: $parent, name: 'waardering', label: $label, code: $code, list: self::APPRAISAL_LIST);
	}//end addRating()

	/**
	 * Add `bewaartermijn`, a `termijnGegevens`.
	 *
	 * `termijnLooptijd` carries the duration. `termijnEinddatum` carries the
	 * archiefactiedatum when the record has one, which is the date the
	 * retention period ends: RetentionService computes it from the same
	 * duration, and the TMLO block stores it under the same name.
	 *
	 * @param DOMElement $parent The informatieobject element.
	 * @param array $facts The object's core archival facts.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
	 */
	private function addRetentionPeriod(DOMElement $parent, array $facts): void {
		$period = $facts['retentionPeriod'];
		$end = $facts['disposalDate'];
		if ($period === null && $end === null) {
			return;
		}

		$term = $this->writer->element(parent: $parent, name: 'bewaartermijn');
		$this->writer->textIfPresent(parent: $term, name: 'termijnLooptijd', content: $period);
		$this->writer->textIfPresent(parent: $term, name: 'termijnEinddatum', content: $end);
	}//end addRetentionPeriod()

	/**
	 * Add `informatiecategorie`, a `begripGegevens`, when the record has a category.
	 *
	 * Omitted, not defaulted, when there is none: the XSD makes the element
	 * `minOccurs="0"`, and the placeholder `onbekend` this used to write was a
	 * value nobody had recorded. The source and its begrippenlijst belong to
	 * {@see MdtoSourceReader::disposalCategory()}.
	 *
	 * @param DOMElement $parent The informatieobject element.
	 * @param array $facts The object's core archival facts.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
	 */
	private function addInformatiecategorie(DOMElement $parent, array $facts): void {
		$category = $facts['disposalCategory'];

		$this->addOptionalBegrip(
			parent: $parent,
			name: 'informatiecategorie',
			label: ($category['label'] ?? null),
			code: null,
			list: ($category['list'] ?? self::CATEGORY_LIST_FALLBACK)
		);
	}//end addInformatiecategorie()

	/**
	 * Add `classificatie` when the object carries a classification code.
	 *
	 * This is not the disposal category: MDTO keeps the classification scheme
	 * and the selectielijst category as separate elements, and so does TMLO.
	 *
	 * @param DOMElement $parent The informatieobject element.
	 * @param array $facts The object's core archival facts.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/tmlo-export/spec.md#requirement-mdto-compliant-xml-export
	 */
	private function addClassification(DOMElement $parent, array $facts): void {
		$this->addOptionalBegrip(
			parent: $parent,
			name: 'classificatie',
			label: $facts['classification'],
			code: null,
			list: self::CLASSIFICATION_LIST
		);
	}//end addClassification()

	/**
	 * Add a `begripGegevens` element, or nothing when there is no label.
	 *
	 * Every optional begrip element follows the same rule: emit it when the
	 * object supplies a term, omit it entirely otherwise, never a placeholder.
	 * One method so that rule cannot drift between the three.
	 *
	 * @param DOMElement $parent The informatieobject element.
	 * @param string $name The element name, without prefix.
	 * @param string|null $label The term, or null to omit the element.
	 * @param string|null $code The code, omitted when null.
	 * @param string $list The begrippenlijst name.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-emit-mdto-aggregatieniveau-beperkinggebruik-and-dekkingintijd-from-their-declared-sources
	 */
	private function addOptionalBegrip(
		DOMElement $parent,
		string $name,
		?string $label,
		?string $code,
		string $list,
	): void {
		if ($label === null) {
			return;
		}

		$this->writer->begrip(parent: $parent, name: $name, label: $label, code: $code, list: $list);
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
				verwijzingNaam: (string)$file['name'],
				kenmerk: $identification['kenmerk'],
				bron: $identification['bron']
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
			verwijzingNaam: $this->appConfig->getValueString('openregister', 'organisation_name', 'OpenRegister'),
			kenmerk: $this->organisationIdentifier(),
			bron: 'OpenRegister'
		);
	}//end addArchiefvormer()

	/**
	 * Add `beperkingGebruik`, which the XSD requires at least once.
	 *
	 * {@see MdtoSourceReader::useRestriction()} decides whether a restriction
	 * is known. When none is, the element carries
	 * {@see self::USE_RESTRICTION_UNRECORDED}, the standard's own term for a
	 * restriction that has not been recorded.
	 *
	 * @param DOMElement $parent The informatieobject element.
	 * @param ObjectEntity $object The source object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-emit-mdto-aggregatieniveau-beperkinggebruik-and-dekkingintijd-from-their-declared-sources
	 */
	private function addUseRestriction(DOMElement $parent, ObjectEntity $object): void {
		$restriction = $this->sourceReader->useRestriction(object: $object);
		if ($restriction === null) {
			$restriction = ['type' => self::USE_RESTRICTION_UNRECORDED, 'description' => null, 'startDate' => null];
		}

		$element = $this->writer->element(parent: $parent, name: 'beperkingGebruik');
		$this->writer->begrip(
			parent: $element,
			name: 'beperkingGebruikType',
			label: $restriction['type'],
			code: null,
			list: self::USE_RESTRICTION_LIST
		);

		$this->writer->textIfPresent(
			parent: $element,
			name: 'beperkingGebruikNadereBeschrijving',
			content: $restriction['description']
		);

		if ($restriction['startDate'] !== null) {
			$term = $this->writer->element(parent: $element, name: 'beperkingGebruikTermijn');
			$this->writer->text(parent: $term, name: 'termijnStartdatumLooptijd', content: $restriction['startDate']);
		}
	}//end addUseRestriction()


	/**
	 * The configured organisation identifier.
	 *
	 * @return string The identifier, falling back to the app name.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-generate-mdto-compliant-xml-metadata-per-object
	 */
	private function organisationIdentifier(): string {
		return $this->appConfig->getValueString('openregister', 'organisation_identifier', 'OpenRegister');
	}//end organisationIdentifier()

}//end class
