<?php

/**
 * AVG / GDPR per-access processing-log service (verwerkingenlogging).
 *
 * Records WHO read or exported WHICH personal-data object WHEN, for
 * schemas that opt in via the `x-openregister-processing` annotation
 * (`logReads: true`). This closes the read-access accountability gap
 * the hash-chained audit trail never covered (it only records writes);
 * AVG Art 5(2) / Art 30 and the VNG Logging Verwerkingen standard
 * require a record of every processing, including raadplegen.
 *
 * Design contract (design.md D3–D6):
 *   - Opt-in per schema: schemas without `logReads: true` are NEVER
 *     touched — read volume on non-person-bearing schemas is pure cost
 *     with no AVG meaning.
 *   - Fail-soft: every public method swallows its own errors. A logging
 *     failure MUST NOT break or materially slow the primary read; the
 *     object load always completes.
 *   - Buffered emission: entries accumulate in an in-request buffer and
 *     are flushed as one batched write (`flush()`), keeping the hot read
 *     path free of per-row synchronous inserts.
 *   - Flagged fallback: when attribution does not resolve, the entry is
 *     attributed to the seeded `niet-geclassificeerde-verwerking`
 *     activity rather than dropped — nothing on an opted-in schema is
 *     silently unlogged.
 *
 * Writes are NOT logged here; they remain on the audit trail (which
 * already stamps `processingActivityId`). The per-subject inzage extract
 * joins both records.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ProcessingLogEntry;
use OCA\OpenRegister\Db\ProcessingLogMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapperInterface;
use OCA\OpenRegister\Service\Gdpr\ProcessingAnnotationReader;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Buffered, fail-soft processing-log writer for object reads/exports.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */
class ProcessingLogService {

	/**
	 * Annotation key carrying the new declarative processing dialect.
	 *
	 * @var string
	 */
	public const ANNOTATION_KEY = ProcessingAnnotationReader::ANNOTATION_KEY;

	/**
	 * Legacy single-string attribution annotation (shorthand).
	 *
	 * @var string
	 */
	public const LEGACY_ANNOTATION_KEY = ProcessingAnnotationReader::LEGACY_ANNOTATION_KEY;

	/**
	 * Code of the per-organisation seeded flagged fallback activity.
	 *
	 * @var string
	 */
	public const FALLBACK_CODE = 'niet-geclassificeerde-verwerking';

	/**
	 * The largest list result whose entry carries per-object identifiers.
	 *
	 * @var int
	 */
	public const LIST_IDENTIFIER_LIMIT = 100;

	/**
	 * In-request buffer of pending entries (flushed after the response).
	 *
	 * @var array<int, ProcessingLogEntry>
	 */
	private array $buffer = [];

