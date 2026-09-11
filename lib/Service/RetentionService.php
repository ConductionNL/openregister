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
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\Appraisal;
use OCA\OpenRegister\Service\Archival\ArchiveActionDateCalculator;
use OCA\OpenRegister\Service\Archival\RecordState;
use OCA\OpenRegister\Service\Archival\RetentionRowScanner;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCP\IAppConfig;
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
	 *
	 * GAP A4. One vocabulary now, the Archiefwet lifecycle in English, shared
	 * with TmloService and with the abstract `_retention` layer. The Dutch
	 * spellings this service and TmloService each used are accepted on READ
	 * through {@see RecordState}'s alias lists, because stored data carries
	 * whatever was current when it was written and there is no migration.
	 *
	 * @var string[]
	 */
	private const VALID_STATUSES = RecordState::ALL_ALIASES;

	/**
	 * Immutable archival statuses (no further updates allowed).
	 *
	 * @var string[]
	 */
	private const IMMUTABLE_STATUSES = RecordState::IMMUTABLE_ALIASES;

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
	 * @param RetentionRowScanner $rowScanner Walks every table a retention decision can live in
	 * @param ArchiveActionDateCalculator $actionDateCalculator Calculates the archiefactiedatum
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) A DI constructor for an aggregate service.
	 *              Every parameter is a distinct collaborator this class genuinely uses, and the tenth
	 *              arrived by EXTRACTING logic out of this class rather than adding any: collapsing them
	 *              behind a parameter object would hide the dependency graph without reducing it.
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
		private readonly RetentionRowScanner $rowScanner,
		private readonly ArchiveActionDateCalculator $actionDateCalculator,
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
		$retention['archiefstatus'] = RecordState::ACTIVE;
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
	 * Delegates to {@see ArchiveActionDateCalculator}, which owns the whole
	 * question of where a retention period starts counting from and what to do
	 * when it cannot be answered. Kept here because callers and the spec both
	 * name this method.
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
		return $this->actionDateCalculator->calculate(
			object: $object,
			schema: $schema,
			retentionPeriod: $retentionPeriod
		);
	}//end calculateArchiveActionDate()

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

		// Both spellings of both states. An install that has not written a
		// record since the vocabulary landed still holds the Dutch one, and a
		// guard that stopped recognising `overgebracht` would unlock every
		// transferred record it has.
		if (in_array($status, RecordState::DESTROYED_ALIASES, true) === true) {
			return 'OBJECT_DESTROYED';
		}

		if (in_array($status, RecordState::TRANSFERRED_ALIASES, true) === true) {
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
	 * Visit every object carrying a retention decision, wherever it lives.
	 *
	 * Delegates to {@see RetentionRowScanner}, which owns the whole question of
	 * WHERE a retention decision is stored: the per-schema magic tables and the
	 * legacy blob table both, paged, with a cap per run. Kept here because both
	 * archival background jobs already ask this service for the sweep.
	 *
	 * @param callable $accept     Predicate answering whether one object is a match.
	 * @param int|null $maxMatches Cap for this run; defaults to the scanner's own.
	 *
	 * @return array{objects: ObjectEntity[], scanned: int, truncated: bool} The matches,
	 *               how many rows were inspected, and whether the cap stopped the run.
	 *
	 * @psalm-param callable(ObjectEntity): bool $accept
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 * @spec openspec/specs/retention-management/spec.md#requirement-the-system-must-generate-destruction-lists-via-a-background-job
	 */
	public function scanObjectsWithRetention(callable $accept, ?int $maxMatches = null): array {
		return $this->rowScanner->scan(accept: $accept, maxMatches: $maxMatches);
	}//end scanObjectsWithRetention()

	/**
	 * Find objects eligible for destruction.
	 *
	 * Objects whose appraisal is destroy, whose archiefactiedatum has passed,
	 * whose record state is still live, that are not immutable, carry no active
	 * legal hold, and are not already on a pending destruction list.
	 *
	 * @param array $excludeUuids UUIDs to exclude (already on pending lists)
	 *
	 * @return ObjectEntity[] Array of eligible objects
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function findEligibleForDestruction(array $excludeUuids = []): array {
		$today = (new DateTime())->format('Y-m-d');

		$scan = $this->scanObjectsWithRetention(
			accept: fn (ObjectEntity $object): bool => $this->isEligibleForDestruction(
				object: $object,
				today: $today,
				excludeUuids: $excludeUuids
			)
		);

		return $scan['objects'];
	}//end findEligibleForDestruction()

	/**
	 * Decide whether one object is eligible for destruction today.
	 *
	 * @param ObjectEntity $object       The object to judge.
	 * @param string       $today        Today, as Y-m-d.
	 * @param array        $excludeUuids UUIDs already on a pending destruction list.
	 *
	 * @return bool True when every destruction rule is satisfied.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) One rule per branch; collapsing
	 *              them would hide which rule rejected an object.
	 */
	private function isEligibleForDestruction(ObjectEntity $object, string $today, array $excludeUuids): bool {
		$retention = ($object->getRetention() ?? []);

		// Every spelling that means destroy. Matching only the English one
		// would make every pre-existing record invisible to this sweep, which
		// is the direction that keeps personal data past its lawful term.
		if (in_array(($retention['archiefnominatie'] ?? ''), Appraisal::DESTROY_ALIASES, true) === false) {
			return false;
		}

		if ($this->recordStateIsLive(retention: $retention) === false) {
			return false;
		}

		$actiedatum = ($retention['archiefactiedatum'] ?? null);
		if ($actiedatum === null || $actiedatum > $today) {
			return false;
		}

		// Skip objects in an immutable archival status (destroyed, transferred).
		//
		// ⚠️ THIS IS A BELT OVER BRACES AND NO TEST CAN REDDEN ON IT ALONE: every
		// spelling {@see validateNotImmutable()} rejects is already outside
		// ACTIVE_ALIASES, so the check above has excluded the object first. It
		// is kept, and its redundancy named rather than quietly relied on,
		// because the live-state list is the thing most likely to grow: the day
		// a new state counts as live, this line is what stops a transferred
		// record being swept while nobody is looking at this method.
		if ($this->validateNotImmutable(object: $object) !== null) {
			return false;
		}

		if ((($retention['legalHold'] ?? [])['active'] ?? false) === true) {
			return false;
		}

		return (in_array($object->getUuid(), $excludeUuids, true) === false);
	}//end isEligibleForDestruction()

	/**
	 * Is this record still live, for the purposes of a disposal sweep?
	 *
	 * 🔴 AN ABSENT RECORD STATE MEANS LIVE, AND SAYING OTHERWISE FINDS NOTHING.
	 * Nothing writes `archiefstatus` until something actually happens to a
	 * record, so the common case carries no state at all. Measured on a live
	 * instance: a dossiq case resolved through OpenRegister came back as
	 * `{"appraisal":"retain_permanently","retentionPeriod":"P10Y",`
	 * `"disposalDate":"2036-09-11T07:22:48+00:00","recordState":null,`
	 * `"basis":"record"}`, and `recordState` was null for every case measured.
	 *
	 * The previous comparison was `in_array($retention['archiefstatus'] ?? '',
	 * ACTIVE_ALIASES)`. `''` is in no alias list, so every record nothing had
	 * happened to yet was skipped. Reading the magic tables would have fixed
	 * where the sweep looks and still returned nothing for the ordinary case.
	 *
	 * Same reasoning as the Dutch aliases: being too strict here keeps personal
	 * data past its lawful term, and it does it in silence. An explicit
	 * `transferred` or `destroyed`, in any spelling, still excludes, and
	 * {@see validateNotImmutable()} still runs after this.
	 *
	 * This is the SWEEP's reading of the field only. It does not change what
	 * {@see \OCA\OpenRegister\Service\Archival\ArchivalDecisionResolver} emits:
	 * null is the honest answer there and consumers render it as a blank.
	 *
	 * @param array $retention The object's retention block.
	 *
	 * @return bool True when the record is live or has no recorded state yet.
	 */
	private function recordStateIsLive(array $retention): bool {
		$state = ($retention['archiefstatus'] ?? null);

		if ($state === null || (is_string($state) === true && trim($state) === '')) {
			return true;
		}

		// Every spelling that means live.
		return in_array($state, RecordState::ACTIVE_ALIASES, true);
	}//end recordStateIsLive()

	/**
	 * Find objects eligible for transfer to an e-Depot.
	 *
	 * Objects whose appraisal is retain-permanently, whose archiefactiedatum has
	 * passed, whose record state is not already transferred or destroyed, that
	 * carry no active legal hold, and are not already on an active transfer
	 * list.
	 *
	 * @param array $excludeUuids UUIDs to exclude (already on active transfer lists)
	 *
	 * @return ObjectEntity[] Array of eligible objects
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-transfer-list-management
	 */
	public function findEligibleForTransfer(array $excludeUuids = []): array {
		$today = (new DateTime())->format('Y-m-d');

		$scan = $this->scanObjectsWithRetention(
			accept: fn (ObjectEntity $object): bool => $this->isEligibleForTransfer(
				object: $object,
				today: $today,
				excludeUuids: $excludeUuids
			)
		);

		return $scan['objects'];
	}//end findEligibleForTransfer()

	/**
	 * Decide whether one object is eligible for e-Depot transfer today.
	 *
	 * A record already transferred has nothing left to hand over and a
	 * destroyed one no longer exists, so both are excluded by state rather than
	 * by requiring the state to be live: a semi-static record is exactly the one
	 * an archivist expects to see on a transfer list.
	 *
	 * @param ObjectEntity $object       The object to judge.
	 * @param string       $today        Today, as Y-m-d.
	 * @param array        $excludeUuids UUIDs already on an active transfer list.
	 *
	 * @return bool True when every transfer rule is satisfied.
	 */
	private function isEligibleForTransfer(ObjectEntity $object, string $today, array $excludeUuids): bool {
		$retention = ($object->getRetention() ?? []);

		// Every spelling that means keep forever: `bewaren` from a ZGW
		// resultaattype, `blijvend_bewaren` from MDTO and the selectielijst.
		if (in_array(($retention['archiefnominatie'] ?? ''), Appraisal::RETAIN_PERMANENTLY_ALIASES, true) === false) {
			return false;
		}

		// Only an EXPLICIT transferred or destroyed state excludes. An absent,
		// null or empty state means nothing has happened to this record yet,
		// which is exactly the record an archivist expects to see on a transfer
		// list; see {@see recordStateIsLive()} for the measurement behind that.
		if (in_array(($retention['archiefstatus'] ?? ''), self::IMMUTABLE_STATUSES, true) === true) {
			return false;
		}

		$actiedatum = ($retention['archiefactiedatum'] ?? null);
		if ($actiedatum === null || $actiedatum > $today) {
			return false;
		}

		if ((($retention['legalHold'] ?? [])['active'] ?? false) === true) {
			return false;
		}

		return (in_array($object->getUuid(), $excludeUuids, true) === false);
	}//end isEligibleForTransfer()

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

			// `status`, NOT `object->status`. MagicSearchHandler compares the
			// filter key against the schema's OWN property names, and anything
			// it does not recognise becomes `1 = 0` rather than an error, so
			// this query returned an empty list on every run. The exclusion it
			// feeds is "objects already on a pending destruction list", which
			// means every sweep re-listed objects that were already awaiting
			// approval, and nothing said so.
			$pendingLists = $this->objectMapper->findAll(
				filters: [
					'status' => ['in_review', 'approved', 'awaiting_second_approval'],
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
				// 🔴 NOT `getTitle()`. `ObjectEntity` HAS NO `title` PROPERTY,
				// so Entity's magic accessor threw "title is not a valid
				// attribute" here, uncaught, and took the whole
				// DestructionCheckJob run down with it. Every sweep that got
				// this far therefore failed at list creation, which is a second
				// reason no destruction list was ever produced. `name` is the
				// property that exists.
				'title' => $object->getName() ?? $object->getUuid(),
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
