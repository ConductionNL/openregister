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
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Db\MagicMapper\MagicListRequest;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Service\Archival\HoldStamp;
use OCA\OpenRegister\Service\Archival\LegalHoldLedger;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Archival\Appraisal;
use OCA\OpenRegister\Service\Archival\ActionDateFollower;
use OCA\OpenRegister\Service\Archival\ArchiveActionDateCalculator;
use OCA\OpenRegister\Service\Archival\CategoryDeclaration;
use OCA\OpenRegister\Service\Archival\ClassificationOverride;
use OCA\OpenRegister\Service\Archival\RecordState;
use OCA\OpenRegister\Service\Archival\RetentionRowScanner;
use OCA\OpenRegister\Service\Archival\SelectielijstResolver;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\Archival\HoldAction;

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
class RetentionService implements RetentionServiceInterface {

	/**
	 * Why a destruction rule refused an object (destructionRefusalIn()).
	 */
	public const REFUSAL_NOT_NOMINATED = 'not_nominated_for_destruction';
	public const REFUSAL_NOT_LIVE = 'record_not_live';
	public const REFUSAL_NOT_DUE = 'action_date_not_reached';
	public const REFUSAL_FROZEN = 'record_frozen';
	public const REFUSAL_LEGAL_HOLD = 'legal_hold';
	public const REFUSAL_ALREADY_LISTED = 'already_on_a_list';

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
	 * @param RetentionStores $stores The object, schema, register and audit trail mappers.
	 * @param RetentionRules $rules Archival settings, row scanner, action date calculator and selectielijst resolver.
	 * @param IUserSession $userSession Current user session
	 * @param LoggerInterface $logger Logger
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) A DI constructor for an aggregate service.
	 *              Every parameter is a distinct collaborator this class genuinely uses, and the tenth
	 *              arrived by EXTRACTING logic out of this class rather than adding any: collapsing them
	 *              behind a parameter object would hide the dependency graph without reducing it.
	 */
	public function __construct(
		private readonly RetentionStores $stores,
		private readonly RetentionRules $rules,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
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

		// The schema's category, unless this record names its own through the
		// property the schema declares for that (DECISIONS row 48).
		$classification = (new ClassificationOverride())->effective(
			declaration: new CategoryDeclaration(archive: $archiveConfig),
			data: ($object->getObject() ?? [])
		);

		$applied = $this->resolveArchivalDefaults(archiveConfig: $archiveConfig, classification: $classification);
		$retentionPeriod = $applied['bewaartermijn'];

		$retention = $this->withArchivalDefaults(retention: $retention, applied: $applied);

		// Calculate archiefactiedatum if bewaartermijn is set.
		if ($retentionPeriod !== null) {
			$retention['archiefactiedatum'] = $this->calculateArchiveActionDate(
				clock: new RetentionClock(object: $object, schema: $schema, retentionPeriod: $retentionPeriod)
			);
		}

		$object->setRetention($retention);

		return $object;
	}//end applyArchivalMetadata()

	/**
	 * Write the decided nominatie, period, source and provenance into a retention block.
	 *
	 * @param array $retention The record's retention block
	 * @param array $applied   The decision from resolveArchivalDefaults(), naming the effective category
	 *
	 * @return array The retention block with the archival metadata set
	 */
	private function withArchivalDefaults(array $retention, array $applied): array {
		// Build archival metadata.
		$retention['archiefnominatie'] = $applied['archiefnominatie'];
		$retention['archiefstatus'] = RecordState::ACTIVE;
		$retention['classification'] = $applied['classification'];
		$retention['bewaartermijn'] = $applied['bewaartermijn'];
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

		return $retention;
	}//end withArchivalDefaults()

	/**
	 * Decide the nominatie, the retention period and where they came from.
	 *
	 * Three sources in precedence order: the selectielijst entry the schema's
	 * classification points at, then the schema's own defaults, then the
	 * schema's explicit `bewaartermijnOverride`, which wins over both because
	 * it is a deliberate local decision rather than a fallback.
	 *
	 * @param array       $archiveConfig  The schema's archive block
	 * @param string|null $classification The record's effective category
	 *
	 * @return array{archiefnominatie: string, bewaartermijn: string|null, selectielijstBron: string|null, provenance: array<string, string>, classification: string|null}
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	private function resolveArchivalDefaults(array $archiveConfig, ?string $classification): array {
		$applied = [
			'archiefnominatie' => ($archiveConfig['defaultNominatie'] ?? 'nog_niet_bepaald'),
			'bewaartermijn' => ($archiveConfig['defaultBewaartermijn'] ?? null),
			'selectielijstBron' => null,
			'provenance' => [],
			'classification' => $classification,
		];

		$entry = null;
		if ($classification !== null) {
			$entry = $this->rules->listResolver->entryFor(category: $classification);
		}

		if ($entry !== null) {
			$data = $entry->getObject();
			$applied['archiefnominatie'] = ($data['archiefnominatie'] ?? 'nog_niet_bepaald');
			$applied['bewaartermijn'] = ($data['bewaartermijn'] ?? null);
			$applied['selectielijstBron'] = ($data['bron'] ?? null);
			$applied['provenance'] = $this->rules->listResolver->provenanceOf(entry: $entry);
		}

		if (empty($archiveConfig['bewaartermijnOverride']) === false) {
			$applied['bewaartermijn'] = $archiveConfig['bewaartermijnOverride'];
		}

		return $applied;
	}//end resolveArchivalDefaults()

	/**
	 * Refuse a record whose category override names no selectielijst row.
	 *
	 * @param Schema               $schema The record's schema
	 * @param array<string, mixed> $data   The record's data as it will be stored
	 *
	 * @return void
	 *
	 * @throws ValidationException When the override is not text or matches no selectielijst row
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function guardClassificationOverride(Schema $schema, array $data): void {
		$override = new ClassificationOverride();
		$override->guard(
			property: $override->propertyOf(archive: ($schema->getArchive() ?? []), configuration: ($schema->getConfiguration() ?? [])),
			data: $data,
			resolver: $this->rules->listResolver
		);
	}//end guardClassificationOverride()

	/**
	 * Follow a changed category override on one update of a record.
	 *
	 * A record that is still active is re-derived from its new category. A
	 * record already nominated at its terminal state keeps that decision:
	 * changing its category is refused, because re-nominating it is the
	 * explicit recompute's job, which records who asked and why.
	 *
	 * Only a change of the override itself counts: an edit that leaves it
	 * alone never re-derives or refuses, even when the schema's own category
	 * has moved since the record was created.
	 *
	 * @param RecordUpdate $update The record, its schema and its data before the update
	 *
	 * @return ObjectEntity The record, re-derived when its category changed
	 *
	 * @throws ObjectStateWriteException When a nominated record's category changes
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function applyClassification(RecordUpdate $update): ObjectEntity {
		$object   = $update->object;
		$archive  = ($update->schema->getArchive() ?? []);
		$override = new ClassificationOverride();
		$property = $override->propertyOf(archive: $archive, configuration: ($update->schema->getConfiguration() ?? []));
		if (($archive['enabled'] ?? false) !== true || $property === null) {
			return $object;
		}

		$data = ($object->getObject() ?? []);
		if ($override->requested(property: $property, data: $data) === $override->requested(property: $property, data: $update->previousData)) {
			return $object;
		}

		$this->guardClassificationOverride(schema: $update->schema, data: $data);

		$declaration = new CategoryDeclaration(archive: $archive, configuration: ($update->schema->getConfiguration() ?? []));
		return $this->rederiveCategory(update: $update, effective: $override->effective(declaration: $declaration, data: $data));
	}//end applyClassification()

	/**
	 * Re-derive a record's archival metadata from its new effective category.
	 *
	 * @param RecordUpdate $update    The record, its schema and its data before the update
	 * @param string|null  $effective The record's new effective category
	 *
	 * @return ObjectEntity The record, re-derived when its category changed
	 *
	 * @throws ObjectStateWriteException When a nominated record's category changes
	 */
	private function rederiveCategory(RecordUpdate $update, ?string $effective): ObjectEntity {
		$retention = ($update->object->getRetention() ?? []);
		if ($effective === ($retention['classification'] ?? null)) {
			return $update->object;
		}

		$this->assertCategoryMayChange(retention: $retention, effective: $effective);

		unset($retention['archiefnominatie'], $retention['archiefactiedatum'], $retention['selectielijstBron']);
		$update->object->setRetention($retention);

		return $this->applyArchivalMetadata(object: $update->object, schema: $update->schema);
	}//end rederiveCategory()

	/**
	 * Refuse a category change on a record already nominated at its terminal state.
	 *
	 * @param array       $retention The record's retention block
	 * @param string|null $effective The record's new effective category
	 *
	 * @return void
	 *
	 * @throws ObjectStateWriteException When the record is not live or already carries a nomination
	 */
	private function assertCategoryMayChange(array $retention, ?string $effective): void {
		$live = in_array(($retention['archiefstatus'] ?? RecordState::ACTIVE), RecordState::ACTIVE_ALIASES, true);
		if ($live === false || isset($retention['nomination']) === true) {
			throw new ObjectStateWriteException(
				message: 'Cannot change the selectielijst category to "' . (string)$effective
					. '": this record was already nominated under "' . (string)($retention['classification'] ?? '')
					. '". Recompute its nomination instead.'
			);
		}
	}//end assertCategoryMayChange()

	/**
	 * Calculate archiefactiedatum based on the schema's afleidingswijze.
	 *
	 * Delegates to {@see ArchiveActionDateCalculator}, which owns the whole
	 * question of where a retention period starts counting from and what to do
	 * when it cannot be answered. Kept here because callers and the spec both
	 * name this method.
	 *
	 * @param RetentionClock $clock The object, its schema (with the afleidingswijze config) and the ISO 8601 retention period
	 *
	 * @return string|null ISO 8601 date string or null if calculation not possible
	 *
	 * @spec openspec/specs/retention-management/spec.md#requirement-the-system-must-calculate-archiefactiedatum-using-configurable-afleidingswijzen
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function calculateArchiveActionDate(RetentionClock $clock): ?string {
		return $this->rules->actionDateCalculator->calculate(
			object: $clock->object,
			schema: $clock->schema,
			retentionPeriod: $clock->retentionPeriod
		);
	}//end calculateArchiveActionDate()

	/**
	 * Recalculate archiefactiedatum when one update changed the source property.
	 *
	 * The source is the property the schema's derivation method reads: the
	 * `sourceDateProperty` under `ander_datumkenmerk`, the reference under the
	 * relation methods, `bronEigenschap` under `eigenschap` and the closure
	 * field under `afgehandeld` and `termijn`.
	 *
	 * @param RecordUpdate $update The record, its schema and its data before the update
	 *
	 * @return ObjectEntity The object with recalculated dates
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-the-destruction-date-follows-its-source-date-under-every-method
	 */
	public function recalculateActionDate(RecordUpdate $update): ObjectEntity {
		$object = $update->object;
		$archiveConfig = $update->schema->getArchive();
		if (empty($archiveConfig) === true || ($archiveConfig['enabled'] ?? false) === false) {
			return $object;
		}

		$property = $this->rules->actionDateCalculator->sourcePropertyOf(archiveConfig: $archiveConfig);
		if ($property === null) {
			return $object;
		}

		if (($update->previousData[$property] ?? null) !== (($object->getObject() ?? [])[$property] ?? null)) {
			$this->followSourceDate(object: $object, schema: $update->schema);
		}

		return $object;
	}//end recalculateActionDate()

	/**
	 * Recalculate a record's archiefactiedatum from its current source date.
	 *
	 * Used for a record whose own data did not change but whose source date
	 * did, such as a contact person whose client's end date moved.
	 *
	 * @param ObjectEntity $object The record
	 * @param Schema       $schema Its schema
	 *
	 * @return bool True when the archiefactiedatum changed
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-the-destruction-date-follows-its-source-date-under-every-method
	 */
	public function followSourceDate(ObjectEntity $object, Schema $schema): bool {
		$archiveConfig = $schema->getArchive();
		if (empty($archiveConfig) === true || ($archiveConfig['enabled'] ?? false) === false) {
			return false;
		}

		$retention = ($object->getRetention() ?? []);

		// A record without a nomination gets its nomination and date together
		// once its schema declares a default nomination to give it.
		if (empty($retention['archiefnominatie']) === true) {
			if (empty($archiveConfig['defaultNominatie']) === true) {
				return false;
			}

			$this->applyArchivalMetadata(object: $object, schema: $schema);

			return (($object->getRetention() ?? [])['archiefactiedatum'] ?? null) !== ($retention['archiefactiedatum'] ?? null);
		}

		$follower = new ActionDateFollower(calculator: $this->rules->actionDateCalculator, auditMapper: $this->stores->auditMapper, logger: $this->logger);

		return $follower->follow(object: $object, schema: $schema);
	}//end followSourceDate()

	/**
	 * Look up a selectielijst entry by categorie code.
	 *
	 * Delegates to {@see \OCA\OpenRegister\Service\Archival\SelectielijstResolver},
	 * which owns the whole question of WHICH revision of a category applies now
	 * that a list is imported with a version. Kept here because callers and the
	 * spec both name this method, the same arrangement
	 * {@see calculateArchiveActionDate()} has with ArchiveActionDateCalculator.
	 *
	 * @param string $category The selectielijst category code (e.g., B1, A1)
	 *
	 * @return array|null The selectielijst entry data or null if not found
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function lookupSelectielijstEntry(string $category): ?array {
		return $this->rules->listResolver->lookupSelectielijstEntry(category: $category);
	}//end lookupSelectielijstEntry()

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
	 * Place a legal hold on an object, as ordered.
	 *
	 * @param ObjectEntity $object The object to place the hold on
	 * @param HoldOrder    $order  Why, and for which matter
	 *
	 * @return ObjectEntity The object with legal hold applied
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function placeHold(ObjectEntity $object, HoldOrder $order): ObjectEntity {
		// One hold per matter, the same ledger LegalHoldService writes (#4172).
		$object->setRetention(
			(new LegalHoldLedger())->placeAction(
				retention: ($object->getRetention() ?? []),
				action: new HoldAction(ownerKey: $order->ownerKey, reason: $order->reason, stamp: $this->holdStamp())
			)
		);

		return $object;
	}//end placeHold()

	/**
	 * Release a legal hold on an object, as ordered.
	 *
	 * @param ObjectEntity $object The object to release the hold from
	 * @param HoldOrder    $order  Why, and for which matter; no matter releases every hold
	 *
	 * @return ObjectEntity The object with legal hold released
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function releaseHold(ObjectEntity $object, HoldOrder $order): ObjectEntity {
		$retention = $object->getRetention() ?? [];
		$ledger = new LegalHoldLedger();
		if ($ledger->activeHolds(retention: $retention) === []) {
			return $object;
		}

		$object->setRetention(
			$ledger->releaseAction(
				retention: $retention,
				action: new HoldAction(ownerKey: $order->ownerKey, reason: $order->reason, stamp: $this->holdStamp())
			)
		);

		return $object;
	}//end releaseHold()

	/**
	 * Who places or releases a hold, and when: the current user, or 'system'.
	 *
	 * @return HoldStamp The stamp
	 */
	private function holdStamp(): HoldStamp {
		return new HoldStamp(userId: $this->currentUserIdOrSystem(), now: (new DateTime())->format('c'));
	}//end holdStamp()

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
			$settings = $this->rules->settingsHandler->getArchivalSettingsOnly();
			$extensionPeriod = $settings['defaultExtensionPeriod'] ?? 'P1Y';
		}

		try {
			$retention = $this->withExtendedActionDate(retention: $retention, extensionPeriod: $extensionPeriod);
			$object->setRetention($retention);
		} catch (Exception $e) {
			$this->logger->warning(
				'[RetentionService] Failed to extend archiefactiedatum: ' . $e->getMessage()
			);
		}

		return $object;
	}//end extendArchiveActionDate()

	/**
	 * Move the archiefactiedatum on by a period, remembering the original once.
	 *
	 * @param array $retention       The record's retention block, with an archiefactiedatum
	 * @param mixed $extensionPeriod ISO 8601 duration
	 *
	 * @return array The retention block with the extended date
	 *
	 * @throws Exception When the date or the period cannot be parsed
	 */
	private function withExtendedActionDate(array $retention, mixed $extensionPeriod): array {
		$date = new DateTime($retention['archiefactiedatum']);
		$date->add(new DateInterval($extensionPeriod));
		$retention['archiefactiedatum'] = $date->format('Y-m-d');

		// Store original date if not already stored.
		if (empty($retention['originalArchiefactiedatum']) === true) {
			$retention['originalArchiefactiedatum'] = $retention['archiefactiedatum'];
		}

		return $retention;
	}//end withExtendedActionDate()

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
		return $this->rules->rowScanner->scan(accept: $accept, maxMatches: $maxMatches);
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
		$sweep = new DisposalSweep(today: (new DateTime())->format('Y-m-d'), excludeUuids: $excludeUuids);

		$scan = $this->scanObjectsWithRetention(
			accept: fn (ObjectEntity $object): bool => $this->isEligibleForDestruction(object: $object, sweep: $sweep)
		);

		return $scan['objects'];
	}//end findEligibleForDestruction()

	/**
	 * Decide whether one object is eligible for destruction today.
	 *
	 * @param ObjectEntity  $object The object to judge.
	 * @param DisposalSweep $sweep  Today, and the UUIDs already on a pending destruction list.
	 *
	 * @return bool True when every destruction rule is satisfied.
	 */
	private function isEligibleForDestruction(ObjectEntity $object, DisposalSweep $sweep): bool {
		return $this->destructionRefusalIn(object: $object, sweep: $sweep) === null;
	}//end isEligibleForDestruction()

	/**
	 * The first destruction rule that refuses one object in a sweep, or null when none does.
	 *
	 * The one rule, read by the daily sweep (through isEligibleForDestruction())
	 * and by an app that asks for a list of its own (DestructionListCreator),
	 * so the two cannot drift apart.
	 *
	 * @param ObjectEntity  $object The object to judge.
	 * @param DisposalSweep $sweep  Today, and the UUIDs already on a pending destruction list.
	 *
	 * @return string|null One of the REFUSAL_* reasons, or null when the object may be destroyed.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function destructionRefusalIn(ObjectEntity $object, DisposalSweep $sweep): ?string {
		// The retention rules first, then the rules about this object, in that order.
		return ($this->retentionDestructionRefusal(retention: ($object->getRetention() ?? []), today: $sweep->today)
			?? $this->objectDestructionRefusal(object: $object, excludeUuids: $sweep->excludeUuids));
	}//end destructionRefusalIn()

	/**
	 * The first rule on the retention decision itself that refuses destruction.
	 *
	 * @param array  $retention The object's retention block.
	 * @param string $today     Today, as Y-m-d.
	 *
	 * @return string|null REFUSAL_NOT_NOMINATED, REFUSAL_NOT_LIVE, REFUSAL_NOT_DUE, or null.
	 */
	private function retentionDestructionRefusal(array $retention, string $today): ?string {
		// Every spelling that means destroy. Matching only the English one
		// would make every pre-existing record invisible to this sweep, which
		// is the direction that keeps personal data past its lawful term.
		if (in_array(($retention['archiefnominatie'] ?? ''), Appraisal::DESTROY_ALIASES, true) === false) {
			return self::REFUSAL_NOT_NOMINATED;
		}

		if ($this->recordStateIsLive(retention: $retention) === false) {
			return self::REFUSAL_NOT_LIVE;
		}

		if ($this->actionDateHasPassed(retention: $retention, today: $today) === false) {
			return self::REFUSAL_NOT_DUE;
		}

		return null;
	}//end retentionDestructionRefusal()

	/**
	 * The first rule on the object's state that refuses destruction.
	 *
	 * @param ObjectEntity $object       The object to judge.
	 * @param array        $excludeUuids UUIDs already on a pending destruction list.
	 *
	 * @return string|null REFUSAL_FROZEN, REFUSAL_LEGAL_HOLD, REFUSAL_ALREADY_LISTED, or null.
	 */
	private function objectDestructionRefusal(ObjectEntity $object, array $excludeUuids): ?string {
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
			return self::REFUSAL_FROZEN;
		}

		if (((($object->getRetention() ?? [])['legalHold'] ?? [])['active'] ?? false) === true) {
			return self::REFUSAL_LEGAL_HOLD;
		}

		if (in_array($object->getUuid(), $excludeUuids, true) === true) {
			return self::REFUSAL_ALREADY_LISTED;
		}

		return null;
	}//end objectDestructionRefusal()

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
		$sweep = new DisposalSweep(today: (new DateTime())->format('Y-m-d'), excludeUuids: $excludeUuids);

		$scan = $this->scanObjectsWithRetention(
			accept: fn (ObjectEntity $object): bool => $this->isEligibleForTransfer(object: $object, sweep: $sweep)
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
	 * @param ObjectEntity  $object The object to judge.
	 * @param DisposalSweep $sweep  Today, and the UUIDs already on an active transfer list.
	 *
	 * @return bool True when every transfer rule is satisfied.
	 */
	private function isEligibleForTransfer(ObjectEntity $object, DisposalSweep $sweep): bool {
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

		if ($this->isDueAndNotHeld(retention: $retention, today: $sweep->today) === false) {
			return false;
		}

		return (in_array($object->getUuid(), $sweep->excludeUuids, true) === false);
	}//end isEligibleForTransfer()

	/**
	 * Whether the action date has passed and no legal hold is active.
	 *
	 * @param array  $retention The object's retention block.
	 * @param string $today     Today, as Y-m-d.
	 *
	 * @return bool True when due and not held.
	 */
	private function isDueAndNotHeld(array $retention, string $today): bool {
		if ($this->actionDateHasPassed(retention: $retention, today: $today) === false) {
			return false;
		}

		return ((($retention['legalHold'] ?? [])['active'] ?? false) === true) === false;
	}//end isDueAndNotHeld()

	/**
	 * Whether the archiefactiedatum is set and not after today.
	 *
	 * @param array  $retention The object's retention block.
	 * @param string $today     Today, as Y-m-d.
	 *
	 * @return bool True when the action date has been reached.
	 */
	private function actionDateHasPassed(array $retention, string $today): bool {
		$actiedatum = ($retention['archiefactiedatum'] ?? null);
		return ($actiedatum === null || $actiedatum > $today) === false;
	}//end actionDateHasPassed()

	/**
	 * Get UUIDs of objects already on pending destruction lists.
	 *
	 * @return string[] Array of object UUIDs
	 *
	 * @spec openspec/specs/retention-management/spec.md#requirement-the-system-must-generate-destruction-lists-via-a-background-job
	 */
	public function getObjectsOnPendingDestructionLists(): array {
		$settings = $this->rules->settingsHandler->getArchivalSettingsOnly();

		$registerId = $settings['destructionListRegister'] ?? null;
		$schemaId = $settings['destructionListSchema'] ?? null;

		if ($registerId === null || $schemaId === null) {
			return [];
		}

		try {
			$register = $this->stores->registerMapper->find((int)$registerId);
			$schema = $this->stores->schemaMapper->find((int)$schemaId);

			// `status`, NOT `object->status`. MagicSearchHandler compares the
			// filter key against the schema's OWN property names, and anything
			// it does not recognise becomes `1 = 0` rather than an error, so
			// this query returned an empty list on every run. The exclusion it
			// feeds is "objects already on a pending destruction list", which
			// means every sweep re-listed objects that were already awaiting
			// approval, and nothing said so.
			$pendingLists = $this->stores->objectMapper->findAllListed(
				request: new MagicListRequest(
					table: new RegisterSchema(register: $register, schema: $schema),
					query: ListQuery::unbounded(filters: ['status' => ['in_review', 'approved', 'awaiting_second_approval']])
				)
			);

			return array_unique($this->listedObjectUuids(lists: $pendingLists));
		} catch (Exception $e) {
			$this->logger->warning(
				'[RetentionService] Failed to get pending destruction list objects: ' . $e->getMessage()
			);
			return [];
		}//end try
	}//end getObjectsOnPendingDestructionLists()

	/**
	 * The UUIDs of every object entry on the given destruction lists, in list order.
	 *
	 * @param ObjectEntity[] $lists The destruction lists
	 *
	 * @return string[] The UUIDs, duplicates included
	 */
	private function listedObjectUuids(array $lists): array {
		$uuids = [];
		foreach ($lists as $list) {
			$listData = $list->getObject();
			$objects = $listData['objects'] ?? [];
			foreach ($objects as $obj) {
				$uuid = $obj['uuid'] ?? null;
				if ($uuid !== null) {
					$uuids[] = $uuid;
				}
			}
		}

		return $uuids;
	}//end listedObjectUuids()

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
		$settings = $this->rules->settingsHandler->getArchivalSettingsOnly();

		$registerId = $settings['destructionListRegister'] ?? null;
		$schemaId = $settings['destructionListSchema'] ?? null;

		if ($registerId === null || $schemaId === null) {
			$this->logger->warning(
				'[RetentionService] Cannot create destruction list: register/schema not configured'
			);
			return null;
		}

		$userId = $this->currentUserIdOrSystem();

		$objectEntries = [];
		foreach ($objects as $object) {
			$objectEntries[] = $this->destructionListEntry(object: $object);
		}

		return [
			'status' => 'in_review',
			'createdBy' => $userId,
			'createdAt' => (new DateTime())->format('c'),
			'objects' => $objectEntries,
			'excluded' => [],
			'approvals' => [],
			// The one decision history: destroy, retain and transfer all
			// land here, so the record an auditor reads is one artefact.
			'decisions' => [],
		];
	}//end createDestructionList()

	/**
	 * The current user's ID, or 'system' when no user is logged in.
	 *
	 * @return string The user ID
	 */
	private function currentUserIdOrSystem(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return 'system';
		}

		return $user->getUID();
	}//end currentUserIdOrSystem()

	/**
	 * One object's entry on a new destruction list.
	 *
	 * @param ObjectEntity $object The object to list
	 *
	 * @return array The entry
	 */
	private function destructionListEntry(ObjectEntity $object): array {
		$retention = $object->getRetention() ?? [];
		// Detect WOO-published status from object data or metadata.
		$objectData = $object->getObject() ?? [];
		$isWooPublished = ($objectData['woo_gepubliceerd'] ?? false) === true
			|| ($objectData['publicatiestatus'] ?? null) === 'gepubliceerd'
			|| ($retention['wooPublished'] ?? false) === true;

		return [
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
			// Unassigned, said out loud, so a consumer reading a fresh
			// list can see that nobody is accountable for this entry yet.
			'reviewer' => null,
		];
	}//end destructionListEntry()

	/**
	 * The destruction certificate for a destruction that was carried out.
	 *
	 * @param ExecutedDestruction $execution The list, how many were destroyed, and when
	 *
	 * @return array The destruction certificate data
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function certify(ExecutedDestruction $execution): array {
		$destructionList = $execution->destructionList;

		return [
			'type' => 'verklaring_van_vernietiging',
			'destructionDate' => $execution->executedAt,
			'approvedBy' => array_column($destructionList['approvals'] ?? [], 'userId'),
			'destructionListUuid' => $destructionList['uuid'] ?? null,
			'totalDestroyed' => $execution->destroyedCount,
			'groupedBySchema' => $this->groupDestroyedBySchema(destructionList: $destructionList),
			'selectielijstBron' => $this->extractSelectionListSource(destructionList: $destructionList),
			'complianceStatement' => 'Vernietiging conform Archiefwet 1995 en Archiefbesluit 1995',
			'immutable' => true,
		];
	}//end certify()

	/**
	 * The destroyed objects counted per schema and classificatie.
	 *
	 * @param array $destructionList The destruction list data
	 *
	 * @return array<int, array{schema: mixed, classification: mixed, count: int}> One group per schema and classificatie
	 */
	private function groupDestroyedBySchema(array $destructionList): array {
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

		return array_values($grouped);
	}//end groupDestroyedBySchema()

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
