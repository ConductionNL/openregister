<?php

/**
 * OpenRegister Retention Service
 *
 * Orchestrates archival lifecycle operations: metadata population, archiefactiedatum
 * calculation, selectielijst lookup, legal hold management, and destruction coordination.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/retention-management/spec.md#requirement-objects-must-carry-mdto-compliant-archival-metadata-in-the-retention-field
 * @spec openspec/specs/retention-management/spec.md#requirement-the-system-must-calculate-archiefactiedatum-using-configurable-afleidingswijzen
 * @spec openspec/specs/retention-management/spec.md#requirement-the-system-must-generate-destruction-lists-via-a-background-job
 * @spec openspec/specs/retention-management/spec.md
 * @spec openspec/specs/retention-management/spec.md
 * @spec openspec/specs/retention-management/spec.md
 * @spec openspec/specs/retention-management/spec.md
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateInterval;
use DateTime;
use Exception;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Service for managing retention lifecycle of register objects.
 *
 * Handles MDTO-compliant archival metadata, selectielijst lookups,
 * archiefactiedatum calculation, legal holds, and destruction workflows.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class RetentionService {

	/**
	 * Valid archiefnominatie values.
	 */
	private const VALID_NOMINATIES = ['vernietigen', 'bewaren', 'nog_niet_bepaald'];

	/**
	 * Valid archiefstatus values.
	 */
	private const VALID_STATUSES = [
		'nog_te_archiveren',
		'gearchiveerd',
		'vernietigd',
		'overgebracht',
	];

	/**
	 * Immutable archival statuses (no further updates allowed).
	 */
	private const IMMUTABLE_STATUSES = ['vernietigd', 'overgebracht'];

	/**
	 * Valid afleidingswijze methods.
	 */
	private const VALID_AFLEIDINGSWIJZEN = ['afgehandeld', 'eigenschap', 'termijn'];

	/**
	 * Constructor.
	 *
	 * @param MagicMapper $objectMapper Object mapper for queries
	 * @param SchemaMapper $schemaMapper Schema mapper for lookups
	 * @param RegisterMapper $registerMapper Register mapper for lookups
	 * @param AuditTrailMapper $auditMapper Audit trail mapper
	 * @param ObjectRetentionHandler $settingsHandler Retention settings handler
	 * @param IAppConfig $appConfig App configuration
	 * @param IUserSession $userSession Current user session
	 * @param LoggerInterface $logger Logger
	 * @param IDBConnection $db Database connection for eligibility queries
	 */
	public function __construct(
		private readonly MagicMapper $objectMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly RegisterMapper $registerMapper,
		private readonly AuditTrailMapper $auditMapper,
		private readonly ObjectRetentionHandler $settingsHandler,
		private readonly IAppConfig $appConfig,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly IDBConnection $db,
	) {
	}//end __construct()

	/**
	 * Apply archival metadata to an object based on its schema's archive configuration.
	 *
	 * Called during object creation to populate retention fields from schema defaults
	 * and selectielijst mappings.
	 *
	 * @param ObjectEntity $object The object entity to populate
	 * @param Schema $schema The schema with archive configuration
	 *
	 * @return ObjectEntity The object with archival metadata applied
	 *
	 * @spec openspec/specs/retention-management/spec.md#requirement-objects-must-carry-mdto-compliant-archival-metadata-in-the-retention-field
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function applyArchivalMetadata(ObjectEntity $object, Schema $schema): ObjectEntity {
		$archiveConfig = $schema->getArchive();

		// Skip if archive is not enabled for this schema.
		if (empty($archiveConfig) === true || ($archiveConfig['enabled'] ?? false) === false) {
			return $object;
		}

		$retention = $object->getRetention() ?? [];

		// Do not overwrite if archival metadata already present.
		if (empty($retention['archiefnominatie']) === false) {
			return $object;
		}

		$applied = $this->resolveArchivalDefaults(archiveConfig: $archiveConfig);
		$retentionPeriod = $applied['bewaartermijn'];

		// Build archival metadata.
		$retention['archiefnominatie'] = $applied['archiefnominatie'];
		$retention['archiefstatus'] = 'nog_te_archiveren';
		$retention['classification'] = ($archiveConfig['classification'] ?? null);
		$retention['bewaartermijn'] = $retentionPeriod;
		$retention['selectielijstBron'] = $applied['selectielijstBron'];

		// GAP B1. WHICH list is not WHICH VERSION OF THAT LIST. The same
		// category carries different retention periods across revisions, so a
		// decision recorded with only the list's name cannot be justified once
		// the list moves. These are English keys beside a Dutch one on purpose:
		// `selectielijstBron` predates the vocabulary decision and existing
		// consumers read it, while everything new speaks the abstract layer's
		// language.
		foreach ($applied['provenance'] as $key => $value) {
			$retention[$key] = $value;
		}

		// Calculate archiefactiedatum if bewaartermijn is set.
		if ($retentionPeriod !== null) {
			$retention['archiefactiedatum'] = $this->calculateArchiveActionDate(
				object: $object,
				schema: $schema,
				retentionPeriod: $retentionPeriod
			);
		}

		$object->setRetention($retention);

		return $object;
	}//end applyArchivalMetadata()

	/**
	 * Decide the nominatie, the retention period and where they came from.
	 *
	 * Three sources in precedence order: the selectielijst entry the schema's
	 * classification points at, then the schema's own defaults, then the
	 * schema's explicit `bewaartermijnOverride`, which wins over both because
	 * it is a deliberate local decision rather than a fallback.
	 *
	 * @param array $archiveConfig The schema's archive block
	 *
	 * @return array{archiefnominatie: string, bewaartermijn: string|null, selectielijstBron: string|null, provenance: array<string, string>}
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	private function resolveArchivalDefaults(array $archiveConfig): array {
		$applied = [
			'archiefnominatie' => ($archiveConfig['defaultNominatie'] ?? 'nog_niet_bepaald'),
			'bewaartermijn' => ($archiveConfig['defaultBewaartermijn'] ?? null),
			'selectielijstBron' => null,
			'provenance' => [],
		];

		$classification = $archiveConfig['classification'] ?? null;
		$entry = null;
		if ($classification !== null) {
			$entry = $this->findSelectielijstEntry(category: $classification);
		}

		if ($entry !== null) {
			$data = $entry->getObject();
			$applied['archiefnominatie'] = ($data['archiefnominatie'] ?? 'nog_niet_bepaald');
			$applied['bewaartermijn'] = ($data['bewaartermijn'] ?? null);
			$applied['selectielijstBron'] = ($data['bron'] ?? null);
			$applied['provenance'] = $this->selectielijstProvenance(entry: $entry);
		}

		if (empty($archiveConfig['bewaartermijnOverride']) === false) {
			$applied['bewaartermijn'] = $archiveConfig['bewaartermijnOverride'];
		}

		return $applied;
	}//end resolveArchivalDefaults()

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
	public function calculateArchiveActionDate(
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
				'[RetentionService] Unsupported afleidingswijze; no archiefactiedatum calculated',
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
				'[RetentionService] Invalid bewaartermijn format: ' . $retentionPeriod,
				['exception' => $e]
			);
			return null;
		}

		$brondatum = $this->determineBrondatum(object: $object, schema: $schema, afleidingswijze: $afleidingswijze);

		if ($brondatum === null) {
			// The object's CREATED date, which is what this comment always
			// claimed and what the code did not do: `new DateTime()` is *now*,
			// so two identical records processed a year apart got disposal
			// dates a year apart, and a record recalculated long after the fact
			// got one far later than lawful. Gap C3 in
			// openspec/changes/archival-conformance.
			$brondatum = $object->getCreated();
			if ($brondatum === null) {
				$brondatum = new DateTime();
			}
		}

		// Never mutate the entity's own DateTime: `->add()` below is in-place,
		// and getCreated() hands back the LIVE object, so a disposal-date
		// calculation would silently move the object's created timestamp.
		// `clone` rather than DateTime::createFromInterface() because phpmd
		// refuses static access and every path here already yields a DateTime.
		$brondatum = clone $brondatum;

		// For 'termijn' method, add procestermijn first.
		if ($afleidingswijze === 'termijn') {
			$procestermijn = $archiveConfig['procestermijn'] ?? null;
			if ($procestermijn !== null) {
				try {
					$brondatum->add(new DateInterval($procestermijn));
				} catch (Exception $e) {
					$this->logger->warning(
						'[RetentionService] Invalid procestermijn format: ' . $procestermijn,
						['exception' => $e]
					);
				}
			}
		}

		$brondatum->add($interval);

		return $brondatum->format('Y-m-d');
	}//end calculateArchiveActionDate()

	/**
	 * Determine the brondatum (source date) based on afleidingswijze.
	 *
	 * @param ObjectEntity $object The object
	 * @param Schema $schema The schema
	 * @param string $afleidingswijze The derivation method
	 *
	 * @return DateTime|null The source date or null
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	private function determineBrondatum(
		ObjectEntity $object,
		Schema $schema,
		string $afleidingswijze,
	): ?DateTime {
		$archiveConfig = $schema->getArchive();
		$objectData = $object->getObject();

		switch ($afleidingswijze) {
			case 'eigenschap':
				$sourceAttribute = $archiveConfig['bronEigenschap'] ?? null;
				if ($sourceAttribute !== null && isset($objectData[$sourceAttribute]) === true) {
					try {
						return new DateTime($objectData[$sourceAttribute]);
					} catch (Exception $e) {
						$this->logger->warning(
							'[RetentionService] Cannot parse bronEigenschap date: ' . $objectData[$sourceAttribute]
						);
					}
				}
				return null;
			case 'afgehandeld':
			case 'termijn':
				// Check for closure date via configured closure field.
				$closureField = $archiveConfig['closureField'] ?? null;
				if ($closureField !== null && isset($objectData[$closureField]) === true) {
					try {
						return new DateTime($objectData[$closureField]);
					} catch (Exception $e) {
						$this->logger->warning(
							'[RetentionService] Cannot parse closure date: ' . $objectData[$closureField]
						);
					}
				}

				// Fallback: use current date (object creation).
				return null;
			default:
				return null;
		}//end switch
	}//end determineBrondatum()

	/**
	 * Recalculate archiefactiedatum when a source property changes.
	 *
	 * @param ObjectEntity $object The object being updated
	 * @param Schema $schema The schema
	 * @param array $oldObject The previous object data
	 *
	 * @return ObjectEntity The object with recalculated dates
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 */
	public function recalculateArchiveActionDate(
		ObjectEntity $object,
		Schema $schema,
		array $oldObject,
	): ObjectEntity {
		$archiveConfig = $schema->getArchive();

		if (empty($archiveConfig) === true || ($archiveConfig['enabled'] ?? false) === false) {
			return $object;
		}

		$retention = $object->getRetention() ?? [];

		// Skip if no archival metadata present.
		if (empty($retention['archiefnominatie']) === true) {
			return $object;
		}

		// Skip if in immutable status.
		if (in_array($retention['archiefstatus'] ?? '', self::IMMUTABLE_STATUSES, true) === true) {
			return $object;
		}

		$retentionPeriod = $retention['bewaartermijn'] ?? null;
		if ($retentionPeriod === null) {
			return $object;
		}

		// Check if the source property changed.
		$afleidingswijze = $archiveConfig['afleidingswijze'] ?? 'afgehandeld';
		$propertyChanged = false;

		if ($afleidingswijze === 'eigenschap') {
			$sourceAttribute = $archiveConfig['bronEigenschap'] ?? null;
			if ($sourceAttribute !== null) {
				$newData = $object->getObject();
				$oldVal = $oldObject[$sourceAttribute] ?? null;
				$newVal = $newData[$sourceAttribute] ?? null;
				$propertyChanged = ($oldVal !== $newVal);
			}
		} elseif (in_array($afleidingswijze, ['afgehandeld', 'termijn'], true) === true) {
			$closureField = $archiveConfig['closureField'] ?? null;
			if ($closureField !== null) {
				$newData = $object->getObject();
				$oldVal = $oldObject[$closureField] ?? null;
				$newVal = $newData[$closureField] ?? null;
				$propertyChanged = ($oldVal !== $newVal);
			}
		}

		if ($propertyChanged === false) {
			return $object;
		}

		$oldDate = $retention['archiefactiedatum'] ?? null;
		$newDate = $this->calculateArchiveActionDate(object: $object, schema: $schema, retentionPeriod: $retentionPeriod);

		if ($newDate !== null && $newDate !== $oldDate) {
			$retention['archiefactiedatum'] = $newDate;
			$object->setRetention($retention);

			$msg = sprintf(
				'[RetentionService] Recalculated archiefactiedatum for object %s: %s -> %s',
				$object->getUuid(),
				$oldDate,
				$newDate
			);
			$this->logger->info($msg);
		}

		return $object;
	}//end recalculateArchiveActionDate()

	/**
	 * Look up a selectielijst entry by categorie code.
	 *
	 * @param string $category The selectielijst category code (e.g., B1, A1)
	 *
	 * @return array|null The selectielijst entry data or null if not found
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function lookupSelectielijstEntry(string $category): ?array {
		$entry = $this->findSelectielijstEntry(category: $category);

		if ($entry === null) {
			return null;
		}

		return $entry->getObject();
	}//end lookupSelectielijstEntry()

	/**
	 * Find the selectielijst entry ENTITY for a categorie code.
	 *
	 * Split out from lookupSelectielijstEntry because `getObject()` drops the
	 * `@self` envelope, and the envelope is where the row's own version and
	 * update timestamp live. Gap B1 in openspec/changes/archival-conformance:
	 * without them a disposal decision can say WHICH list it came from but not
	 * WHICH VERSION OF THAT LIST, and the same category carries different
	 * retention periods across selectielijst revisions. Five years on, that is
	 * the difference between a decision you can justify and one you cannot.
	 *
	 * @param string $category The selectielijst category code (e.g., B1, A1)
	 *
	 * @return ObjectEntity|null The entry, or null when unconfigured or absent
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	private function findSelectielijstEntry(string $category): ?ObjectEntity {
		$settings = $this->settingsHandler->getArchivalSettingsOnly();

		$registerId = $settings['selectielijstRegister'] ?? null;
		$schemaId = $settings['selectielijstSchema'] ?? null;

		if ($registerId === null || $schemaId === null) {
			return null;
		}

		try {
			$register = $this->registerMapper->find((int)$registerId);
			$schema = $this->schemaMapper->find((int)$schemaId);

			$results = $this->objectMapper->findAll(
				limit: 1,
				filters: ['object->categorie' => $category],
				register: $register,
				schema: $schema
			);

			if (empty($results) === true) {
				return null;
			}

			return $results[0];
		} catch (Exception $e) {
			$this->logger->warning(
				'[RetentionService] Failed to lookup selectielijst entry for ' . $category,
				['exception' => $e]
			);
			return null;
		}//end try
	}//end findSelectielijstEntry()

	/**
	 * Read the provenance of a selectielijst entry: which version, read when.
	 *
	 * Three sources, in the order an auditor would trust them:
	 *
	 *  1. a `versie` or `version` the row itself declares, which is the list
	 *     publisher's own numbering and the only one that means anything
	 *     outside this install;
	 *  2. failing that, the entry object's own `@self.version`, which says
	 *     which revision of the stored row was read even when the publisher
	 *     numbered nothing;
	 *  3. the moment it was read, always, because a version alone does not say
	 *     whether the decision predates a later revision.
	 *
	 * Returns an empty array rather than nulls when nothing can be
	 * established: an absent key is honest, and a key holding null reads as a
	 * recorded answer of "no version".
	 *
	 * @param ObjectEntity $entry The selectielijst entry that was applied
	 *
	 * @return array<string, string> The provenance keys, possibly empty
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	private function selectielijstProvenance(ObjectEntity $entry): array {
		$provenance = ['selectionListConsultedAt' => (new DateTime())->format('c')];

		$data = $entry->getObject();
		$declared = null;
		if (is_array($data) === true) {
			$declared = ($data['versie'] ?? ($data['version'] ?? null));
		}

		$version = $this->stringOrNull(value: $declared);
		if ($version === null) {
			$version = $this->stringOrNull(value: $entry->getVersion());
		}

		if ($version !== null) {
			$provenance['selectionListVersion'] = $version;
		}

		return $provenance;
	}//end selectielijstProvenance()

	/**
	 * A non-empty trimmed string, or null.
	 *
	 * @param mixed $value The candidate value
	 *
	 * @return string|null The string, or null when it says nothing
	 */
	private function stringOrNull(mixed $value): ?string {
		if (is_string($value) === false && is_int($value) === false) {
			return null;
		}

		$text = trim((string)$value);
		if ($text === '') {
			return null;
		}

		return $text;
	}//end stringOrNull()

	/**
	 * Validate that an object is not in an immutable archival status.
	 *
	 * @param ObjectEntity $object The object to check
	 *
	 * @return string|null Error code if immutable, null if mutable
	 *
	 * @spec exclude Archival-status immutability guard (vernietigd/overgebracht → error code); sibling of the
	 *              already-annotated archival methods, owned by archival-destruction-workflow. No distinct contract.
	 */
	public function validateNotImmutable(ObjectEntity $object): ?string {
		$retention = $object->getRetention() ?? [];
		$status = $retention['archiefstatus'] ?? null;

		if ($status === 'vernietigd') {
			return 'OBJECT_DESTROYED';
		}

		if ($status === 'overgebracht') {
			return 'OBJECT_TRANSFERRED';
		}

		return null;
	}//end validateNotImmutable()

	/**
	 * Place a legal hold on an object.
	 *
	 * @param ObjectEntity $object The object to place hold on
	 * @param string $reason The reason for the legal hold
	 *
	 * @return ObjectEntity The object with legal hold applied
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function placeLegalHold(ObjectEntity $object, string $reason): ObjectEntity {
		$retention = $object->getRetention() ?? [];
		$user = $this->userSession->getUser();
		$userId = 'system';
		if ($user !== null) {
			$userId = $user->getUID();
		}

		$retention['legalHold'] = [
			'active' => true,
			'reason' => $reason,
			'placedBy' => $userId,
			'placedDate' => (new DateTime())->format('c'),
			'history' => $retention['legalHold']['history'] ?? [],
		];

		$object->setRetention($retention);

		return $object;
	}//end placeLegalHold()

	/**
	 * Release a legal hold on an object.
	 *
	 * @param ObjectEntity $object The object to release hold from
	 * @param string $reason The reason for releasing the hold
	 *
	 * @return ObjectEntity The object with legal hold released
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function releaseLegalHold(ObjectEntity $object, string $reason): ObjectEntity {
		$retention = $object->getRetention() ?? [];
		$legalHold = $retention['legalHold'] ?? null;

		if ($legalHold === null || ($legalHold['active'] ?? false) === false) {
			return $object;
		}

		$user = $this->userSession->getUser();
		$userId = 'system';
		if ($user !== null) {
			$userId = $user->getUID();
		}

		// Move current hold to history.
		$historyEntry = [
			'reason' => $legalHold['reason'] ?? '',
			'placedBy' => $legalHold['placedBy'] ?? '',
			'placedDate' => $legalHold['placedDate'] ?? '',
			'releasedBy' => $userId,
			'releasedDate' => (new DateTime())->format('c'),
			'releaseReason' => $reason,
		];

		$history = $legalHold['history'] ?? [];
		$history[] = $historyEntry;

		$retention['legalHold'] = [
			'active' => false,
			'history' => $history,
		];

		$object->setRetention($retention);

		return $object;
	}//end releaseLegalHold()

	/**
	 * Check if an object has an active legal hold.
	 *
	 * Delegates to {@see ObjectEntity::hasActiveLegalHold()}, the single
	 * definition of "held". Kept as a service method because the destruction
	 * and sweep jobs already ask the question here.
	 *
	 * @param ObjectEntity $object The object to check
	 *
	 * @return bool True if object has active legal hold
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function hasActiveLegalHold(ObjectEntity $object): bool {
		return $object->hasActiveLegalHold();
	}//end hasActiveLegalHold()

	/**
	 * Extend archiefactiedatum by a period for excluded/rejected objects.
	 *
	 * @param ObjectEntity $object The object to extend
	 * @param string|null $extensionPeriod ISO 8601 duration (default from settings)
	 *
	 * @return ObjectEntity The object with extended archiefactiedatum
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function extendArchiveActionDate(ObjectEntity $object, ?string $extensionPeriod = null): ObjectEntity {
		$retention = $object->getRetention() ?? [];

		if (empty($retention['archiefactiedatum']) === true) {
			return $object;
		}

		if ($extensionPeriod === null) {
			$settings = $this->settingsHandler->getArchivalSettingsOnly();
			$extensionPeriod = $settings['defaultExtensionPeriod'] ?? 'P1Y';
		}

		try {
			$date = new DateTime($retention['archiefactiedatum']);
			$date->add(new DateInterval($extensionPeriod));
			$retention['archiefactiedatum'] = $date->format('Y-m-d');

			// Store original date if not already stored.
			if (empty($retention['originalArchiefactiedatum']) === true) {
				$retention['originalArchiefactiedatum'] = $retention['archiefactiedatum'];
			}

			$object->setRetention($retention);
		} catch (Exception $e) {
			$this->logger->warning(
				'[RetentionService] Failed to extend archiefactiedatum: ' . $e->getMessage()
			);
		}

		return $object;
	}//end extendArchiveActionDate()

	/**
	 * Find objects eligible for destruction.
	 *
	 * Objects with archiefactiedatum < now, archiefnominatie = vernietigen,
	 * archiefstatus = nog_te_archiveren, no active legal hold, and not already
	 * on a pending destruction list.
	 *
	 * @param array $excludeUuids UUIDs to exclude (already on pending lists)
	 *
	 * @return ObjectEntity[] Array of eligible objects
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function findEligibleForDestruction(array $excludeUuids = []): array {
		$today = (new DateTime())->format('Y-m-d');

		try {
			// Query objects with archival metadata indicating destruction eligibility.
			$qb = $this->db->getQueryBuilder();

			$qb->select('id')
				->from('openregister_objects')
				->where(
					$qb->expr()->isNotNull('retention')
				);

			$result = $qb->executeQuery();
			$rows = $result->fetchAll();
			$result->closeCursor();

			$eligible = [];

			foreach ($rows as $row) {
				try {
					$object = $this->objectMapper->find(intval($row['id']), null, null, false, false, false);
				} catch (Exception $e) {
					continue;
				}

				$retention = $object->getRetention() ?? [];

				// Check eligibility criteria.
				if (($retention['archiefnominatie'] ?? '') !== 'vernietigen') {
					continue;
				}

				if (($retention['archiefstatus'] ?? '') !== 'nog_te_archiveren') {
					continue;
				}

				$actiedatum = $retention['archiefactiedatum'] ?? null;
				if ($actiedatum === null || $actiedatum > $today) {
					continue;
				}

				// Skip objects in an immutable archival status (vernietigd, overgebracht).
				if ($this->validateNotImmutable(object: $object) !== null) {
					continue;
				}

				// Skip objects with active legal hold.
				if (($retention['legalHold']['active'] ?? false) === true) {
					continue;
				}

				// Skip objects already on pending lists.
				if (in_array($object->getUuid(), $excludeUuids, true) === true) {
					continue;
				}

				$eligible[] = $object;
			}//end foreach

			return $eligible;
		} catch (Exception $e) {
			$this->logger->error(
				'[RetentionService] Failed to find eligible objects for destruction: ' . $e->getMessage(),
				['exception' => $e]
			);
			return [];
		}//end try
	}//end findEligibleForDestruction()

	/**
	 * Get UUIDs of objects already on pending destruction lists.
	 *
	 * @return string[] Array of object UUIDs
	 *
	 * @spec openspec/specs/retention-management/spec.md#requirement-the-system-must-generate-destruction-lists-via-a-background-job
	 */
	public function getObjectsOnPendingDestructionLists(): array {
		$settings = $this->settingsHandler->getArchivalSettingsOnly();

		$registerId = $settings['destructionListRegister'] ?? null;
		$schemaId = $settings['destructionListSchema'] ?? null;

		if ($registerId === null || $schemaId === null) {
			return [];
		}

		try {
			$register = $this->registerMapper->find((int)$registerId);
			$schema = $this->schemaMapper->find((int)$schemaId);

			$pendingLists = $this->objectMapper->findAll(
				filters: [
					'object->status' => ['in_review', 'approved', 'awaiting_second_approval'],
				],
				register: $register,
				schema: $schema
			);

			$uuids = [];
			foreach ($pendingLists as $list) {
				$listData = $list->getObject();
				$objects = $listData['objects'] ?? [];
				foreach ($objects as $obj) {
					$uuid = $obj['uuid'] ?? null;
					if ($uuid !== null) {
						$uuids[] = $uuid;
					}
				}
			}

			return array_unique($uuids);
		} catch (Exception $e) {
			$this->logger->warning(
				'[RetentionService] Failed to get pending destruction list objects: ' . $e->getMessage()
			);
			return [];
		}//end try
	}//end getObjectsOnPendingDestructionLists()

	/**
	 * Create a destruction list as a register object.
	 *
	 * @param ObjectEntity[] $objects The objects to include in the destruction list
	 *
	 * @return array|null The destruction list data or null on failure
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function createDestructionList(array $objects): ?array {
		$settings = $this->settingsHandler->getArchivalSettingsOnly();

		$registerId = $settings['destructionListRegister'] ?? null;
		$schemaId = $settings['destructionListSchema'] ?? null;

		if ($registerId === null || $schemaId === null) {
			$this->logger->warning(
				'[RetentionService] Cannot create destruction list: register/schema not configured'
			);
			return null;
		}

		$user = $this->userSession->getUser();
		$userId = 'system';
		if ($user !== null) {
			$userId = $user->getUID();
		}

		$objectEntries = [];
		foreach ($objects as $object) {
			$retention = $object->getRetention() ?? [];
			// Detect WOO-published status from object data or metadata.
			$objectData = $object->getObject() ?? [];
			$isWooPublished = ($objectData['woo_gepubliceerd'] ?? false) === true
				|| ($objectData['publicatiestatus'] ?? null) === 'gepubliceerd'
				|| ($retention['wooPublished'] ?? false) === true;

			$objectEntries[] = [
				'uuid' => $object->getUuid(),
				'title' => $object->getTitle() ?? $object->getUuid(),
				'schema' => $object->getSchema(),
				'register' => $object->getRegister(),
				'archiefactiedatum' => $retention['archiefactiedatum'] ?? null,
				'classification' => $retention['classification'] ?? null,
				'softDeleted' => $object->isSoftDeleted(),
				'wooGepubliceerd' => $isWooPublished,
			];
		}

		return [
			'status' => 'in_review',
			'createdBy' => $userId,
			'createdAt' => (new DateTime())->format('c'),
			'objects' => $objectEntries,
			'excluded' => [],
			'approvals' => [],
		];
	}//end createDestructionList()

	/**
	 * Generate a destruction certificate after execution.
	 *
	 * @param array $destructionList The destruction list data
	 * @param int $destroyedCount Number of objects destroyed
	 * @param string $executedAt ISO 8601 timestamp of execution
	 *
	 * @return array The destruction certificate data
	 *
	 * @spec openspec/specs/retention-management/spec.md#requirement-the-system-must-generate-destruction-lists-via-a-background-job
	 * @spec openspec/specs/retention-management/spec.md
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function generateDestructionCertificate(
		array $destructionList,
		int $destroyedCount,
		string $executedAt,
	): array {
		// Group destroyed objects by schema and classificatie.
		$grouped = [];
		foreach ($destructionList['objects'] ?? [] as $obj) {
			$key = ($obj['schema'] ?? 'unknown') . '/' . ($obj['classification'] ?? 'unknown');
			if (isset($grouped[$key]) === false) {
				$grouped[$key] = [
					'schema' => $obj['schema'] ?? 'unknown',
					'classification' => $obj['classification'] ?? 'unknown',
					'count' => 0,
				];
			}

			$grouped[$key]['count']++;
		}

		return [
			'type' => 'verklaring_van_vernietiging',
			'destructionDate' => $executedAt,
			'approvedBy' => array_column($destructionList['approvals'] ?? [], 'userId'),
			'destructionListUuid' => $destructionList['uuid'] ?? null,
			'totalDestroyed' => $destroyedCount,
			'groupedBySchema' => array_values($grouped),
			'selectielijstBron' => $this->extractSelectionListSource(destructionList: $destructionList),
			'complianceStatement' => 'Vernietiging conform Archiefwet 1995 en Archiefbesluit 1995',
			'immutable' => true,
		];
	}//end generateDestructionCertificate()

	/**
	 * Extract selectielijst bron references from a destruction list.
	 *
	 * @param array $destructionList The destruction list data
	 *
	 * @return string[] Unique selectielijst bron references
	 */
	private function extractSelectionListSource(array $destructionList): array {
		$bronnen = [];
		foreach ($destructionList['objects'] ?? [] as $obj) {
			$source = $obj['selectielijstBron'] ?? null;
			if ($source !== null) {
				$bronnen[] = $source;
			}
		}

		return array_values(array_unique($bronnen));
	}//end extractSelectionListSource()
}//end class
