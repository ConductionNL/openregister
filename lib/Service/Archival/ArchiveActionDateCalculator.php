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
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use Psr\Log\LoggerInterface;

/**
 * Calculates the archiefactiedatum: where a retention period starts counting
 * from, and what to do when that cannot be answered.
 */
class ArchiveActionDateCalculator {

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
		'gerelateerde_zaak',
		'hoofdzaak',
		'ingangsdatum_besluit',
		'vervaldatum_besluit',
		'zaakobject',
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
	 * Constructor.
	 *
	 * @param MagicMapper     $objectMapper Object mapper, for following a relation to another record
	 * @param LoggerInterface $logger       Logger
	 */
	public function __construct(
		private readonly MagicMapper $objectMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Determine the brondatum (source date) based on afleidingswijze.
	 *
	 * @param ObjectEntity $object The object
	 * @param Schema $schema The schema
	 * @param string $afleidingswijze The derivation method
	 *
	 * @return DateTime|null The source date or null
	 *
	 * @spec openspec/specs/retention-management/spec.md#requirement-the-system-must-calculate-archiefactiedatum-using-configurable-afleidingswijzen
	 */
	public function determineBrondatum(
		ObjectEntity $object,
		Schema $schema,
		string $afleidingswijze,
	): ?DateTime {
		$archiveConfig = $schema->getArchive();
		$objectData = $object->getObject();

		// Every branch is the same two questions: which property holds the
		// date, and is it on this record or on one this record points at. The
		// switch says which config key answers the first; the helpers answer
		// the second. `eigenschap` and the closure field are OPTIONAL, so an
		// unconfigured one is silence rather than an error: they have a
		// creation-date fallback above and always did.
		switch ($afleidingswijze) {
			case 'eigenschap':
				return $this->brondatumFromOptionalProperty(
					objectData: $objectData,
					property: ($archiveConfig['bronEigenschap'] ?? null),
					label: 'bronEigenschap'
				);
			case 'ander_datumkenmerk':
				// GAP C1. ZGW's catch-all: a date attribute on this record that
				// is not a zaak-eigenschap. Same mechanic as `eigenschap`, its
				// own config key so a schema can carry both, and REQUIRED,
				// because this method has no fallback to be silent in favour of.
				return $this->brondatumFromProperty(
					objectData: $objectData,
					property: ($archiveConfig['sourceDateProperty'] ?? null),
					label: 'sourceDateProperty'
				);
			case 'afgehandeld':
			case 'termijn':
				return $this->brondatumFromOptionalProperty(
					objectData: $objectData,
					property: ($archiveConfig['closureField'] ?? null),
					label: 'closureField'
				);
			default:
				if (in_array($afleidingswijze, self::RELATION_AFLEIDINGSWIJZEN, true) === true) {
					return $this->brondatumFromRelation(objectData: $objectData, archiveConfig: $archiveConfig);
				}

				return null;
		}//end switch
	}//end determineBrondatum()

	/**
	 * Read a brondatum from a property that the schema need not configure.
	 *
	 * The quiet sibling of brondatumFromProperty. `eigenschap` and the closure
	 * field both have a creation-date fallback, so an unconfigured one is a
	 * schema that chose the fallback, not a mistake to shout about.
	 *
	 * @param array       $objectData The record's own data
	 * @param string|null $property   The configured property name, if any
	 * @param string      $label      The config key's name, for the log line
	 *
	 * @return DateTime|null The date, or null when it is absent or unparseable
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	private function brondatumFromOptionalProperty(array $objectData, ?string $property, string $label): ?DateTime {
		if ($property === null) {
			return null;
		}

		return $this->brondatumFromProperty(objectData: $objectData, property: $property, label: $label);
	}//end brondatumFromOptionalProperty()

	/**
	 * Read a brondatum from a named date property on this record.
	 *
	 * @param array       $objectData The record's own data
	 * @param string|null $property   The configured property name
	 * @param string      $label      The config key's name, for the log line
	 *
	 * @return DateTime|null The date, or null when it is absent or unparseable
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	private function brondatumFromProperty(array $objectData, ?string $property, string $label): ?DateTime {
		if ($property === null) {
			$this->logger->error(
				'[RetentionService] Derivation method needs a date property and none is configured',
				['expectedConfigKey' => $label]
			);

			return null;
		}

		if (isset($objectData[$property]) === false) {
			return null;
		}

		try {
			return new DateTime((string)$objectData[$property]);
		} catch (Exception $e) {
			$this->logger->warning(
				'[RetentionService] Cannot parse brondatum from ' . $label . ': ' . $property,
				['exception' => $e]
			);

			return null;
		}//end try
	}//end brondatumFromProperty()

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
			$this->logger->error(
				'[RetentionService] Relation-based derivation needs sourceRelation and sourceRelationProperty',
				['sourceRelation' => $relation, 'sourceRelationProperty' => $property]
			);

			return null;
		}

		$reference = $this->firstReference(value: ($objectData[$relation] ?? null));
		if ($reference === null) {
			return null;
		}

		try {
			$related = $this->objectMapper->find($reference);
		} catch (Exception $e) {
			$this->logger->warning(
				'[RetentionService] Cannot resolve the related object for a brondatum: ' . $reference,
				['exception' => $e]
			);

			return null;
		}

		$relatedData = $related->getObject();
		if (is_array($relatedData) === false) {
			return null;
		}

		return $this->brondatumFromProperty(
			objectData: $relatedData,
			property: $property,
			label: 'sourceRelationProperty'
		);
	}//end brondatumFromRelation()

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

		if (is_string($value) === false && is_int($value) === false) {
			return null;
		}

		$reference = trim((string)$value);
		if ($reference === '') {
			return null;
		}

		return $reference;
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
	public function calculate(
		ObjectEntity $object,
		Schema $schema,
		string $retentionPeriod,
	): ?string {
		$archiveConfig = $schema->getArchive();
		$afleidingswijze = $archiveConfig['afleidingswijze'] ?? 'afgehandeld';

		// GAP C2. VALID_AFLEIDINGSWIJZEN was declared and NEVER REFERENCED, so
		// a schema configured with one of ZGW's six other derivation methods
		// was not rejected, not warned about and not logged: determineBrondatum
		// fell through `default: return null` and the date was computed from
		// the fallback instead. A permit that must run from
		// `ingangsdatum_besluit` silently ran from somewhere else.
		//
		// Refusing is the honest answer. No date at all is a visible gap a
		// records officer can act on; a plausible wrong date is not.
		if (in_array($afleidingswijze, self::VALID_AFLEIDINGSWIJZEN, true) === false) {
			$this->logger->error(
				'[ArchiveActionDateCalculator] Unsupported afleidingswijze; no archiefactiedatum calculated',
				[
					'afleidingswijze' => $afleidingswijze,
					'supported' => self::VALID_AFLEIDINGSWIJZEN,
					'objectUuid' => $object->getUuid(),
				]
			);

			return null;
		}

		try {
			$interval = new DateInterval($retentionPeriod);
		} catch (Exception $e) {
			$this->logger->warning(
				'[ArchiveActionDateCalculator] Invalid bewaartermijn format: ' . $retentionPeriod,
				['exception' => $e]
			);
			return null;
		}

		$brondatum = $this->determineBrondatum(
			object: $object,
			schema: $schema,
			afleidingswijze: $afleidingswijze
		);

		if ($brondatum === null) {
			$brondatum = $this->brondatumFallback(object: $object, afleidingswijze: $afleidingswijze);
			if ($brondatum === null) {
				return null;
			}
		}

		// Never mutate the entity's own DateTime: `->add()` below is in-place,
		// and getCreated() hands back the LIVE object, so a disposal-date
		// calculation would silently move the object's created timestamp.
		// `clone` rather than DateTime::createFromInterface() because phpmd
		// refuses static access and every path here already yields a DateTime.
		$brondatum = clone $brondatum;

		if ($afleidingswijze === 'termijn') {
			$this->addProcestermijn(brondatum: $brondatum, archiveConfig: $archiveConfig);
		}

		$brondatum->add($interval);

		return $brondatum->format('Y-m-d');
	}//end calculate()

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
				[
					'afleidingswijze' => $afleidingswijze,
					'objectUuid' => $object->getUuid(),
				]
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