	/**
	 * Constructor.
	 *
	 * @param ProcessingLogMapperInterface $logMapper Append-only log mapper.
	 * @param VerwerkingsactiviteitMapperInterface $vrwMapper Activity resolver + fallback seed.
	 * @param SchemaCatalogInterface $catalog Schema and register annotation readers (register inheritance).
	 * @param IUserSession $userSession Actor identity.
	 * @param LoggerInterface $logger Fail-soft diagnostics.
	 */
	public function __construct(
		private readonly ProcessingLogMapperInterface $logMapper,
		private readonly VerwerkingsactiviteitMapperInterface $vrwMapper,
		private readonly SchemaCatalogInterface $catalog,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Record a single object read/export if its schema opts in.
	 *
	 * Fail-soft: never throws. Buffers the entry; nothing is written to
	 * the database until `flush()` runs.
	 *
	 * @param ObjectEntity   $object The object being processed.
	 * @param ProcessingRead $read   The read: action, channel and actor.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	public function recordRead(ObjectEntity $object, ProcessingRead $read): void {
		try {
			$this->captureRead(object: $object, read: $read);
		} catch (\Throwable $e) {
			// Fail-soft: logging never breaks the read.
			$this->logger->debug(message: '[AVG] processing-log capture skipped', context: ['exception' => $e->getMessage()]);
		}
	}//end recordRead()

	/**
	 * Buffer the entry for one object read when its schema or register opted in.
	 *
	 * @param ObjectEntity   $object The object read.
	 * @param ProcessingRead $read   The read.
	 *
	 * @return void
	 */
	private function captureRead(ObjectEntity $object, ProcessingRead $read): void {
		$run = $this->startRun(read: $read, objects: [$object]);
		if ($run === null) {
			return;
		}

		$entry   = $this->newEntry(run: $run);
		$subject = $this->extractSubject(object: $object, config: $run->config);
		$entry->setObjectUuid((string)$object->getUuid());
		$entry->setSubjectIdType($subject['type']);
		$entry->setSubjectIdValue($subject['value']);
		$entry->setObjectCount(1);
		$entry->setConfidential(false);
		$entry->setOrganisationId((string)$object->getOrganisation());

		$this->buffer[] = $entry;
	}//end captureRead()

	/**
	 * The capture of a read when its first object's schema or register logs reads; null otherwise.
	 *
	 * @param ProcessingRead           $read    The read.
	 * @param array<int|string, mixed> $objects The objects read.
	 *
	 * @return ReadCaptureRun|null
	 */
	private function startRun(ProcessingRead $read, array $objects): ?ReadCaptureRun {
		$anchor = $this->listAnchor(objects: $objects);
		if ($anchor === null) {
			return null;
		}

		$config = $this->readLoggingConfig(anchor: $anchor);
		if ($config === null) {
			return null;
		}

		return new ReadCaptureRun(read: $read, anchor: $anchor, objects: $objects, config: $config);
	}//end startRun()

	/**
	 * The processing configuration of an object's schema/register, when it logs reads; null otherwise.
	 *
	 * @param ObjectEntity $anchor The object whose schema and register decide.
	 *
	 * @return array<string, mixed>|null
	 */
	private function readLoggingConfig(ObjectEntity $anchor): ?array {
		$config = $this->resolveProcessingConfig(schemaId: $anchor->getSchema(), registerId: $anchor->getRegister());
		if ($config === null || ($config['logReads'] ?? false) !== true) {
			return null;
		}

		return $config;
	}//end readLoggingConfig()

	/**
	 * A new entry with the attribution, action, actor, channel, register and schema set, in that order.
	 *
	 * @param ReadCaptureRun $run The capture.
	 *
	 * @return ProcessingLogEntry
	 */
	private function newEntry(ReadCaptureRun $run): ProcessingLogEntry {
		$activityUuid = $this->resolveAttribution(run: $run);

		$entry = new ProcessingLogEntry();
		$entry->setActivityId($activityUuid);
		$entry->setAction($run->read->action);
		$entry->setActor($run->read->actor ?? $this->currentActor());
		$entry->setChannel($run->read->channel ?? $this->detectChannel());
		$entry->setRegisterId((string)$run->anchor->getRegister());
		$entry->setSchemaId((string)$run->anchor->getSchema());

		return $entry;
	}//end newEntry()

	/**
	 * Record a single collapsed entry for a list/search result.
	 *
	 * Fail-soft: never throws.
	 *
	 * @param array<int, ObjectEntity> $objects The list result.
	 * @param ProcessingRead           $read    The read: action, channel, actor and query.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	public function recordReadList(array $objects, ProcessingRead $read): void {
		try {
			$this->captureReadList(objects: $objects, read: $read);
		} catch (\Throwable $e) {
			$this->logger->debug(message: '[AVG] processing-log list capture skipped', context: ['exception' => $e->getMessage()]);
		}
	}//end recordReadList()

	/**
	 * Buffer one entry for a list read when the first object's schema or register opted in.
	 *
	 * @param array<int|string, mixed> $objects The objects read.
	 * @param ProcessingRead           $read    The read.
	 *
	 * @return void
	 */
	private function captureReadList(array $objects, ProcessingRead $read): void {
		$run = $this->startRun(read: $read, objects: $objects);
		if ($run === null) {
			return;
		}

		$entry = $this->newEntry(run: $run);
		$this->completeListEntry(entry: $entry, run: $run);

		$this->buffer[] = $entry;
	}//end captureReadList()

	/**
	 * The object a list read is anchored on: the first one, when it is an entity.
	 *
	 * @param array<int|string, mixed> $objects The objects read.
	 *
	 * @return ObjectEntity|null
	 */
	private function listAnchor(array $objects): ?ObjectEntity {
		if ($objects === []) {
			return null;
		}

		// Anchor on the first object to resolve schema/register opt-in.
		$first = $objects[array_key_first($objects)];
		if (($first instanceof ObjectEntity) === false) {
			return null;
		}

		return $first;
	}//end listAnchor()

	/**
	 * Set the list fields: count, confidentiality, organisation, query and (up to the cap) identifiers.
	 *
	 * @param ProcessingLogEntry $entry The entry being built.
	 * @param ReadCaptureRun     $run   The capture: anchor, objects, configuration and query.
	 *
	 * @return void
	 */
	private function completeListEntry(ProcessingLogEntry $entry, ReadCaptureRun $run): void {
		$entry->setObjectCount(count($run->objects));
		$entry->setConfidential(false);
		$entry->setOrganisationId((string)$run->anchor->getOrganisation());
		$entry->setQueryParams($run->read->query);
		if (count($run->objects) <= self::LIST_IDENTIFIER_LIMIT) {
			$this->attachListIdentifiers(entry: $entry, run: $run);
		}
	}//end completeListEntry()

	/**
	 * Put every object's uuid, and each subject identifier found, on a list entry.
	 *
	 * @param ProcessingLogEntry $entry The collapsed entry.
	 * @param ReadCaptureRun     $run   The capture: the objects read and the configuration.
	 *
	 * @return void
	 */
	private function attachListIdentifiers(ProcessingLogEntry $entry, ReadCaptureRun $run): void {
		$uuids = [];
		$subjects = [];
		foreach ($run->objects as $object) {
			$uuids[] = (string)$object->getUuid();
			$subject = $this->extractSubject(object: $object, config: $run->config);
			if ($subject['type'] !== null) {
				$subjects[] = ['type' => $subject['type'], 'value' => (string)$subject['value']];
			}
		}

		$entry->setObjectUuids($uuids);
		$entry->setSubjectIds($subjects);
	}//end attachListIdentifiers()

	/**
	 * Flush the in-request buffer as one batched append.
	 *
	 * Fail-soft: on a flush failure the buffer is retained so a caller /
	 * background retry can attempt it again; the primary action is never
	 * affected because flush runs after the response.
	 *
	 * @return int Number of entries persisted (0 on failure or empty buffer).
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	public function flush(): int {
		if ($this->buffer === []) {
			return 0;
		}

		try {
			$count = $this->logMapper->insertBatch(entries: $this->buffer);
			$this->buffer = [];
			return $count;
		} catch (\Throwable $e) {
			$pending = ['exception' => $e->getMessage(), 'pending' => count($this->buffer)];
			$this->logger->error(message: '[AVG] processing-log flush failed; entries retained for retry', context: $pending);
			return 0;
		}
	}//end flush()

	/**
	 * Number of entries currently buffered (test/diagnostic helper).
	 *
	 * @return int
	 *
	 * @spec exclude Test/diagnostic accessor for the in-request buffer; no behaviour.
	 */
	public function pendingCount(): int {
		return count($this->buffer);
	}//end pendingCount()

	/**
	 * Resolve the effective processing config for a schema, inheriting
	 * the new dialect or the legacy string from schema then register.
	 *
	 * @param string|int|null $schemaId Schema identifier.
	 * @param string|int|null $registerId Register identifier.
	 *
	 * @return array<string, mixed>|null Normalised config, or null when none.
	 */
	private function resolveProcessingConfig($schemaId, $registerId): ?array {
		$schemaConfig = $this->readAnnotation(loader: fn () => $this->loadSchemaConfig(schemaId: $schemaId));
		$registerConfig = $this->readAnnotation(loader: fn () => $this->loadRegisterConfig(registerId: $registerId));

		// Schema-level wins; fall back to register-level field by field.
		if ($schemaConfig === null) {
			return $registerConfig;
		}

		$effective = array_merge(($registerConfig ?? []), $schemaConfig);
		// Attribution merges per-key, schema overriding register.
		$effective['attribution'] = array_merge(($registerConfig['attribution'] ?? []), ($schemaConfig['attribution'] ?? []));

		return $effective;
	}//end resolveProcessingConfig()

	/**
	 * Normalise an annotation block from either the new dialect or the
	 * legacy single-string key, through the reader data subject discovery uses too.
	 *
	 * @param callable $loader Returns the raw configuration array or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function readAnnotation(callable $loader): ?array {
		return (new ProcessingAnnotationReader())->read(config: $loader());
	}//end readAnnotation()

	/**
	 * Resolve the attributed activity uuid for the run's action, falling back
	 * to the seeded flagged fallback activity of the anchor's organisation when nothing resolves.
	 *
	 * @param ReadCaptureRun $run The capture: its configuration, action and anchor.
	 *
	 * @return string Resolved (or fallback) activity uuid.
	 */
	private function resolveAttribution(ReadCaptureRun $run): string {
		$attribution = $this->asArray(value: ($run->config['attribution'] ?? null));
		$reference = ($attribution[$run->read->action] ?? $attribution['default'] ?? null);

		if (is_string($reference) === true && $reference !== '') {
			$resolved = $this->vrwMapper->resolveReference(reference: $reference);
			// A draft/retired/non-existent target falls through to fallback.
			if ($resolved !== null && $this->isAttributable(activity: $resolved) === true) {
				return (string)$resolved->getUuid();
			}
		}

		return $this->fallbackActivityUuid(organisationId: (string)$run->anchor->getOrganisation());
	}//end resolveAttribution()

	/**
	 * Whether an activity may receive new attribution.
	 *
	 * `retired`/`archived` activities remain resolvable for history but
	 * MUST NOT accept new processing — they fall back instead.
	 *
	 * @param Verwerkingsactiviteit $activity Candidate activity.
	 *
	 * @return bool
	 */
	private function isAttributable(Verwerkingsactiviteit $activity): bool {
		$status = (string)$activity->getStatus();
		return in_array($status, ['retired', 'archived'], true) === false;
	}//end isAttributable()

	/**
	 * Find-or-create the per-organisation flagged fallback activity.
	 *
	 * @param string $organisationId Tenant scope.
	 *
	 * @return string Fallback activity uuid.
	 */
	private function fallbackActivityUuid(string $organisationId): string {
		$existing = $this->vrwMapper->findByCode(code: self::FALLBACK_CODE);
		if ($existing !== null) {
			return (string)$existing->getUuid();
		}

		$activity = new Verwerkingsactiviteit();
		$activity->setCode(self::FALLBACK_CODE);
		$activity->setName('Niet-geclassificeerde verwerking');
		$activity->setPurpose('Catch-all for processing whose activity attribution did not resolve (AVG accountability gap marker).');
		// Bugfix (verwerkingsregister-i18n): the old call, setRechtsgrond('public-task'), used a
		// hyphenated value that was never a member of RECHTSGROND_VOCABULARY (which required the
		// underscored 'publieke_taak') — the seeded fallback activity's legal basis has been
		// silently invalid since this was written. Corrected to the valid renamed value.
		$activity->setLegalBasis('public_task');
		if ($organisationId !== '') {
			$activity->setOrganisationId($organisationId);
		}

		return (string)$this->vrwMapper->insert(entity: $activity)->getUuid();
	}//end fallbackActivityUuid()

	/**
	 * Extract the data-subject identifier from the object, using the
	 * schema-declared `subjectIdFields` map (`{ "BSN": "bsn" }`).
	 *
	 * @param ObjectEntity $object The read object.
	 * @param array<string, mixed> $config Normalised processing config.
	 *
	 * @return array{type: string|null, value: string|null}
	 */
	private function extractSubject(ObjectEntity $object, array $config): array {
		$fields = $this->asArray(value: ($config['subjectIdFields'] ?? null));
		if ($fields === []) {
			return ['type' => null, 'value' => null];
		}

		$data = $object->getObject();
		foreach ($fields as $idType => $fieldName) {
			$value = ($data[$fieldName] ?? null);
			if (is_scalar($value) === true && (string)$value !== '') {
				return ['type' => (string)$idType, 'value' => (string)$value];
			}
		}

		return ['type' => null, 'value' => null];
	}//end extractSubject()

	/**
	 * Current actor identifier — NC user id, or `system` when none.
	 *
	 * @return string
	 */
	private function currentActor(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return 'system';
		}

		return $user->getUID();
	}//end currentActor()

