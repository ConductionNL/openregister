<?php

/**
 * OpenRegister TMLO Service
 *
 * Service for handling TMLO (Toepassingsprofiel Metadatastandaard Lokale Overheden)
 * archival metadata on OpenRegister objects.
 *
 * Provides:
 * - Auto-population of TMLO defaults from schema/register configuration
 * - Archival status transition validation
 * - TMLO field value validation
 * - MDTO-compliant XML export generation
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/tmlo-validation/spec.md#scenario-valid-iso-8601-duration-accepted
 */

namespace OCA\OpenRegister\Service;

use DateInterval;
use DateTime;
use DOMDocument;
use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Edepot\MdtoXmlGenerator;
use Psr\Log\LoggerInterface;

/**
 * Service for TMLO archival metadata management
 *
 * @package OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class TmloService implements TmloServiceInterface {

	/**
	 * Valid values for archiefnominatie field
	 */
	public const ARCHIEFNOMINATIE_BLIJVEND_BEWAREN = 'blijvend_bewaren';
	public const ARCHIEFNOMINATIE_VERNIETIGEN = 'vernietigen';

	// The archiefstatus values (ARCHIEFSTATUS_*) are declared on TmloServiceInterface.

	/**
	 * MDTO XML namespace
	 */
	public const MDTO_NAMESPACE = 'https://www.nationaalarchief.nl/mdto';

	/**
	 * Namespace of the batch envelope, which is openregister's, not MDTO's.
	 */
	public const EXPORT_NAMESPACE = 'https://www.openregister.app/mdto-export';

	/**
	 * Prefix of the batch envelope element.
	 */
	public const EXPORT_PREFIX = 'or';

	/**
	 * All valid archiefnominatie values
	 *
	 * @var string[]
	 */
	public const VALID_ARCHIEFNOMINATIE = [
		self::ARCHIEFNOMINATIE_BLIJVEND_BEWAREN,
		self::ARCHIEFNOMINATIE_VERNIETIGEN,
	];

	/**
	 * All valid archiefstatus values
	 *
	 * @var string[]
	 */
	public const VALID_ARCHIEFSTATUS = [
		self::ARCHIEFSTATUS_ACTIEF,
		self::ARCHIEFSTATUS_SEMI_STATISCH,
		self::ARCHIEFSTATUS_OVERGEBRACHT,
		self::ARCHIEFSTATUS_VERNIETIGD,
	];

	/**
	 * All TMLO field names
	 *
	 * @var string[]
	 */
	public const TMLO_FIELDS = [
		'classification',
		'archiefnominatie',
		'archiefactiedatum',
		'archiefstatus',
		'bewaarTermijn',
		'vernietigingsCategorie',
	];

	/**
	 * Valid status transitions: from => [allowed targets]
	 *
	 * @var array<string, string[]>
	 */
	public const VALID_TRANSITIONS = [
		self::ARCHIEFSTATUS_ACTIEF => [self::ARCHIEFSTATUS_SEMI_STATISCH],
		self::ARCHIEFSTATUS_SEMI_STATISCH => [self::ARCHIEFSTATUS_OVERGEBRACHT, self::ARCHIEFSTATUS_VERNIETIGD],
		self::ARCHIEFSTATUS_OVERGEBRACHT => [],
		self::ARCHIEFSTATUS_VERNIETIGD => [],
	];

	/**
	 * Enumerated TMLO fields and their allowed values, in validation order.
	 *
	 * @var array<string, string[]>
	 */
	private const ENUM_FIELDS = [
		'archiefnominatie' => self::VALID_ARCHIEFNOMINATIE,
		'archiefstatus'    => self::VALID_ARCHIEFSTATUS,
	];

	/**
	 * Required fields and archiefnominatie per target archiefstatus.
	 *
	 * @var array<string, array{fields: string[], nominatie: string}>
	 */
	private const TRANSITION_REQUIREMENTS = [
		self::ARCHIEFSTATUS_OVERGEBRACHT => [
			'fields'    => ['archiefactiedatum', 'classification', 'archiefnominatie'],
			'nominatie' => self::ARCHIEFNOMINATIE_BLIJVEND_BEWAREN,
		],
		self::ARCHIEFSTATUS_VERNIETIGD   => [
			'fields'    => ['archiefactiedatum', 'classification', 'archiefnominatie', 'vernietigingsCategorie'],
			'nominatie' => self::ARCHIEFNOMINATIE_VERNIETIGEN,
		],
	];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger interface
	 * @param MdtoXmlGenerator $mdtoGenerator The one implementation of the MDTO format
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-tmlo-metadata/tasks.md#task-1
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly MdtoXmlGenerator $mdtoGenerator,
	) {
	}//end __construct()

	/**
	 * Check if TMLO is enabled for a given register.
	 *
	 * @param Register $register The register to check
	 *
	 * @return bool True if TMLO is enabled
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-tmlo-metadata/tasks.md#task-2
	 */
	public function isTmloEnabled(Register $register): bool {
		$config = $register->getConfiguration();
		return ($config['tmloEnabled'] ?? false) === true;
	}//end isTmloEnabled()

	/**
	 * Get TMLO defaults from a schema's configuration.
	 *
	 * @param Schema $schema The schema to get defaults from
	 *
	 * @return array The TMLO default values
	 *
	 * @spec exclude Owned by tmlo-auto-populate spec (schema-defaults precedence is part of the auto-populate contract); not foundation behaviour.
	 */
	public function getSchemaDefaults(Schema $schema): array {
		$config = $schema->getConfiguration();
		if (is_array($config) === false) {
			return [];
		}

		return ($config['tmloDefaults'] ?? []);
	}//end getSchemaDefaults()

	/**
	 * Populate TMLO defaults on an object entity of one register and schema.
	 *
	 * Merges schema-level TMLO defaults with any explicitly provided TMLO data;
	 * nothing is set unless the register has TMLO enabled. Sets archiefstatus
	 * to 'actief' if not already set, and calculates archiefactiedatum from
	 * bewaarTermijn if not explicitly provided.
	 *
	 * @param ObjectEntity   $object The object to populate
	 * @param RegisterSchema $table  The register (TMLO switch) and schema (default values)
	 *
	 * @return ObjectEntity The object with populated TMLO metadata
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-tmlo-metadata/tasks.md#task-2
	 */
	public function populateDefaults(ObjectEntity $object, RegisterSchema $table): ObjectEntity {
		if ($this->isTmloEnabled(register: $table->register) === false) {
			return $object;
		}

		// Get existing TMLO data from the object (may have been set explicitly).
		$tmlo = $object->getTmlo();
		if (is_array($tmlo) === false || empty($tmlo) === true) {
			$tmlo = [];
		}

		$tmlo = $this->mergeSchemaDefaults(tmlo: $tmlo, defaults: $this->getSchemaDefaults(schema: $table->schema));
		$object->setTmlo($this->withDerivedDefaults(tmlo: $tmlo));

		return $object;
	}//end populateDefaults()

	/**
	 * Fill every TMLO field that is not set (or null) from the schema defaults.
	 *
	 * @param array $tmlo     The object's TMLO metadata.
	 * @param array $defaults The schema-level TMLO defaults.
	 *
	 * @return array The merged TMLO metadata.
	 */
	private function mergeSchemaDefaults(array $tmlo, array $defaults): array {
		foreach (self::TMLO_FIELDS as $field) {
			if (isset($tmlo[$field]) === false || $tmlo[$field] === null) {
				$tmlo[$field] = ($defaults[$field] ?? null);
			}
		}

		return $tmlo;
	}//end mergeSchemaDefaults()

	/**
	 * Default archiefstatus to 'actief', and derive archiefactiedatum from bewaarTermijn when unset.
	 *
	 * @param array $tmlo The merged TMLO metadata.
	 *
	 * @return array The TMLO metadata with derived defaults.
	 */
	private function withDerivedDefaults(array $tmlo): array {
		if (($tmlo['archiefstatus'] ?? null) === null) {
			$tmlo['archiefstatus'] = self::ARCHIEFSTATUS_ACTIEF;
		}

		if (($tmlo['archiefactiedatum'] ?? null) === null && ($tmlo['bewaarTermijn'] ?? null) !== null) {
			$tmlo['archiefactiedatum'] = $this->calculateArchiveActionDate(duration: $tmlo['bewaarTermijn']);
		}

		return $tmlo;
	}//end withDerivedDefaults()

	/**
	 * Calculate archiefactiedatum from an ISO-8601 duration string.
	 *
	 * @param string $duration ISO-8601 duration (e.g., P7Y, P5Y6M)
	 *
	 * @return string|null ISO-8601 date string or null if invalid duration
	 *
	 * @spec openspec/specs/tmlo-validation/spec.md#scenario-valid-iso-8601-duration-accepted
	 */
	public function calculateArchiveActionDate(string $duration): ?string {
		try {
			$interval = new DateInterval($duration);
			$date = new DateTime();
			$date->add($interval);
			return $date->format('Y-m-d');
		} catch (Exception $e) {
			$this->logger->warning(
				'Failed to calculate archiefactiedatum from duration: ' . $duration,
				['exception' => $e]
			);
			return null;
		}
	}//end calculateArchiveActionDate()

	/**
	 * Validate TMLO field values.
	 *
	 * Checks that all provided TMLO field values conform to allowed values.
	 *
	 * @param array $tmlo The TMLO metadata to validate
	 *
	 * @return array Array of validation errors (empty if valid)
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-tmlo-metadata/tasks.md#task-3
	 */
	public function validateFieldValues(array $tmlo): array {
		// Validate archiefnominatie and archiefstatus against their allowed values.
		$errors = $this->enumFieldErrors(tmlo: $tmlo);

		// Validate bewaarTermijn as ISO-8601 duration.
		$durationError = $this->bewaarTermijnError(tmlo: $tmlo);
		if ($durationError !== null) {
			$errors[] = $durationError;
		}

		// Validate archiefactiedatum as ISO-8601 date.
		$dateError = $this->archiefactiedatumError(tmlo: $tmlo);
		if ($dateError !== null) {
			$errors[] = $dateError;
		}

		return $errors;
	}//end validateFieldValues()

	/**
	 * Errors for the enumerated fields (archiefnominatie, archiefstatus), in that order.
	 *
	 * @param array $tmlo The TMLO metadata to validate
	 *
	 * @return array Array of validation errors
	 */
	private function enumFieldErrors(array $tmlo): array {
		$errors = [];
		foreach (self::ENUM_FIELDS as $field => $validValues) {
			if (isset($tmlo[$field]) === true
				&& in_array($tmlo[$field], $validValues, true) === false
			) {
				$allowed  = implode(', ', $validValues);
				$got      = $tmlo[$field];
				$errors[] = "{$field} must be one of: {$allowed}. Got: {$got}";
			}
		}

		return $errors;
	}//end enumFieldErrors()

	/**
	 * Error for a bewaarTermijn that is not an ISO-8601 duration.
	 *
	 * @param array $tmlo The TMLO metadata to validate
	 *
	 * @return string|null The error, or null when absent or valid
	 */
	private function bewaarTermijnError(array $tmlo): ?string {
		if (isset($tmlo['bewaarTermijn']) === false) {
			return null;
		}

		try {
			new DateInterval($tmlo['bewaarTermijn']);
		} catch (Exception $e) {
			$got = $tmlo['bewaarTermijn'];
			return "bewaarTermijn must be a valid ISO-8601 duration (e.g., P7Y, P5Y6M). Got: {$got}";
		}

		return null;
	}//end bewaarTermijnError()

	/**
	 * Error for an archiefactiedatum that is not an ISO-8601 date.
	 *
	 * @param array $tmlo The TMLO metadata to validate
	 *
	 * @return string|null The error, or null when absent or valid
	 */
	private function archiefactiedatumError(array $tmlo): ?string {
		if (isset($tmlo['archiefactiedatum']) === false) {
			return null;
		}

		$date = date_create_from_format('Y-m-d', $tmlo['archiefactiedatum']);
		if ($date === false || $date->format('Y-m-d') !== $tmlo['archiefactiedatum']) {
			$got = $tmlo['archiefactiedatum'];
			return "archiefactiedatum must be a valid ISO-8601 date (YYYY-MM-DD). Got: {$got}";
		}

		return null;
	}//end archiefactiedatumError()

	/**
	 * Validate an archival status transition.
	 *
	 * Checks that:
	 * 1. The transition is allowed per the state machine
	 * 2. Required fields are present for the target status
	 * 3. archiefnominatie matches the target status
	 *
	 * @param array $tmlo The full TMLO metadata (with new archiefstatus)
	 * @param string $oldStatus The current/old archiefstatus
	 *
	 * @return array Array of validation errors (empty if valid)
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-tmlo-metadata/tasks.md#task-4
	 */
	public function validateStatusTransition(array $tmlo, string $oldStatus): array {
		$newStatus = ($tmlo['archiefstatus'] ?? null);

		// No change in status.
		if ($newStatus === null || $newStatus === $oldStatus) {
			return [];
		}

		// Check if the transition is allowed.
		if (in_array($newStatus, (self::VALID_TRANSITIONS[$oldStatus] ?? []), true) === false) {
			return [$this->transitionNotAllowedError(oldStatus: $oldStatus, newStatus: $newStatus)];
		}

		// Validate required fields and nominatie for the target status.
		return $this->transitionRequirementErrors(tmlo: $tmlo, newStatus: $newStatus);
	}//end validateStatusTransition()

	/**
	 * The error for a transition the state machine does not allow.
	 *
	 * @param string $oldStatus The current/old archiefstatus
	 * @param mixed  $newStatus The requested archiefstatus
	 *
	 * @return string The error, naming the statuses reachable from $oldStatus
	 */
	private function transitionNotAllowedError(string $oldStatus, mixed $newStatus): string {
		$allowedTargets = (self::VALID_TRANSITIONS[$oldStatus] ?? []);
		$allowed = 'none (terminal state)';
		if (empty($allowedTargets) === false) {
			$allowed = implode(', ', $allowedTargets);
		}//end if

		return sprintf(
			"Transition from '%s' to '%s' is not allowed. Allowed transitions from '%s': %s",
			$oldStatus,
			$newStatus,
			$oldStatus,
			$allowed
		);
	}//end transitionNotAllowedError()

	/**
	 * Errors for the fields and nominatie an allowed target status requires.
	 *
	 * @param array  $tmlo      The full TMLO metadata (with new archiefstatus)
	 * @param string $newStatus The requested (allowed) archiefstatus
	 *
	 * @return array Array of validation errors (empty if valid)
	 */
	private function transitionRequirementErrors(array $tmlo, string $newStatus): array {
		$requirement = (self::TRANSITION_REQUIREMENTS[$newStatus] ?? null);
		if ($requirement === null) {
			return [];
		}

		$errors = $this->missingFieldErrors(tmlo: $tmlo, newStatus: $newStatus);
		if (($tmlo['archiefnominatie'] ?? null) !== $requirement['nominatie']) {
			$errors[] = "archiefnominatie must be '{$requirement['nominatie']}' for transition to '{$newStatus}'";
		}

		return $errors;
	}//end transitionRequirementErrors()

	/**
	 * Errors for required fields that are absent, null or empty.
	 *
	 * @param array  $tmlo      The full TMLO metadata (with new archiefstatus)
	 * @param string $newStatus The requested (allowed) archiefstatus, which has transition requirements
	 *
	 * @return array Array of validation errors, in the order of the required fields
	 */
	private function missingFieldErrors(array $tmlo, string $newStatus): array {
		$errors = [];
		foreach (self::TRANSITION_REQUIREMENTS[$newStatus]['fields'] as $field) {
			if (($tmlo[$field] ?? null) === null || $tmlo[$field] === '') {
				$errors[] = "Field '{$field}' is required for transition to '{$newStatus}'";
			}
		}

		return $errors;
	}//end missingFieldErrors()

	/**
	 * Generate the MDTO document for a single object.
	 *
	 * ## One implementation of the format, not two
	 *
	 * This used to build its own DOM, and the result did not validate: its
	 * root was `mdto:informatieobject` where the XSD declares `MDTO`, and it
	 * emitted `archiefactiedatum`, `archiefstatus` and `vernietigingsCategorie`,
	 * none of which is an MDTO element. It now delegates to
	 * {@see MdtoXmlGenerator}, which is held to the vendored XSD by a test, so
	 * this endpoint and the e-Depot export cannot drift apart again.
	 *
	 * The TMLO facts are not lost. {@see \OCA\OpenRegister\Service\Edepot\MdtoSourceReader}
	 * reads the `tmlo` block as well as the `retention` block, so
	 * `bewaarTermijn` becomes `bewaartermijn/termijnLooptijd`,
	 * `archiefactiedatum` becomes `bewaartermijn/termijnEinddatum`,
	 * `vernietigingsCategorie` becomes `informatiecategorie` and
	 * `classification` becomes `classificatie`. Only `archiefstatus` has no
	 * MDTO element, and MDTO expresses that lifecycle through `event`
	 * instead, which the generator derives from the audit trail.
	 *
	 * @param ObjectEntity $object The object to export
	 *
	 * @return string The MDTO XML string
	 *
	 * @throws InvalidArgumentException If the object has no TMLO metadata
	 *
	 * @spec openspec/specs/tmlo-export/spec.md#requirement-mdto-compliant-xml-export
	 */
	public function generateMdtoXml(ObjectEntity $object): string {
		if ($this->hasTmlo(object: $object) === false) {
			throw new InvalidArgumentException(
				'Object ' . $object->getUuid() . ' has no TMLO metadata. MDTO export requires TMLO metadata.'
			);
		}

		return $this->mdtoGenerator->generate($object);
	}//end generateMdtoXml()

	/**
	 * Generate MDTO documents for multiple objects, in one envelope.
	 *
	 * MDTO has no batch container: an `MDTO` document holds exactly one
	 * informatieobject or one bestand. So the envelope element is openregister's
	 * own, in openregister's own namespace, and every child of it is a
	 * complete, valid MDTO document. Nothing here claims the envelope is MDTO,
	 * which is the mistake the previous `mdto:informatieobjecten` wrapper made
	 * by putting a made-up element in the MDTO namespace.
	 *
	 * An object without TMLO metadata is skipped rather than failing the batch.
	 *
	 * @param ObjectEntity[] $objects Array of objects to export
	 *
	 * @return string The envelope XML
	 *
	 * @spec openspec/specs/tmlo-export/spec.md#requirement-mdto-compliant-xml-export
	 */
	public function generateBatchMdtoXml(array $objects): string {
		$dom = new DOMDocument('1.0', 'UTF-8');
		$dom->formatOutput = true;

		$envelope = $dom->createElementNS(self::EXPORT_NAMESPACE, self::EXPORT_PREFIX . ':mdtoExport');
		$dom->appendChild($envelope);

		foreach ($objects as $object) {
			if ($this->hasTmlo(object: $object) === true) {
				$envelope->appendChild($this->importMdtoDocument(dom: $dom, object: $object));
			}
		}

		return (string)$dom->saveXML();
	}//end generateBatchMdtoXml()

	/**
	 * Whether the object carries a non-empty TMLO metadata array.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return bool
	 */
	private function hasTmlo(ObjectEntity $object): bool {
		$tmlo = $object->getTmlo();
		return is_array($tmlo) === true && empty($tmlo) === false;
	}//end hasTmlo()

	/**
	 * Generate the object's MDTO document and import its root into the envelope document.
	 *
	 * @param DOMDocument  $dom    The envelope document.
	 * @param ObjectEntity $object The object to export.
	 *
	 * @return \DOMNode The imported MDTO root element.
	 */
	private function importMdtoDocument(DOMDocument $dom, ObjectEntity $object): \DOMNode {
		$document = new DOMDocument('1.0', 'UTF-8');
		$document->loadXML($this->mdtoGenerator->generate($object));

		return $dom->importNode($document->documentElement, true);
	}//end importMdtoDocument()

}//end class
