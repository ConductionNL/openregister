<?php

/**
 * OpenRegister MDTO XML Generator
 *
 * Generates MDTO metadata for objects eligible for e-Depot transfer.
 * Targets the MDTO (Metagegevens Duurzaam Toegankelijke Overheidsinformatie)
 * schema published by the Nationaal Archief, XML syntax version 1.0.1.
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
 * @spec openspec/specs/edepot-transfer/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Generator for MDTO metadata documents.
 *
 * ## What this generator does and does not claim
 *
 * It emits a well-formed XML document in the MDTO namespace carrying the
 * elements listed below. It does NOT claim the result is valid MDTO, and it
 * cannot: the MDTO XSD (`MDTO-XML1.0.1.xsd`) is not vendored in this
 * repository and no code path validates against it. Every statement about
 * which elements MDTO requires is cited in the spec requirements this class
 * points at, taken from the Nationaal Archief metagegevensschema and XSD.
 *
 * Emitted: `identificatie`, `naam`, `aggregatieniveau`, `dekkingInTijd`,
 * `event`, `waardering`, `bewaartermijn`, `informatiecategorie`,
 * `archiefvormer`, `beperkingGebruik`, plus a `toelichting` and nested
 * `bestand` elements.
 *
 * ## Known deviations from MDTO-XML1.0.1, NOT fixed here
 *
 * These predate this class's current shape and are recorded so nobody reads a
 * successful `generate()` as conformance. Each one changes the shape of an
 * element that already ships, so correcting them is a separate change with a
 * blast radius that reaches every receiving e-Depot.
 *
 * 1. The document root is `mdto:informatieobject`. The XSD declares the root
 *    element `MDTO`, with `informatieobject` and `bestand` as a choice inside
 *    it.
 * 2. `bestand` is emitted as a CHILD of `informatieobject`. The XSD makes it a
 *    sibling, related back by `isRepresentatieVan`.
 * 3. `waardering`, `informatiecategorie` and `bestandsformaat` are emitted as
 *    plain strings. The XSD types all three as `begripGegevens`.
 * 4. `bewaartermijn` is emitted as a plain ISO-8601 duration string. The XSD
 *    types it as `termijnGegevens`.
 * 5. `checksumDatum` is never emitted although the XSD requires it, and
 *    `checksumAlgoritme` is a plain string rather than `begripGegevens`.
 * 6. `toelichting` is not an MDTO element at all. The XSD's nearest equivalent
 *    is `omschrijving`.
 *
 * The elements this class ADDS are shaped per the XSD, so they are correct
 * even while their siblings above are not.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class MdtoXmlGenerator {

	/**
	 * MDTO namespace URI.
	 */
	public const MDTO_NAMESPACE = 'https://www.nationaalarchief.nl/mdto';

	/**
	 * MDTO namespace prefix.
	 */
	public const MDTO_PREFIX = 'mdto';

	/**
	 * The MDTO begrippenlijst that `aggregatieniveau` labels come from.
	 */
	public const AGGREGATION_LEVEL_LIST = 'Aggregatieniveaus';

	/**
	 * The MDTO begrippenlijst that `beperkingGebruikType` labels come from.
	 */
	public const USE_RESTRICTION_LIST = 'BeperkingGebruikTypeLijst';

	/**
	 * Mapping from archiefnominatie values to MDTO waardering values.
	 *
	 * @var array<string, string>
	 */
	private const WAARDERING_MAP = [
		'vernietigen' => 'vernietigen',
		'bewaren' => 'bewaren',
		'nog_niet_bepaald' => 'nog niet bepaald',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app configuration for organisation settings.
	 * @param LoggerInterface $logger Logger for error and info messages.
	 * @param MdtoEventMapper $eventMapper Source of the MDTO event history.
	 * @param MdtoSourceReader $sourceReader Resolver for the declared archival values.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly MdtoEventMapper $eventMapper,
		private readonly MdtoSourceReader $sourceReader,
	) {
	}//end __construct()

	/**
	 * Generate MDTO XML for an object.
	 *
	 * Element order follows the XSD's `informatieobjectType` sequence for the
	 * elements that appear in it; the two that do not (`toelichting` and the
	 * nested `bestand`) come last.
	 *
	 * A successful return does NOT mean the document is valid MDTO. See
	 * {@see self::assertGeneratorPreconditions()} for what was actually
	 * checked and {@see self::collectMdtoRequiredElementGaps()} for what MDTO
	 * requires that this document will be missing.
	 *
	 * @param ObjectEntity $object The object to generate XML for.
	 * @param array $files Associated file metadata (name, size, format, checksum).
	 *
	 * @return string The generated MDTO XML string.
	 *
	 * @throws InvalidArgumentException If a precondition is not met.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-generate-mdto-compliant-xml-metadata-per-object
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-mdto-generator-must-report-the-limits-of-its-own-validation
	 */
	public function generate(ObjectEntity $object, array $files = []): string {
		$retention = ($object->getRetention() ?? []);

		$this->assertGeneratorPreconditions(object: $object, retention: $retention, files: $files);
		$this->reportMdtoRequiredElementGaps(object: $object, files: $files);

		$dom = new DOMDocument('1.0', 'UTF-8');
		$dom->formatOutput = true;

		$root = $dom->createElementNS(self::MDTO_NAMESPACE, self::MDTO_PREFIX . ':informatieobject');
		$dom->appendChild($root);

		$this->addIdentification(dom: $dom, parent: $root, object: $object);
		$this->addName(dom: $dom, parent: $root, object: $object);
		$this->addAggregationLevel(dom: $dom, parent: $root, object: $object);
		$this->addTemporalCoverage(dom: $dom, parent: $root, object: $object);
		$this->addEvents(dom: $dom, parent: $root, object: $object);
		$this->addRating(dom: $dom, parent: $root, retention: $retention);
		$this->addRetentionPeriod(dom: $dom, parent: $root, retention: $retention);
		$this->addInformatiecategorie(dom: $dom, parent: $root, retention: $retention);
		$this->addArchiefvormer(dom: $dom, parent: $root);
		$this->addUseRestriction(dom: $dom, parent: $root, object: $object);

		if (empty($retention['toelichting']) === false) {
			$this->addTextElement(dom: $dom, parent: $root, name: 'toelichting', content: $retention['toelichting']);
		}

		foreach ($files as $file) {
			$this->addFile(dom: $dom, parent: $root, file: $file);
		}

		$xml = $dom->saveXML();
		if ($xml === false) {
			throw new InvalidArgumentException('Failed to generate MDTO XML');
		}

		return $xml;
	}//end generate()

	/**
	 * List the MDTO-required elements this document will NOT carry.
	 *
	 * This is the honest counterpart to
	 * {@see self::assertGeneratorPreconditions()}. The preconditions are what
	 * the generator needs in order to emit anything; this is what MDTO's XSD
	 * marks `minOccurs="1"` and this object cannot supply. It reports rather
	 * than throws: refusing a transfer over an element no source in this
	 * system writes would stop every transfer, and that is a decision for the
	 * archivist and not for a serialiser.
	 *
	 * @param ObjectEntity $object The object to inspect.
	 * @param array $files Associated file metadata.
	 *
	 * @return list<string> One line per gap; empty when there is none.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-mdto-generator-must-report-the-limits-of-its-own-validation
	 */
	public function collectMdtoRequiredElementGaps(ObjectEntity $object, array $files = []): array {
		$gaps = [];

		if ($this->sourceReader->useRestriction(object: $object) === null) {
			$gaps[] = 'beperkingGebruik: MDTO-XML1.0.1 declares minOccurs="1" on informatieobject, '
				. 'and this object declares no use restriction and carries no active legal hold';
		}

		if (empty($files) === false) {
			$gaps[] = 'bestand/checksum/checksumDatum: MDTO-XML1.0.1 declares minOccurs="1" on '
				. 'checksumGegevens and this generator never emits it';
		}

		return $gaps;
	}//end collectMdtoRequiredElementGaps()

	/**
	 * Check the inputs this generator needs, and only those.
	 *
	 * ## Read this before treating a pass as MDTO validity
	 *
	 * This method establishes exactly one thing: the generator has the inputs
	 * it needs to emit the elements it emits. It is NOT an MDTO validity
	 * check, it does not consult an XSD, and a document that passes it can
	 * still be rejected by a receiving e-Depot. The class docblock lists six
	 * structural deviations that this method does not look at.
	 *
	 * What it checks, and on whose authority:
	 *
	 * - `uuid` and the `organisation_identifier` app setting, which fill
	 *   `identificatie/identificatieKenmerk` and `identificatie/identificatieBron`.
	 *   Both are `minOccurs="1"` in the XSD's `identificatieGegevens`, and
	 *   `identificatie` itself is `minOccurs="1"` on `objectType`.
	 * - a non-empty `naam`, `minOccurs="1"` on `objectType`.
	 * - `retention.archiefnominatie`, which fills `waardering`,
	 *   `minOccurs="1"` on `informatieobjectType`.
	 * - per file: `name`, `size`, `format` and `checksum`. `omvang`,
	 *   `bestandsformaat` and `checksum` are all `minOccurs="1"` on
	 *   `bestandType`, and `naam` is inherited from `objectType`. Before this
	 *   check a file array missing a key produced a PHP warning and an empty
	 *   element rather than a refusal.
	 * - `retention.bewaartermijn`. This one is a LOCAL rule and stricter than
	 *   MDTO: the XSD makes `bewaartermijn` `minOccurs="0"`. openregister
	 *   refuses to transfer a record whose retention period is unknown, which
	 *   is this repository's own policy and not the standard's.
	 *
	 * @param ObjectEntity $object The object to check.
	 * @param array<string,mixed> $retention The retention metadata.
	 * @param array $files Associated file metadata.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException If an input is missing.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-mdto-generator-must-report-the-limits-of-its-own-validation
	 */
	private function assertGeneratorPreconditions(ObjectEntity $object, array $retention, array $files): void {
		$missing = [];

		if (empty($object->getUuid()) === true) {
			$missing[] = 'uuid';
		}

		if (empty($this->resolveName(object: $object)) === true) {
			$missing[] = 'naam';
		}

		if (empty($retention['archiefnominatie']) === true) {
			$missing[] = 'retention.archiefnominatie';
		}

		if (empty($retention['bewaartermijn']) === true) {
			$missing[] = 'retention.bewaartermijn';
		}

		$archiefvormer = $this->appConfig->getValueString('openregister', 'organisation_identifier', '');
		if (empty($archiefvormer) === true) {
			$missing[] = 'app_setting:organisation_identifier';
		}

		$missing = array_merge($missing, $this->missingFileInputs(files: $files));

		if (empty($missing) === false) {
			$missingStr = implode(', ', $missing);
			$this->logger->error(
				message: '[MdtoXmlGenerator] Cannot generate MDTO XML, inputs missing: ' . $missingStr,
				context: ['objectUuid' => $object->getUuid()]
			);
			throw new InvalidArgumentException(
				'Cannot generate MDTO XML for object ' . $object->getUuid()
				. '. These generator inputs are missing: ' . $missingStr
				. '. This check covers the generator inputs only and is not an MDTO validity check.'
			);
		}
	}//end assertGeneratorPreconditions()

	/**
	 * Name the per-file inputs a `bestand` element cannot be built without.
	 *
	 * `omvang`, `bestandsformaat` and `checksum` are `minOccurs="1"` on the
	 * XSD's `bestandType`, and `naam` is inherited from `objectType`. Before
	 * this check a file array missing a key produced a PHP warning and an
	 * empty element rather than a refusal.
	 *
	 * @param array $files Associated file metadata.
	 *
	 * @return list<string> One entry per missing input.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-mdto-generator-must-report-the-limits-of-its-own-validation
	 */
	private function missingFileInputs(array $files): array {
		$missing = [];

		foreach ($files as $index => $file) {
			foreach (['name', 'size', 'format', 'checksum'] as $key) {
				if (($file[$key] ?? '') === '') {
					$missing[] = 'file[' . $index . '].' . $key;
				}
			}
		}

		return $missing;
	}//end missingFileInputs()

	/**
	 * Log the MDTO-required elements the document will be missing.
	 *
	 * @param ObjectEntity $object The object being exported.
	 * @param array $files Associated file metadata.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-mdto-generator-must-report-the-limits-of-its-own-validation
	 */
	private function reportMdtoRequiredElementGaps(ObjectEntity $object, array $files): void {
		$gaps = $this->collectMdtoRequiredElementGaps(object: $object, files: $files);
		if (empty($gaps) === true) {
			return;
		}

		$this->logger->warning(
			message: '[MdtoXmlGenerator] Document is not valid MDTO: required elements are absent',
			context: ['objectUuid' => $object->getUuid(), 'gaps' => $gaps]
		);
	}//end reportMdtoRequiredElementGaps()

	/**
	 * Add the identificatie element to the XML document.
	 *
	 * @param DOMDocument $dom The DOM document.
	 * @param DOMElement $parent The parent element.
	 * @param ObjectEntity $object The source object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-generate-mdto-compliant-xml-metadata-per-object
	 */
	private function addIdentification(DOMDocument $dom, DOMElement $parent, ObjectEntity $object): void {
		$identification = $dom->createElementNS(self::MDTO_NAMESPACE, self::MDTO_PREFIX . ':identificatie');

		$reference = $dom->createElementNS(self::MDTO_NAMESPACE, self::MDTO_PREFIX . ':identificatieKenmerk');
		$reference->textContent = $object->getUuid();
		$identification->appendChild($reference);

		$source = $dom->createElementNS(self::MDTO_NAMESPACE, self::MDTO_PREFIX . ':identificatieBron');
		$source->textContent = $this->organisationIdentifier();
		$identification->appendChild($source);

		$parent->appendChild($identification);
	}//end addIdentification()

	/**
	 * Add the naam element to the XML document.
	 *
	 * @param DOMDocument $dom The DOM document.
	 * @param DOMElement $parent The parent element.
	 * @param ObjectEntity $object The source object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-generate-mdto-compliant-xml-metadata-per-object
	 */
	private function addName(DOMDocument $dom, DOMElement $parent, ObjectEntity $object): void {
		$this->addTextElement(
			dom: $dom,
			parent: $parent,
			name: 'naam',
			content: $this->resolveName(object: $object)
		);
	}//end addName()

	/**
	 * Add the aggregatieniveau element when the object declares one.
	 *
	 * The sources and the rule for when there is no value belong to
	 * {@see MdtoSourceReader::aggregationLevel()}. Labels come from MDTO's
	 * `Aggregatieniveaus` begrippenlijst: Archief, Serie, Dossier,
	 * Archiefstuk.
	 *
	 * @param DOMDocument $dom The DOM document.
	 * @param DOMElement $parent The parent element.
	 * @param ObjectEntity $object The source object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-emit-mdto-aggregatieniveau-beperkinggebruik-and-dekkingintijd-from-their-declared-sources
	 */
	private function addAggregationLevel(DOMDocument $dom, DOMElement $parent, ObjectEntity $object): void {
		$level = $this->sourceReader->aggregationLevel(object: $object);
		if ($level === null) {
			return;
		}

		$this->addBegrip(
			dom: $dom,
			parent: $parent,
			name: 'aggregatieniveau',
			label: $level['label'],
			code: $level['code'],
			list: self::AGGREGATION_LEVEL_LIST
		);
	}//end addAggregationLevel()

	/**
	 * Add dekkingInTijd elements when the object declares them.
	 *
	 * {@see MdtoSourceReader::temporalCoverage()} has already dropped every
	 * half-declared entry, so each entry reaching this loop carries both the
	 * `dekkingInTijdType` and the `dekkingInTijdBegindatum` that the XSD marks
	 * `minOccurs="1"`.
	 *
	 * @param DOMDocument $dom The DOM document.
	 * @param DOMElement $parent The parent element.
	 * @param ObjectEntity $object The source object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-emit-mdto-aggregatieniveau-beperkinggebruik-and-dekkingintijd-from-their-declared-sources
	 */
	private function addTemporalCoverage(DOMDocument $dom, DOMElement $parent, ObjectEntity $object): void {
		foreach ($this->sourceReader->temporalCoverage(object: $object) as $entry) {
			$coverage = $dom->createElementNS(self::MDTO_NAMESPACE, self::MDTO_PREFIX . ':dekkingInTijd');
			$this->addBegrip(
				dom: $dom,
				parent: $coverage,
				name: 'dekkingInTijdType',
				label: $entry['type'],
				code: null,
				list: 'DekkingInTijdType'
			);
			$this->addTextElement(
				dom: $dom,
				parent: $coverage,
				name: 'dekkingInTijdBegindatum',
				content: $entry['start']
			);

			if ($entry['end'] !== null) {
				$this->addTextElement(
					dom: $dom,
					parent: $coverage,
					name: 'dekkingInTijdEinddatum',
					content: $entry['end']
				);
			}

			$parent->appendChild($coverage);
		}
	}//end addTemporalCoverage()

	/**
	 * Add event elements derived from the audit trail.
	 *
	 * The selection and the bound belong to {@see MdtoEventMapper}, which
	 * documents the mapping decision. This method only serialises.
	 *
	 * @param DOMDocument $dom The DOM document.
	 * @param DOMElement $parent The parent element.
	 * @param ObjectEntity $object The source object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-derive-mdto-event-entries-from-the-audit-trail
	 */
	private function addEvents(DOMDocument $dom, DOMElement $parent, ObjectEntity $object): void {
		foreach ($this->eventMapper->forObject(object: $object) as $event) {
			$element = $dom->createElementNS(self::MDTO_NAMESPACE, self::MDTO_PREFIX . ':event');

			$this->addBegrip(
				dom: $dom,
				parent: $element,
				name: 'eventType',
				label: $event['type'],
				code: null,
				list: MdtoEventMapper::EVENT_TYPE_LIST
			);

			if ($event['time'] !== null) {
				$this->addTextElement(dom: $dom, parent: $element, name: 'eventTijd', content: $event['time']);
			}

			if ($event['actorName'] !== null) {
				$this->addVerwijzing(
					dom: $dom,
					parent: $element,
					name: 'eventVerantwoordelijkeActor',
					verwijzingNaam: $event['actorName'],
					identificatieKenmerk: $event['actorId']
				);
			}

			$parent->appendChild($element);
		}
	}//end addEvents()

	/**
	 * Add the beperkingGebruik element when a restriction is known.
	 *
	 * {@see MdtoSourceReader::useRestriction()} decides whether there is one,
	 * and records why an unknown restriction is omitted rather than declared
	 * as "Nader te bepalen".
	 *
	 * @param DOMDocument $dom The DOM document.
	 * @param DOMElement $parent The parent element.
	 * @param ObjectEntity $object The source object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-emit-mdto-aggregatieniveau-beperkinggebruik-and-dekkingintijd-from-their-declared-sources
	 */
	private function addUseRestriction(DOMDocument $dom, DOMElement $parent, ObjectEntity $object): void {
		$restriction = $this->sourceReader->useRestriction(object: $object);
		if ($restriction === null) {
			return;
		}

		$element = $dom->createElementNS(self::MDTO_NAMESPACE, self::MDTO_PREFIX . ':beperkingGebruik');

		$this->addBegrip(
			dom: $dom,
			parent: $element,
			name: 'beperkingGebruikType',
			label: $restriction['type'],
			code: null,
			list: self::USE_RESTRICTION_LIST
		);

		if ($restriction['description'] !== null) {
			$this->addTextElement(
				dom: $dom,
				parent: $element,
				name: 'beperkingGebruikNadereBeschrijving',
				content: $restriction['description']
			);
		}

		if ($restriction['startDate'] !== null) {
			$termijn = $dom->createElementNS(self::MDTO_NAMESPACE, self::MDTO_PREFIX . ':beperkingGebruikTermijn');
			$this->addTextElement(
				dom: $dom,
				parent: $termijn,
				name: 'termijnStartdatumLooptijd',
				content: $restriction['startDate']
			);
			$element->appendChild($termijn);
		}

		$parent->appendChild($element);
	}//end addUseRestriction()


	/**
	 * Add the waardering element to the XML document.
	 *
	 * @param DOMDocument $dom The DOM document.
	 * @param DOMElement $parent The parent element.
	 * @param array<string,mixed> $retention The retention metadata.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-generate-mdto-compliant-xml-metadata-per-object
	 */
	private function addRating(DOMDocument $dom, DOMElement $parent, array $retention): void {
		$nominatie = ($retention['archiefnominatie'] ?? '');
		$rating = (self::WAARDERING_MAP[$nominatie] ?? $nominatie);
		$this->addTextElement(dom: $dom, parent: $parent, name: 'waardering', content: $rating);
	}//end addRating()

	/**
	 * Add the bewaartermijn element to the XML document.
	 *
	 * @param DOMDocument $dom The DOM document.
	 * @param DOMElement $parent The parent element.
	 * @param array<string,mixed> $retention The retention metadata.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-generate-mdto-compliant-xml-metadata-per-object
	 */
	private function addRetentionPeriod(DOMDocument $dom, DOMElement $parent, array $retention): void {
		$retentionPeriod = ($retention['bewaartermijn'] ?? '');
		$this->addTextElement(dom: $dom, parent: $parent, name: 'bewaartermijn', content: (string)$retentionPeriod);
	}//end addRetentionPeriod()

	/**
	 * Add the informatiecategorie element to the XML document.
	 *
	 * @param DOMDocument $dom The DOM document.
	 * @param DOMElement $parent The parent element.
	 * @param array<string,mixed> $retention The retention metadata.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-generate-mdto-compliant-xml-metadata-per-object
	 */
	private function addInformatiecategorie(DOMDocument $dom, DOMElement $parent, array $retention): void {
		$classification = ($retention['classification'] ?? 'onbekend');
		$this->addTextElement(dom: $dom, parent: $parent, name: 'informatiecategorie', content: (string)$classification);
	}//end addInformatiecategorie()

	/**
	 * Add the archiefvormer element to the XML document.
	 *
	 * @param DOMDocument $dom The DOM document.
	 * @param DOMElement $parent The parent element.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-generate-mdto-compliant-xml-metadata-per-object
	 */
	private function addArchiefvormer(DOMDocument $dom, DOMElement $parent): void {
		$organisationName = $this->appConfig->getValueString('openregister', 'organisation_name', 'OpenRegister');

		$this->addVerwijzing(
			dom: $dom,
			parent: $parent,
			name: 'archiefvormer',
			verwijzingNaam: $organisationName,
			identificatieKenmerk: $this->organisationIdentifier(),
			identificatieBron: 'OpenRegister'
		);
	}//end addArchiefvormer()

	/**
	 * Add a bestand (file) element to the XML document.
	 *
	 * @param DOMDocument $dom The DOM document.
	 * @param DOMElement $parent The parent element.
	 * @param array{name: string, size: int, format: string, checksum: string} $file The file metadata.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-generate-mdto-compliant-xml-metadata-per-object
	 */
	private function addFile(DOMDocument $dom, DOMElement $parent, array $file): void {
		$fileElement = $dom->createElementNS(self::MDTO_NAMESPACE, self::MDTO_PREFIX . ':bestand');

		$this->addTextElement(dom: $dom, parent: $fileElement, name: 'naam', content: $file['name']);
		$this->addTextElement(dom: $dom, parent: $fileElement, name: 'omvang', content: (string)$file['size']);
		$this->addTextElement(dom: $dom, parent: $fileElement, name: 'bestandsformaat', content: $file['format']);

		$checksumElement = $dom->createElementNS(self::MDTO_NAMESPACE, self::MDTO_PREFIX . ':checksum');

		$algoritme = $dom->createElementNS(self::MDTO_NAMESPACE, self::MDTO_PREFIX . ':checksumAlgoritme');
		$algoritme->textContent = 'SHA-256';
		$checksumElement->appendChild($algoritme);

		$value = $dom->createElementNS(self::MDTO_NAMESPACE, self::MDTO_PREFIX . ':checksumWaarde');
		$value->textContent = $file['checksum'];
		$checksumElement->appendChild($value);

		$fileElement->appendChild($checksumElement);
		$parent->appendChild($fileElement);
	}//end addFile()

	/**
	 * Add a begripGegevens element.
	 *
	 * The XSD makes `begripLabel` and `begripBegrippenlijst` both
	 * `minOccurs="1"`, so the list name is never optional here.
	 *
	 * @param DOMDocument $dom The DOM document.
	 * @param DOMElement $parent The parent element.
	 * @param string $name The element name (without namespace prefix).
	 * @param string $label The begripLabel value.
	 * @param string|null $code The begripCode value, omitted when null.
	 * @param string $list The begrippenlijst name.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-emit-mdto-aggregatieniveau-beperkinggebruik-and-dekkingintijd-from-their-declared-sources
	 */
	private function addBegrip(
		DOMDocument $dom,
		DOMElement $parent,
		string $name,
		string $label,
		?string $code,
		string $list,
	): void {
		$element = $dom->createElementNS(self::MDTO_NAMESPACE, self::MDTO_PREFIX . ':' . $name);

		$this->addTextElement(dom: $dom, parent: $element, name: 'begripLabel', content: $label);

		if (($code ?? '') !== '') {
			$this->addTextElement(dom: $dom, parent: $element, name: 'begripCode', content: $code);
		}

		$this->addVerwijzing(
			dom: $dom,
			parent: $element,
			name: 'begripBegrippenlijst',
			verwijzingNaam: $list
		);

		$parent->appendChild($element);
	}//end addBegrip()

	/**
	 * Add a verwijzingGegevens element.
	 *
	 * @param DOMDocument $dom The DOM document.
	 * @param DOMElement $parent The parent element.
	 * @param string $name The element name (without namespace prefix).
	 * @param string $verwijzingNaam The name of the referenced object.
	 * @param string|null $identificatieKenmerk The reference key, omitted when null.
	 * @param string|null $identificatieBron The reference source; defaults to the organisation identifier.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-generate-mdto-compliant-xml-metadata-per-object
	 */
	private function addVerwijzing(
		DOMDocument $dom,
		DOMElement $parent,
		string $name,
		string $verwijzingNaam,
		?string $identificatieKenmerk = null,
		?string $identificatieBron = null,
	): void {
		$element = $dom->createElementNS(self::MDTO_NAMESPACE, self::MDTO_PREFIX . ':' . $name);

		$this->addTextElement(dom: $dom, parent: $element, name: 'verwijzingNaam', content: $verwijzingNaam);

		if (($identificatieKenmerk ?? '') !== '') {
			$identificatie = $dom->createElementNS(
				self::MDTO_NAMESPACE,
				self::MDTO_PREFIX . ':verwijzingIdentificatie'
			);
			$this->addTextElement(
				dom: $dom,
				parent: $identificatie,
				name: 'identificatieKenmerk',
				content: $identificatieKenmerk
			);
			$this->addTextElement(
				dom: $dom,
				parent: $identificatie,
				name: 'identificatieBron',
				content: ($identificatieBron ?? $this->organisationIdentifier())
			);
			$element->appendChild($identificatie);
		}

		$parent->appendChild($element);
	}//end addVerwijzing()

	/**
	 * Add a simple text element to the XML document.
	 *
	 * @param DOMDocument $dom The DOM document.
	 * @param DOMElement $parent The parent element.
	 * @param string $name The element name (without namespace prefix).
	 * @param string $content The text content.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-generate-mdto-compliant-xml-metadata-per-object
	 */
	private function addTextElement(DOMDocument $dom, DOMElement $parent, string $name, string $content): void {
		$element = $dom->createElementNS(self::MDTO_NAMESPACE, self::MDTO_PREFIX . ':' . $name);
		$element->textContent = $content;
		$parent->appendChild($element);
	}//end addTextElement()





	/**
	 * Resolve the object's naam.
	 *
	 * @param ObjectEntity $object The source object.
	 *
	 * @return string The name, empty when nothing supplies one.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-generate-mdto-compliant-xml-metadata-per-object
	 */
	private function resolveName(ObjectEntity $object): string {
		$data = ($object->getObject() ?? []);
		$title = ($data['title'] ?? $data['naam'] ?? $data['name'] ?? $object->getUuid() ?? '');

		return (string)$title;
	}//end resolveName()

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