	/**
	 * Best-effort channel detection from the runtime.
	 *
	 * @return string One of ui | api | public | background.
	 */
	private function detectChannel(): string {
		if (class_exists('\OC', false) === true && \OC::$CLI === true) {
			return 'background';
		}

		if ($this->userSession->getUser() === null) {
			return 'public';
		}

		return 'ui';
	}//end detectChannel()

	/**
	 * Load a schema's configuration array, RBAC/multitenancy bypassed.
	 *
	 * @param string|int|null $schemaId Schema identifier.
	 *
	 * @return array<string, mixed>|null
	 */
	private function loadSchemaConfig($schemaId): ?array {
		if ($schemaId === null || $schemaId === '' || $schemaId === 0) {
			return null;
		}

		$schema = $this->catalog->schemas()->find($schemaId, _rbac: false, _multitenancy: false);
		return $schema->getConfiguration();
	}//end loadSchemaConfig()

	/**
	 * Load a register's configuration array, RBAC/multitenancy bypassed.
	 *
	 * @param string|int|null $registerId Register identifier.
	 *
	 * @return array<string, mixed>|null
	 */
	private function loadRegisterConfig($registerId): ?array {
		if ($registerId === null || $registerId === '' || $registerId === 0) {
			return null;
		}

		$register = $this->catalog->registers()->find($registerId, _multitenancy: false);
		return $register->getConfiguration();
	}//end loadRegisterConfig()

	/**
	 * Coerce a value to an array, returning an empty array when it is not one.
	 *
	 * @param mixed $value Candidate value.
	 *
	 * @return array<array-key, mixed>
	 */
	private function asArray($value): array {
		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}//end asArray()
}//end class
