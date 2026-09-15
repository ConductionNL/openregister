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
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
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
class TmloService {

	/**
	 * Valid values for archiefnominatie field
	 */
	public const ARCHIEFNOMINATIE_BLIJVEND_BEWAREN = 'blijvend_bewaren';
	public const ARCHIEFNOMINATIE_VERNIETIGEN = 'vernietigen';

	/**
	 * Valid values for archiefstatus field
	 */
	public const ARCHIEFSTATUS_ACTIEF = 'actief';
	public const ARCHIEFSTATUS_SEMI_STATISCH = 'semi_statisch';
	public const ARCHIEFSTATUS_OVERGEBRACHT = 'overgebracht';
	public const ARCHIEFSTATUS_VERNIETIGD = 'vernietigd';

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
	 * Constructor.
	 *
	 * @param RegisterMapper $registerMapper Register mapper for fetching registers
	 * @param SchemaMapper $schemaMapper Schema mapper for fetching schemas
	 * @param LoggerInterface $logger Logger interface
	 * @param MdtoXmlGenerator $mdtoGenerator The one implementation of the MDTO format
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-tmlo-metadata/tasks.md#task-1
	 */
	public function __construct(
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
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
	 * Populate TMLO defaults on an object entity.
	 *
	 * Merges schema-level TMLO defaults with any explicitly provided TMLO data.
	 * Sets archiefstatus to 'actief' if not already set.
	 * Calculates archiefactiedatum from bewaarTermijn if not explicitly provided.
	 *
	 * @param ObjectEntity $object The object to populate
	 * @param Register $register The register (must have tmloEnabled=true)
	 * @param Schema $schema The schema for default values
	 *
	 * @return ObjectEntity The object with populated TMLO metadata
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-tmlo-metadata/tasks.md#task-2
	 */
	public function populateDefaults(ObjectEntity $object, Register $register, Schema $schema): ObjectEntity {
		if ($this->isTmloEnabled(register: $register) === false) {
			return $object;
		}

		// Get existing TMLO data from the object (may have been set explicitly).
		$tmlo = $object->getTmlo();
		if (is_array($tmlo) === false || empty($tmlo) === true) {
			$tmlo = [];
		}

		// Get schema-level defaults.
		$defaults = $this->getSchemaDefaults(schema: $schema);

		// Merge defaults: only fill in fields that are not already set.
		foreach (self::TMLO_FIELDS as $field) {
			if (isset($tmlo[$field]) === false || $tmlo[$field] === null) {
				$tmlo[$field] = ($defaults[$field] ?? null);
			}
		}

		// Always default archiefstatus to 'actief' if not set.
		if (($tmlo['archiefstatus'] ?? null) === null) {
			$tmlo['archiefstatus'] = self::ARCHIEFSTATUS_ACTIEF;
		}

		// Calculate archiefactiedatum from bewaarTermijn if not explicitly set.
		if (($tmlo['archiefactiedatum'] ?? null) === null && ($tmlo['bewaarTermijn'] ?? null) !== null) {
			$tmlo['archiefactiedatum'] = $this->calculateArchiveActionDate(duration: $tmlo['bewaarTermijn']);
		}

		$object->setTmlo($tmlo);

		return $object;
	}//end populateDefaults()

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
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-tmlo-metadata/tasks.md#task-3
	 */
	public function validateFieldValues(array $tmlo): array {
		$errors = [];

		// Validate archiefnominatie.
		if (isset($tmlo['archiefnominatie']) === true
			&& in_array($tmlo['archiefnominatie'], self::VALID_ARCHIEFNOMINATIE, true) === false
		) {
			$allowed = implode(', ', self::VALID_ARCHIEFNOMINATIE);
			$got = $tmlo['archiefnominatie'];
			$errors[] = "archiefnominatie must be one of: {$allowed}. Got: {$got}";
		}

		// Validate archiefstatus.
		if (isset($tmlo['archiefstatus']) === true
			&& in_array($tmlo['archiefstatus'], self::VALID_ARCHIEFSTATUS, true) === false
		) {
			$allowed = implode(', ', self::VALID_ARCHIEFSTATUS);
			$got = $tmlo['archiefstatus'];
			$errors[] = "archiefstatus must be one of: {$allowed}. Got: {$got}";
		}

		// Validate bewaarTermijn as ISO-8601 duration.
		if (isset($tmlo['bewaarTermijn']) === true) {
			try {
				new DateInterval($tmlo['bewaarTermijn']);
			} catch (Exception $e) {
				$got = $tmlo['bewaarTermijn'];
				$errors[] = "bewaarTermijn must be a valid ISO-8601 duration (e.g., P7Y, P5Y6M). Got: {$got}";
			}
		}

		// Validate archiefactiedatum as ISO-8601 date.
		if (isset($tmlo['archiefactiedatum']) === true) {
			$date = DateTime::createFromFormat('Y-m-d', $tmlo['archiefactiedatum']);
			if ($date === false || $date->format('Y-m-d') !== $tmlo['archiefactiedatum']) {
				$got = $tmlo['archiefactiedatum'];
				$errors[] = "archiefactiedatum must be a valid ISO-8601 date (YYYY-MM-DD). Got: {$got}";
			}
		}

		return $errors;
	}//end validateFieldValues()

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
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-tmlo-metadata/tasks.md#task-4
	 */
	public function validateStatusTransition(array $tmlo, string $oldStatus): array {
		$errors = [];
		$newStatus = ($tmlo['archiefstatus'] ?? null);

		// No change in status.
		if ($newStatus === null || $newStatus === $oldStatus) {
			return $errors;
		}

		// Check if the transition is allowed.
		$allowedTargets = (self::VALID_TRANSITIONS[$oldStatus] ?? []);
		if (in_array($newStatus, $allowedTargets, true) === false) {
			$allowed = 'none (terminal state)';
			if (empty($allowedTargets) === false) {
				$allowed = implode(', ', $allowedTargets);
			}//end if

			$errors[] = sprintf(
				"Transition from '%s' to '%s' is not allowed. Allowed transitions from '%s': %s",
				$oldStatus,
				$newStatus,
				$oldStatus,
				$allowed
			);
			return $errors;
		}

		// Validate required fields for transfer (overgebracht).
		if ($newStatus === self::ARCHIEFSTATUS_OVERGEBRACHT) {
			$requiredFields = ['archiefactiedatum', 'classification', 'archiefnominatie'];
			foreach ($requiredFields as $field) {
				if (($tmlo[$field] ?? null) === null || $tmlo[$field] === '') {
					$errors[] = "Field '{$field}' is required for transition to 'overgebracht'";
				}
			}

			if (($tmlo['archiefnominatie'] ?? null) !== self::ARCHIEFNOMINATIE_BLIJVEND_BEWAREN) {
				$errors[] = "archiefnominatie must be 'blijvend_bewaren' for transition to 'overgebracht'";
			}
		}

		// Validate required fields for destruction (vernietigd).
		if ($newStatus === self::ARCHIEFSTATUS_VERNIETIGD) {
			$requiredFields = ['archiefactiedatum', 'classification', 'archiefnominatie', 'vernietigingsCategorie'];
			foreach ($requiredFields as $field) {
				if (($tmlo[$field] ?? null) === null || $tmlo[$field] === '') {
					$errors[] = "Field '{$field}' is required for transition to 'vernietigd'";
				}
			}

			if (($tmlo['archiefnominatie'] ?? null) !== self::ARCHIEFNOMINATIE_VERNIETIGEN) {
				$errors[] = "archiefnominatie must be 'vernietigen' for transition to 'vernietigd'";
			}
		}

		return $errors;
	}//end validateStatusTransition()

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
		$tmlo = $object->getTmlo();
		if (is_array($tmlo) === false || empty($tmlo) === true) {
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
			$tmlo = $object->getTmlo();
			if (is_array($tmlo) === false || empty($tmlo) === true) {
				continue;
			}

			$document = new DOMDocument('1.0', 'UTF-8');
			$document->loadXML($this->mdtoGenerator->generate($object));

			$imported = $dom->importNode($document->documentElement, true);
			$envelope->appendChild($imported);
		}

		return (string)$dom->saveXML();
	}//end generateBatchMdtoXml()

}//end class
