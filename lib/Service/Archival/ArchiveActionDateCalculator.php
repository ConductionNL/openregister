<?php

/**
 * Where a retention period starts counting from.
 *
 * 🔴 ONE HOME FOR THE BRONDATUM QUESTION. There were two implementations of
 * this and only one of them ran. `ArchiveActionDateCalculator` carried a
 * `determineBrondatum` and a `brondatumFromProperty` of its own, plus a unit
 * test suite that made it look live, and had ZERO production callers anywhere
 * in the repository: every real calculation went through RetentionService's
 * private copy. A duplicate with its own green tests is worse than no
 * duplicate, because the next person to fix a derivation bug has even odds of
 * fixing the copy nobody calls. That class is deleted and this is the one home.
 *
 * ZGW's `brondatumArchiefprocedure` names nine derivation methods. Five of them
 * — `gerelateerde_zaak`, `hoofdzaak`, `ingangsdatum_besluit`,
 * `vervaldatum_besluit`, `zaakobject` — are one mechanic wearing five names:
 * follow a reference held on this record, read a date property off the record
 * it points at. openregister implements that mechanic once, configured by
 * `sourceRelation` and `sourceRelationProperty`, and never learns what a zaak
 * or a besluit is. Gap C1 in openspec/changes/archival-conformance.
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

use DateInterval;
use DateTime;
use Exception;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use Psr\Log\LoggerInterface;

/**
 * Calculates the archiefactiedatum: where a retention period starts counting
 * from, and what to do when that cannot be answered.
 */
class ArchiveActionDateCalculator implements ArchiveReferenceReaderInterface {

	/**
	 * Valid afleidingswijze methods.
	 */
	private const VALID_AFLEIDINGSWIJZEN = [
		'afgehandeld',
		'ander_datumkenmerk',
		'eigenschap',
		'gerelateerde_zaak',
		'hoofdzaak',
		'ingangsdatum_besluit',
		'termijn',
		'vervaldatum_besluit',
		'zaakobject',
	];

	/**
	 * The derivation methods that must NOT silently fall back to a date.
	 *
	 * For `afgehandeld` and `termijn` the creation date is a defensible source:
	 * a record with no recorded closure was created and has been open since.
	 * For these, it is not. A schema that says "date this from the related
	 * decision" and cannot find that decision has produced no answer, and a
	 * plausible wrong disposal date is worse than a visible gap a records
	 * officer can act on. Same reasoning as gap C2.
	 *
	 * `eigenschap` is deliberately NOT in this list. It predates the change
	 * and existing installs may rely on its fallback; moving it is a separate
	 * decision from implementing the six that never worked at all.
	 *
	 * @var string[]
	 */
	private const REFUSE_WITHOUT_BRONDATUM = [
		'ander_datumkenmerk',
		...self::RELATION_AFLEIDINGSWIJZEN,
	];

	/**
	 * The derivation methods that follow a relation to another object.
	 *
	 * GAP C1. ZGW names five of these after zaak and besluit concepts, but the
	 * mechanic underneath all five is identical: follow a reference held on
	 * this object, then read a date property off whatever it points at.
	 * openregister stays schema-agnostic, so it implements the mechanic once
	 * and lets the schema say which property holds the reference and which
	 * property on the target holds the date. It never learns what a zaak is.
	 *
	 * @var string[]
	 */
	private const RELATION_AFLEIDINGSWIJZEN = [
		'gerelateerde_zaak',
		'hoofdzaak',
		'ingangsdatum_besluit',
		'vervaldatum_besluit',
		'zaakobject',
	];

