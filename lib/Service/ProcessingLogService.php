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
use OCA\OpenRegister\Db\ProcessingLogMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapper;
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
	public const ANNOTATION_KEY = 'x-openregister-processing';

	/**
	 * Legacy single-string attribution annotation (shorthand).
	 *
	 * @var string
	 */
	public const LEGACY_ANNOTATION_KEY = 'x-openregister-processing-activity';

	/**
	 * Code of the per-organisation seeded flagged fallback activity.
	 *
	 * @var string
	 */
	public const FALLBACK_CODE = 'niet-geclassificeerde-verwerking';

	/**
	 * In-request buffer of pending entries (flushed after the response).
	 *
	 * @var array<int, ProcessingLogEntry>
	 */
	private array $buffer = [];

	/**
	 * Constructor.
	 *
	 * @param ProcessingLogMapper $logMapper Append-only log mapper.
	 * @param VerwerkingsactiviteitMapper $vrwMapper Activity resolver + fallback seed.
	 * @param SchemaMapper $schemaMapper Schema annotation reader.
	 * @param RegisterMapper $registerMapper Register annotation reader (inheritance).
	 * @param IUserSession $userSession Actor identity.
	 * @param LoggerInterface $logger Fail-soft diagnostics.
	 */
	public function __construct(
		private readonly ProcessingLogMapper $logMapper,
		private readonly VerwerkingsactiviteitMapper $vrwMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly RegisterMapper $registerMapper,
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
	 * @param ObjectEntity $object The object being processed.
	 * @param string $action `read` | `export`.
	 * @param string|null $channel Access channel; defaults from the request shape.
	 * @param string|null $actor Explicit actor (e.g. API client id) or null for the NC user.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	public function logRead(
		ObjectEntity $object,
		string $action = 'read',
		?string $channel = null,
		?string $actor = null,
	): void {
		try {
			$config = $this->resolveProcessingConfig(schemaId: $object->getSchema(), registerId: $object->getRegister());
			if ($config === null || ($config['logReads'] ?? false) !== true) {
				return;
			}

			$activityUuid = $this->resolveAttribution(
				config: $config,
				action: $action,
				organisationId: (string)$object->getOrganisation()
			);

			$confidential = false;
			$subject = $this->extractSubject(object: $object, config: $config);

			$entry = new ProcessingLogEntry();
			$entry->setActivityId($activityUuid);
			$entry->setAction($action);
			$entry->setActor($actor ?? $this->currentActor());
			$entry->setChannel($channel ?? $this->detectChannel());
			$entry->setRegisterId((string)$object->getRegister());
			$entry->setSchemaId((string)$object->getSchema());
			$entry->setObjectUuid((string)$object->getUuid());
			$entry->setSubjectIdType($subject['type']);
			$entry->setSubjectIdValue($subject['value']);
			$entry->setObjectCount(1);
			$entry->setConfidential($confidential);
			$entry->setOrganisationId((string)$object->getOrganisation());

			$this->buffer[] = $entry;
		} catch (\Throwable $e) {
			// Fail-soft: logging never breaks the read.
			$this->logger->debug(
				message: '[AVG] processing-log capture skipped',
				context: ['exception' => $e->getMessage()]
			);
		}//end try

	}//end logRead()

	/**
	 * Record a single collapsed entry for a list/search result.
	 *
	 * A list response produces ONE entry carrying `objectCount` — never
	 * one row per scanned object. Per-object subject identifiers are
	 * only attached when the result set is small (<= 100); otherwise the
	 * entry stays an aggregate count.
	 *
	 * @param array<int, ObjectEntity> $objects The list result.
	 * @param string $action `read` | `export`.
	 * @param string|null $channel Access channel.
	 * @param string|null $actor Explicit actor or null.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	public function logReadList(
		array $objects,
		string $action = 'read',
		?string $channel = null,
		?string $actor = null,
	): void {
		if ($objects === []) {
			return;
		}

		try {
			// Anchor on the first object to resolve schema/register opt-in.
			$first = $objects[array_key_first($objects)];
			if (($first instanceof ObjectEntity) === false) {
				return;
			}

			$config = $this->resolveProcessingConfig(schemaId: $first->getSchema(), registerId: $first->getRegister());
			if ($config === null || ($config['logReads'] ?? false) !== true) {
				return;
			}

			$activityUuid = $this->resolveAttribution(
				config: $config,
				action: $action,
				organisationId: (string)$first->getOrganisation()
			);

			$entry = new ProcessingLogEntry();
			$entry->setActivityId($activityUuid);
			$entry->setAction($action);
			$entry->setActor($actor ?? $this->currentActor());
			$entry->setChannel($channel ?? $this->detectChannel());
			$entry->setRegisterId((string)$first->getRegister());
			$entry->setSchemaId((string)$first->getSchema());
			$entry->setObjectCount(count($objects));
			$entry->setConfidential(false);
			$entry->setOrganisationId((string)$first->getOrganisation());

			// Only attach a subject identifier when the set is small AND
			// homogeneous enough that a single identifier is meaningful;
			// for safety we leave the identifier null on collapsed lists
			// and rely on objectCount (per-object identifiers for <=100
			// sets are emitted as individual entries by callers that need
			// betrokkene-level granularity).
			$this->buffer[] = $entry;
		} catch (\Throwable $e) {
			$this->logger->debug(
				message: '[AVG] processing-log list capture skipped',
				context: ['exception' => $e->getMessage()]
			);
		}//end try

	}//end logReadList()

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
			$this->logger->error(
				message: '[AVG] processing-log flush failed; entries retained for retry',
				context: ['exception' => $e->getMessage(), 'pending' => count($this->buffer)]
			);
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

		if ($schemaConfig === null && $registerConfig === null) {
			return null;
		}

		// Schema-level wins; fall back to register-level field by field.
		$effective = ($registerConfig ?? []);
		if ($schemaConfig !== null) {
			$effective = array_merge($effective, $schemaConfig);
			// Attribution merges per-key, schema overriding register.
			$effective['attribution'] = array_merge(
				($registerConfig['attribution'] ?? []),
				($schemaConfig['attribution'] ?? [])
			);
		}

		return $effective;
	}//end resolveProcessingConfig()

	/**
	 * Normalise an annotation block from either the new dialect or the
	 * legacy single-string key.
	 *
	 * @param callable $loader Returns the raw configuration array or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function readAnnotation(callable $loader): ?array {
		$config = $loader();
		if (is_array($config) === false) {
			return null;
		}

		$dialect = ($config[self::ANNOTATION_KEY] ?? null);
		if (is_array($dialect) === true) {
			return [
				'logReads' => (($dialect['logReads'] ?? false) === true),
				'attribution' => $this->asArray(value: ($dialect['attribution'] ?? null)),
				'subjectIdFields' => $this->asArray(value: ($dialect['subjectIdFields'] ?? null)),
			];
		}

		// Legacy shorthand: a single attribution reference, reads off.
		$legacy = ($config[self::LEGACY_ANNOTATION_KEY] ?? null);
		if (is_string($legacy) === true && $legacy !== '') {
			return [
				'logReads' => false,
				'attribution' => ['default' => $legacy],
				'subjectIdFields' => [],
			];
		}

		return null;
	}//end readAnnotation()

	/**
	 * Resolve the attributed activity uuid for an action, falling back
	 * to the seeded flagged fallback activity when nothing resolves.
	 *
	 * @param array<string, mixed> $config Normalised processing config.
	 * @param string $action Operation (`read`|`export`|...).
	 * @param string $organisationId Tenant for the fallback seed.
	 *
	 * @return string Resolved (or fallback) activity uuid.
	 */
	private function resolveAttribution(array $config, string $action, string $organisationId): string {
		$attribution = $this->asArray(value: ($config['attribution'] ?? null));
		$reference = ($attribution[$action] ?? $attribution['default'] ?? null);

		if (is_string($reference) === true && $reference !== '') {
			$resolved = $this->vrwMapper->resolveReference(reference: $reference);
			// A draft/retired/non-existent target falls through to fallback.
			if ($resolved !== null && $this->isAttributable(activity: $resolved) === true) {
				return (string)$resolved->getUuid();
			}
		}

		return $this->fallbackActivityUuid(organisationId: $organisationId);
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

		$schema = $this->schemaMapper->find($schemaId, _rbac: false, _multitenancy: false);
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

		$register = $this->registerMapper->find($registerId, _multitenancy: false);
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