	/**
	 * The derivation methods that read a date property on this record: the
	 * config key naming the property, and whether the method requires it.
	 *
	 * `eigenschap` and the closure field are OPTIONAL, so an unconfigured one
	 * is silence rather than an error: they have a creation-date fallback and
	 * always did. `ander_datumkenmerk` (GAP C1) is ZGW's catch-all: a date
	 * attribute on this record that is not a zaak-eigenschap. Same mechanic as
	 * `eigenschap`, its own config key so a schema can carry both, and
	 * REQUIRED, because this method has no fallback to be silent in favour of.
	 *
	 * @var array<string, array{0: string, 1: bool}>
	 */
	private const DATE_PROPERTY_KEYS = [
		'eigenschap' => ['bronEigenschap', false],
		'ander_datumkenmerk' => ['sourceDateProperty', true],
		'afgehandeld' => ['closureField', false],
		'termijn' => ['closureField', false],
	];

	/**
	 * Constructor.
	 *
	 * @param MagicMapperInterface     $objectMapper Object mapper, for following a relation to another record
	 * @param LoggerInterface $logger       Logger
	 */
	public function __construct(
		private readonly MagicMapperInterface $objectMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Determine the brondatum (source date) based on afleidingswijze.
	 *
	 * @param RecordDating $dating The record, its schema's archive configuration and the derivation method.
	 *
	 * @return DateTime|null The source date or null
	 *
	 * @spec openspec/specs/retention-management/spec.md#requirement-the-system-must-calculate-archiefactiedatum-using-configurable-afleidingswijzen
	 */
	public function determineBrondatum(RecordDating $dating): ?DateTime {
		return $this->brondatumOf(dating: $dating);
	}//end determineBrondatum()

	/**
	 * The brondatum of one record under its derivation method, or null.
	 *
	 * @param RecordDating $dating The record, its archive configuration and its derivation method (a string).
	 *
	 * @return DateTime|null The brondatum, or null when it cannot be determined.
	 */
	private function brondatumOf(RecordDating $dating): ?DateTime {
		$objectData = $dating->object->getObject();

		// Every method is the same two questions: which property holds the
		// date, and is it on this record or on one this record points at.
		if (array_key_exists($dating->afleidingswijze, self::DATE_PROPERTY_KEYS) === true) {
			return $this->brondatumFromOwnProperty(objectData: $objectData, dating: $dating);
		}

		if ($this->isRelationMethod(afleidingswijze: $dating->afleidingswijze) === true) {
			return $this->brondatumFromRelation(objectData: $objectData, archiveConfig: $dating->archive);
		}

		return null;
	}//end brondatumOf()

	/**
	 * Read the brondatum off the property on this record that the method's config key names.
	 *
	 * @param array<string, mixed> $objectData The record's own data.
	 * @param RecordDating         $dating     The archive configuration and the derivation method, one of DATE_PROPERTY_KEYS.
	 *
	 * @return DateTime|null The date, or null
	 */
	private function brondatumFromOwnProperty(array $objectData, RecordDating $dating): ?DateTime {
		[$key, $required] = self::DATE_PROPERTY_KEYS[$dating->afleidingswijze];
		$source = new DateSource(property: ($dating->archive[$key] ?? null), label: $key);
		if ($required === true) {
			return $this->brondatumFromProperty(objectData: $objectData, source: $source);
		}

		return $this->brondatumFromOptionalProperty(objectData: $objectData, source: $source);
	}//end brondatumFromOwnProperty()

	/**
	 * The property on this record whose change moves the source date.
	 *
	 * For the relation methods that is the reference itself (`sourceRelation`);
	 * a change of the date on the related record is followed separately, from
	 * that record's own save.
	 *
	 * @param array $archiveConfig The schema's archive block
	 *
	 * @return string|null The property name, or null when the method has none configured
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-the-destruction-date-follows-its-source-date-under-every-method
	 */
	public function sourcePropertyOf(array $archiveConfig): ?string {
		$key = $this->sourceKeyOf(afleidingswijze: ($archiveConfig['afleidingswijze'] ?? 'afgehandeld'));
		if ($key === null) {
			return null;
		}

		$property = ($archiveConfig[$key] ?? null);
		if (is_string($property) === false || $property === '') {
			return null;
		}

		return $property;
	}//end sourcePropertyOf()

	/**
	 * The archive-block key that names a method's source property.
	 *
	 * @param mixed $afleidingswijze The derivation method
	 *
	 * @return string|null The key, or null when the method has none
	 */
	private function sourceKeyOf(mixed $afleidingswijze): ?string {
		if (is_string($afleidingswijze) === true && array_key_exists($afleidingswijze, self::DATE_PROPERTY_KEYS) === true) {
			return self::DATE_PROPERTY_KEYS[$afleidingswijze][0];
		}

		if ($this->isRelationMethod(afleidingswijze: $afleidingswijze) === true) {
			return 'sourceRelation';
		}

		return null;
	}//end sourceKeyOf()

	/**
	 * Whether a derivation method reads its date off a related record.
	 *
	 * @param string $afleidingswijze The derivation method
	 *
	 * @return bool True for the relation methods
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-the-destruction-date-follows-its-source-date-under-every-method
	 */
	public function isRelationMethod(string $afleidingswijze): bool {
		return in_array($afleidingswijze, self::RELATION_AFLEIDINGSWIJZEN, true);
	}//end isRelationMethod()

	/**
	 * The uuid or uri a relation property holds, or null.
	 *
	 * @param mixed $value The raw relation value
	 *
	 * @return string|null The reference
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-the-destruction-date-follows-its-source-date-under-every-method
	 */
	public function referenceOf(mixed $value): ?string {
		return $this->firstReference(value: $value);
	}//end referenceOf()

	/**
	 * Read a brondatum from a property that the schema need not configure.
	 *
	 * The quiet sibling of brondatumFromProperty. `eigenschap` and the closure
	 * field both have a creation-date fallback, so an unconfigured one is a
	 * schema that chose the fallback, not a mistake to shout about.
	 *
	 * @param array<string, mixed> $objectData The record's data.
	 * @param DateSource           $source     The property, null when none is configured, and its configuration key.
	 *
	 * @return DateTime|null The date, or null when it is absent or unparseable
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	private function brondatumFromOptionalProperty(array $objectData, DateSource $source): ?DateTime {
		if ($source->property === null) {
			return null;
		}

		return $this->brondatumFromProperty(objectData: $objectData, source: $source);
	}//end brondatumFromOptionalProperty()

	/**
	 * Read a brondatum from a named date property on this record.
	 *
	 * @param array<string, mixed> $objectData The record's data.
	 * @param DateSource           $source     The property, null when none is configured, and its configuration key.
	 *
	 * @return DateTime|null The date, or null when it is absent or unparseable
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	private function brondatumFromProperty(array $objectData, DateSource $source): ?DateTime {
		$property = $source->property;
		if ($property === null) {
			$this->logger->error(
				'[RetentionService] Derivation method needs a date property and none is configured',
				['expectedConfigKey' => $source->label]
			);

			return null;
		}

		if (isset($objectData[$property]) === false) {
			return null;
		}

		return $this->parsedDate(value: $objectData[$property], source: $source);
	}//end brondatumFromProperty()

	/**
	 * A stored date value as a DateTime, or null when it does not parse.
	 *
	 * @param mixed      $value  The stored value.
	 * @param DateSource $source The property it came from and its configuration key, for the log line.
	 *
	 * @return DateTime|null The date
	 */
	private function parsedDate(mixed $value, DateSource $source): ?DateTime {
		try {
			return new DateTime((string)$value);
		} catch (Exception $e) {
			$this->logger->warning(
				'[RetentionService] Cannot parse brondatum from ' . $source->label . ': ' . (string)$source->property,
				['exception' => $e]
			);

			return null;
		}//end try
	}//end parsedDate()

	/**
	 * Follow a relation off this record and read a date property on the target.
	 *
	 * GAP C1. This is the one mechanic behind all five relation-based ZGW
	 * derivation methods. The schema names two things: `sourceRelation`, the
	 * property on this record holding the reference, and
	 * `sourceRelationProperty`, the date property to read on whatever it points
	 * at. A reference held as a list resolves through its first entry, because
	 * a derivation from many dates is not a derivation.
	 *
	 * Every failure returns null rather than a guess, and every one of them is
	 * logged. The caller refuses to produce a disposal date at all for these
	 * methods, per REFUSE_WITHOUT_BRONDATUM.
	 *
	 * @param array $objectData    The record's own data
	 * @param array $archiveConfig The schema's archive block
	 *
	 * @return DateTime|null The related record's date, or null
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	private function brondatumFromRelation(array $objectData, array $archiveConfig): ?DateTime {
		$relation = $archiveConfig['sourceRelation'] ?? null;
		$property = $archiveConfig['sourceRelationProperty'] ?? null;

		if ($relation === null || $property === null) {
			$message = '[RetentionService] Relation-based derivation needs sourceRelation and sourceRelationProperty';
			$this->logger->error($message, ['sourceRelation' => $relation, 'sourceRelationProperty' => $property]);
			return null;
		}

		$relatedData = $this->relatedData(reference: $this->firstReference(value: ($objectData[$relation] ?? null)));
		if ($relatedData === null) {
			return null;
		}

		return $this->brondatumFromProperty(objectData: $relatedData, source: new DateSource(property: $property, label: 'sourceRelationProperty'));
	}//end brondatumFromRelation()

	/**
	 * The data of the record a reference points at, or null when it cannot be read.
	 *
	 * @param string|null $reference The reference, or null when there is none
	 *
	 * @return array|null The related record's data
	 */
	private function relatedData(?string $reference): ?array {
		if ($reference === null) {
			return null;
		}

		$relatedData = $this->findRelated(reference: $reference)?->getObject();
		if (is_array($relatedData) === false) {
			return null;
		}

		return $relatedData;
	}//end relatedData()

	/**
	 * The record a reference points at, or null when it cannot be resolved.
	 *
	 * @param string $reference The reference
	 *
	 * @return ObjectEntity|null The related record
	 */
	private function findRelated(string $reference): ?ObjectEntity {
		try {
			return $this->objectMapper->find($reference);
		} catch (Exception $e) {
			$this->logger->warning(
				'[RetentionService] Cannot resolve the related object for a brondatum: ' . $reference,
				['exception' => $e]
			);

			return null;
		}
	}//end findRelated()

	/**
	 * The single reference a relation property points at.
	 *
	 * A relation is stored as a uuid, a uri, or a list of either. A list
	 * resolves through its FIRST entry and says so in the log, because a
	 * disposal date derived from several unrelated dates is not derived at
	 * all, and picking silently would hide that the schema is ambiguous.
	 *
	 * @param mixed $value The raw relation value
	 *
	 * @return string|null The reference, or null when there is none
	 */
	private function firstReference(mixed $value): ?string {
		if (is_array($value) === true) {
			if ($value === []) {
				return null;
			}

			$this->logger->info(
				'[RetentionService] Relation holds several references; the brondatum uses the first'
			);
			$value = reset($value);
		}

		return ArchivalText::fromStringOrInt(value: $value);
	}//end firstReference()

	/**
	 * Calculate archiefactiedatum based on the schema's afleidingswijze.
	 *
	 * @param ObjectEntity $object The object to calculate for
	 * @param Schema $schema The schema with afleidingswijze config
	 * @param string $retentionPeriod ISO 8601 duration (e.g., P5Y, P20Y)
	 *
	 * @return string|null ISO 8601 date string or null if calculation not possible
	 *
	 * @spec openspec/specs/retention-management/spec.md#requirement-the-system-must-calculate-archiefactiedatum-using-configurable-afleidingswijzen
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function calculate(ObjectEntity $object, Schema $schema, string $retentionPeriod): ?string {
		$archive = $schema->getArchive();
		$dating = new RecordDating(object: $object, archive: $archive, afleidingswijze: ($archive['afleidingswijze'] ?? 'afgehandeld'));
		return $this->dated(dating: $dating, retentionPeriod: $retentionPeriod);
	}//end calculate()

	/**
	 * The archiefactiedatum of one record: its brondatum, plus the procestermijn under `termijn`, plus the period.
	 *
	 * @param RecordDating $dating          The record, its archive configuration and its derivation method.
	 * @param string       $retentionPeriod ISO 8601 duration (bewaartermijn).
	 *
	 * @return string|null The archiefactiedatum (Y-m-d), or null when it cannot be calculated.
	 */
	private function dated(RecordDating $dating, string $retentionPeriod): ?string {
		$interval = $this->intervalFor(dating: $dating, retentionPeriod: $retentionPeriod);
		if ($interval === null) {
			return null;
		}

		$brondatum = $this->sourceDateFor(dating: $dating);
		if ($brondatum === null) {
			return null;
		}

		return $this->counted(dating: $dating, brondatum: $brondatum)->add($interval)->format('Y-m-d');
	}//end dated()

	/**
	 * The retention period as an interval, or null when the method is unsupported or the period invalid.
	 *
	 * @param RecordDating $dating          The record and its derivation method.
	 * @param string       $retentionPeriod ISO 8601 duration (bewaartermijn).
	 *
	 * @return DateInterval|null The interval
	 */
	private function intervalFor(RecordDating $dating, string $retentionPeriod): ?DateInterval {
		if ($this->isSupported(dating: $dating) === false) {
			return null;
		}

		return $this->intervalOf(retentionPeriod: $retentionPeriod);
	}//end intervalFor()

	/**
	 * Whether the derivation method is one this calculator implements; an unsupported one is logged.
	 *
	 * GAP C2. VALID_AFLEIDINGSWIJZEN was declared and NEVER REFERENCED, so
	 * a schema configured with one of ZGW's six other derivation methods
	 * was not rejected, not warned about and not logged: determineBrondatum
	 * fell through `default: return null` and the date was computed from
	 * the fallback instead. A permit that must run from
	 * `ingangsdatum_besluit` silently ran from somewhere else.
	 *
	 * Refusing is the honest answer. No date at all is a visible gap a
	 * records officer can act on; a plausible wrong date is not.
	 *
	 * @param RecordDating $dating The record and its derivation method.
	 *
	 * @return bool True when the method is supported
	 */
	private function isSupported(RecordDating $dating): bool {
		if (in_array($dating->afleidingswijze, self::VALID_AFLEIDINGSWIJZEN, true) === true) {
			return true;
		}

		$this->logger->error(
			'[ArchiveActionDateCalculator] Unsupported afleidingswijze; no archiefactiedatum calculated',
			[
				'afleidingswijze' => $dating->afleidingswijze,
				'supported' => self::VALID_AFLEIDINGSWIJZEN,
				'objectUuid' => $dating->object->getUuid(),
			]
		);

		return false;
	}//end isSupported()

	/**
	 * The retention period as an interval, or null when it is not an ISO 8601 duration.
	 *
	 * @param string $retentionPeriod The retention period
	 *
	 * @return DateInterval|null The interval
	 */
	private function intervalOf(string $retentionPeriod): ?DateInterval {
		try {
			return new DateInterval($retentionPeriod);
		} catch (Exception $e) {
			$this->logger->warning(
				'[ArchiveActionDateCalculator] Invalid bewaartermijn format: ' . $retentionPeriod,
				['exception' => $e]
			);
			return null;
		}
	}//end intervalOf()

	/**
	 * The date the period counts from: the method's source date, else its fallback.
	 *
	 * @param RecordDating $dating The record, its archive configuration and its derivation method.
	 *
	 * @return DateTime|null The date, or null to refuse
	 */
	private function sourceDateFor(RecordDating $dating): ?DateTime {
		$brondatum = $this->brondatumOf(dating: $dating);
		if ($brondatum !== null) {
			return $brondatum;
		}

		return $this->brondatumFallback(object: $dating->object, afleidingswijze: $dating->afleidingswijze);
	}//end sourceDateFor()

	/**
	 * A copy of the source date, moved by the process term under `termijn`; the caller adds the period.
	 *
	 * Never mutate the entity's own DateTime: `->add()` is in-place,
	 * and getCreated() hands back the LIVE object, so a disposal-date
	 * calculation would silently move the object's created timestamp.
	 * `clone` rather than DateTime::createFromInterface() because phpmd
	 * refuses static access and every path here already yields a DateTime.
	 *
	 * @param RecordDating $dating    The archive configuration and the derivation method.
	 * @param DateTime     $brondatum The brondatum.
	 *
	 * @return DateTime The date the period is counted from, a copy of the brondatum.
	 */
	private function counted(RecordDating $dating, DateTime $brondatum): DateTime {
		$date = clone $brondatum;

		if ($dating->afleidingswijze === 'termijn') {
			$this->addProcestermijn(brondatum: $date, archiveConfig: $dating->archive);
		}

		return $date;
	}//end counted()

	/**
	 * What to date from when the derivation method produced no source date.
	 *
	 * GAP C1. For the six methods in REFUSE_WITHOUT_BRONDATUM the answer is
	 * NOTHING. A schema that says "date this from the related decision" and
	 * cannot find that decision has produced no answer, and dating it from
	 * creation instead would be a plausible wrong disposal date, which is the
	 * failure those methods were implemented to end. No date is a visible gap a
	 * records officer can act on.
	 *
	 * For the rest it is the object's CREATED date. GAP C3: the code used to
	 * say `new DateTime()`, which is NOW, while its comment claimed the
	 * creation date. Two identical records processed a year apart therefore got
	 * disposal dates a year apart, and a record recalculated long after the
	 * fact got one far later than lawful.
	 *
	 * @param ObjectEntity $object          The object being dated
	 * @param string       $afleidingswijze The derivation method that found nothing
	 *
	 * @return DateTime|null The date to count from, or null to refuse outright
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	private function brondatumFallback(ObjectEntity $object, string $afleidingswijze): ?DateTime {
		if (in_array($afleidingswijze, self::REFUSE_WITHOUT_BRONDATUM, true) === true) {
			$this->logger->error(
				'[ArchiveActionDateCalculator] No brondatum for this derivation method; no archiefactiedatum calculated',
				['afleidingswijze' => $afleidingswijze, 'objectUuid' => $object->getUuid()]
			);

			return null;
		}

		$created = $object->getCreated();
		if ($created === null) {
			return new DateTime();
		}

		return $created;
	}//end brondatumFallback()

	/**
	 * Add the schema's procestermijn to the brondatum, in place.
	 *
	 * Only the `termijn` derivation method uses it: the retention period runs
	 * from the end of a process term rather than from the source date itself.
	 * An unparseable term is logged and skipped rather than fatal, because the
	 * retention period behind it is still a real obligation and a date without
	 * the process term is closer to right than no date at all.
	 *
	 * @param DateTime $brondatum     The source date, mutated in place
	 * @param array    $archiveConfig The schema's archive block
	 *
	 * @return void
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	private function addProcestermijn(DateTime $brondatum, array $archiveConfig): void {
		$procestermijn = $archiveConfig['procestermijn'] ?? null;
		if ($procestermijn === null) {
			return;
		}

		try {
			$brondatum->add(new DateInterval($procestermijn));
		} catch (Exception $e) {
			$this->logger->warning(
				'[ArchiveActionDateCalculator] Invalid procestermijn format: ' . $procestermijn,
				['exception' => $e]
			);
		}//end try
	}//end addProcestermijn()

}//end class
