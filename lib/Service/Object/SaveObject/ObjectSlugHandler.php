<?php

/**
 * OpenRegister ObjectSlugHandler
 *
 * The one place that decides an object's slug: candidate, freeze, uniqueness
 * within the register and schema, the optional date-time suffix and the log
 * lines that name every object involved (#4037).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Decides object slugs for single save, bulk save and the slug backfill.
 *
 * A schema opts in with `configuration.objectSlugField`. For such a schema:
 * a stored slug never changes (freeze); an object without a slug gets its
 * candidate when no other live object in the register and schema holds it;
 * a taken candidate gets a UTC date-time suffix when `objectSlugSuffix` is on,
 * otherwise the object stays without a slug and a warning names both objects.
 * A schema without `objectSlugField` keeps the legacy behaviour: a client
 * slug is set as sent, no query, no log (design D8, BC1).
 *
 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
 */
class ObjectSlugHandler {

	/**
	 * Width of the column that stores a slug.
	 */
	private const MAX_LENGTH = 255;

	/**
	 * Length of `-YYYYMMDDHHMMSS`.
	 */
	private const SUFFIX_LENGTH = 15;

	/**
	 * Registers resolved during this request, by id.
	 *
	 * @var array<string, Register|null>
	 */
	private array $registers = [];

	/**
	 * Constructor.
	 *
	 * @param MagicMapper              $magicMapper    Slug queries on the magic tables.
	 * @param MetadataHydrationHandler $hydrator       Field extraction and the slugify routine.
	 * @param RegisterMapper           $registerMapper Resolves the register of an entity.
	 * @param LoggerInterface          $logger         Receives the [ObjectSlug] warnings.
	 * @param ITimeFactory|null        $timeFactory    Clock for the suffix (null = system clock).
	 */
	public function __construct(
		private readonly MagicMapper $magicMapper,
		private readonly MetadataHydrationHandler $hydrator,
		private readonly RegisterMapper $registerMapper,
		private readonly LoggerInterface $logger,
		private readonly ?ITimeFactory $timeFactory=null,
	) {
	}//end __construct()

	/**
	 * Whether a schema has opted into managed slugs.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return bool True when `objectSlugField` is a non-empty string.
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-schema-configuration-must-accept-and-persist-the-object-slug-keys
	 */
	public function isManaged(Schema $schema): bool {
		$field = ($schema->getConfiguration() ?? [])['objectSlugField'] ?? null;
		return is_string($field) === true && trim($field) !== '';
	}//end isManaged()

	/**
	 * The slug an object would like to have, before any uniqueness check.
	 *
	 * A non-empty client slug wins and is used as sent (BC5); otherwise the
	 * value of `objectSlugField` (dot path or template) is slugified with the
	 * one slugify routine that produced every stored slug.
	 *
	 * @param array       $objectData The object's business data.
	 * @param Schema      $schema     The schema.
	 * @param string|null $clientSlug `@self.slug` or top-level `slug` as sent.
	 *
	 * @return string|null The candidate, or null when there is none.
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-an-object-slug-must-come-only-from-the-configured-slug-field-and-must-not-be-written-into-the-object-data
	 */
	public function candidate(array $objectData, Schema $schema, ?string $clientSlug): ?string {
		if ($clientSlug !== null && trim($clientSlug) !== '') {
			return $clientSlug;
		}

		if ($this->isManaged(schema: $schema) === false) {
			return null;
		}

		$businessData = $objectData;
		if (isset($objectData['object']) === true && is_array($objectData['object']) === true) {
			$businessData = $objectData['object'];
		}

		$value = $this->hydrator->extractMetadataValue(
			data: $businessData,
			fieldPath: (string)$schema->getConfiguration()['objectSlugField'],
			schemaProperties: $schema->getProperties() ?? []
		);
		if ($value === null || trim($value) === '') {
			return null;
		}

		$slug = $this->hydrator->createSlug(text: trim($value));
		return $slug === '' ? null : substr($slug, 0, self::MAX_LENGTH);
	}//end candidate()

	/**
	 * Decide and set the slug of one entity during a single save.
	 *
	 * @param ObjectEntity $entity       The entity being saved (uuid already set).
	 * @param Schema       $schema       Its schema.
	 * @param string|null  $originalSlug The slug stored before this save (null on create).
	 * @param string|null  $clientSlug   `@self.slug` or top-level `slug` as sent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-an-object-slug-once-set-must-not-be-changed-by-a-later-save
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-a-client-supplied-slug-must-follow-the-same-uniqueness-and-freeze-rules
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-a-slug-freed-by-deleting-its-holder-must-go-to-the-next-object-that-saves-without-a-slug
	 */
	public function applyOnSave(
		ObjectEntity $entity,
		Schema $schema,
		?string $originalSlug,
		?string $clientSlug,
	): void {
		// Legacy branch (D8): a schema that did not opt in keeps today's
		// behaviour exactly — a client slug overwrites, nothing else happens.
		if ($this->isManaged(schema: $schema) === false) {
			if ($clientSlug !== null && $clientSlug !== '') {
				$entity->setSlug($clientSlug);
			}

			return;
		}

		$register = $this->resolveRegister(registerId: $entity->getRegister());

		// Freeze (D2): a stored slug stays, whatever the source field or the
		// client says now.
		if ($originalSlug !== null && $originalSlug !== '') {
			$entity->setSlug($originalSlug);
			if ($clientSlug !== null && $clientSlug !== '' && $clientSlug !== $originalSlug) {
				$this->logIgnoredClientSlug(
					register: $register,
					schema: $schema,
					objectUuid: (string)$entity->getUuid(),
					sent: $clientSlug,
					frozen: $originalSlug,
					path: 'single'
				);
			}

			return;
		}

		$candidate = $this->candidate(objectData: $entity->getObject() ?? [], schema: $schema, clientSlug: $clientSlug);
		if ($candidate === null || $register === null) {
			$entity->setSlug(null);
			return;
		}

		$uuid = (string)$entity->getUuid();
		$isTaken = function (string $slug) use ($register, $schema, $uuid): ?string {
			$holders = $this->magicMapper->findSlugHolders(
				register: $register,
				schema: $schema,
				slug: $slug,
				excludeUuid: $uuid
			);
			return $holders[0] ?? null;
		};

		$entity->setSlug(
			$this->decide(
				candidate: $candidate,
				register: $register,
				schema: $schema,
				objectUuid: $uuid,
				holderOf: $isTaken,
				path: 'single'
			)
		);
	}//end applyOnSave()

	/**
	 * Decide the slugs of one register+schema group of a bulk save, in batch order.
	 *
	 * The caller normalises its rows to `['uuid' => ?string, 'clientSlug' =>
	 * ?string, 'data' => array]` and writes each decision back itself, as an
	 * explicit null when there is none, so the bulk writer never falls back
	 * to a top-level `slug` property. Two queries per group (ADR-009).
	 *
	 * @param array<int|string, array{uuid: ?string, clientSlug: ?string, data: array}> $rows The group's rows.
	 * @param Register $register The register of the group.
	 * @param Schema   $schema   The schema of the group.
	 *
	 * @return array<int|string, string|null> Row index => slug; empty when the schema did not opt in.
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-bulk-save-must-apply-the-same-slug-rules-as-a-single-save
	 */
	public function decideBatch(array $rows, Register $register, Schema $schema): array {
		if ($this->isManaged(schema: $schema) === false) {
			return [];
		}

		$suffixOn = ((($schema->getConfiguration() ?? [])['objectSlugSuffix'] ?? false) === true);
		// One stamp for the whole batch, so the suffixed slugs queried below are
		// exactly the ones decide() would write.
		$stamp = $this->stamp();
		$candidates = [];
		$suffixed = [];
		$uuids = [];
		foreach ($rows as $index => $row) {
			$candidates[$index] = $this->candidate(
				objectData: $row['data'],
				schema: $schema,
				clientSlug: $row['clientSlug']
			);
			if ($suffixOn === true && $candidates[$index] !== null) {
				$suffixed[$index] = $this->withSuffix(slug: $candidates[$index], stamp: $stamp);
			}

			if ($row['uuid'] !== null && $row['uuid'] !== '') {
				$uuids[$index] = $row['uuid'];
			}
		}

		// Suffixed variants go into the same query: the single path checks a
		// suffixed slug against the database too (D5, BC6).
		$state = $this->magicMapper->findSlugState(
			register: $register,
			schema: $schema,
			slugs: array_values(array_unique(array_merge(array_filter($candidates), array_values($suffixed)))),
			uuids: array_values($uuids)
		);
		$holders = $state['holders'];
		$stored = $state['stored'];

		$decisions = [];
		foreach ($rows as $index => $row) {
			$uuid = $uuids[$index] ?? null;
			$storedSlug = ($uuid !== null) ? ($stored[$uuid] ?? null) : null;
			$clientSlug = $row['clientSlug'];

			if ($storedSlug !== null) {
				$decisions[$index] = $storedSlug;
				if ($clientSlug !== null && $clientSlug !== '' && $clientSlug !== $storedSlug) {
					$this->logIgnoredClientSlug(
						register: $register,
						schema: $schema,
						objectUuid: (string)$uuid,
						sent: $clientSlug,
						frozen: $storedSlug,
						path: 'bulk'
					);
				}

				continue;
			}

			$candidate = $candidates[$index] ?? null;
			if ($candidate === null) {
				$decisions[$index] = null;
				continue;
			}

			$holderOf = static function (string $slug) use (&$holders, $uuid): ?string {
				foreach ($holders[$slug] ?? [] as $holder) {
					if ($holder !== $uuid) {
						return $holder;
					}
				}

				return null;
			};

			$decided = $this->decide(
				candidate: $candidate,
				register: $register,
				schema: $schema,
				objectUuid: (string)($uuid ?? '?'),
				holderOf: $holderOf,
				path: 'bulk',
				suffixed: $suffixed[$index] ?? null
			);
			$decisions[$index] = $decided;
			if ($decided !== null) {
				// Later rows of this batch see the slug as taken, and a later row
				// for the same object finds it frozen, as consecutive single saves would.
				$holders[$decided][] = (string)($uuid ?? ('batch-' . $index));
				if ($uuid !== null) {
					$stored[$uuid] = $decided;
				}
			}
		}//end foreach

		return $decisions;
	}//end decideBatch()

	/**
	 * Resolve the slug a candidate becomes, given who holds what.
	 *
	 * @param string   $candidate  The wanted slug.
	 * @param Register $register   The register (for the log line).
	 * @param Schema   $schema     The schema (suffix switch, log line).
	 * @param string   $objectUuid The object being decided.
	 * @param callable $holderOf   fn(string $slug): ?string — uuid holding $slug, other than this object.
	 * @param string   $path       single | bulk | backfill (log context).
	 * @param bool     $log        Log a collision (false for a backfill dry run).
	 * @param string|null $suffixed The suffixed slug to try, when the caller already computed it.
	 *
	 * @return string|null The slug to write, or null when the object stays without one.
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-objectslugsuffix-must-append-a-utc-date-time-suffix-to-a-colliding-slug
	 */
	public function decide(
		string $candidate,
		Register $register,
		Schema $schema,
		string $objectUuid,
		callable $holderOf,
		string $path,
		bool $log=true,
		?string $suffixed=null,
	): ?string {
		$holder = $holderOf($candidate);
		if ($holder === null) {
			return $candidate;
		}

		if ((($schema->getConfiguration() ?? [])['objectSlugSuffix'] ?? false) === true) {
			$suffixed ??= $this->withSuffix(slug: $candidate);
			if ($holderOf($suffixed) === null) {
				return $suffixed;
			}
		} else {
			$suffixed = null;
		}

		if ($log === false) {
			return null;
		}

		$this->logCollision(
			register: $register,
			schema: $schema,
			slug: $candidate,
			holderUuid: $holder,
			objectUuid: $objectUuid,
			suffixed: $suffixed,
			path: $path
		);
		return null;
	}//end decide()

	/**
	 * Append `-YYYYMMDDHHMMSS` (UTC), cutting the base so the result fits the column.
	 *
	 * @param string      $slug  The taken slug.
	 * @param string|null $stamp A `YmdHis` stamp to use instead of now.
	 *
	 * @return string The suffixed slug.
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-objectslugsuffix-must-append-a-utc-date-time-suffix-to-a-colliding-slug
	 */
	public function withSuffix(string $slug, ?string $stamp=null): string {
		return $this->suffixBase(slug: $slug) . '-' . ($stamp ?? $this->stamp());
	}//end withSuffix()

	/**
	 * Whether a slug is a suffixed variant of a candidate (`<base>-<14 digits>`).
	 *
	 * @param string $slug      The stored slug.
	 * @param string $candidate The unsuffixed candidate.
	 *
	 * @return bool True when $slug is $candidate with a date-time suffix.
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-objectslugsuffix-must-append-a-utc-date-time-suffix-to-a-colliding-slug
	 */
	public function isSuffixOf(string $slug, string $candidate): bool {
		return preg_match('/^' . preg_quote($this->suffixBase(slug: $candidate), '/') . '-\d{14}$/', $slug) === 1;
	}//end isSuffixOf()

	/**
	 * The current UTC time as a `YmdHis` stamp.
	 *
	 * @return string The stamp.
	 */
	private function stamp(): string {
		$now = $this->timeFactory?->now() ?? new DateTimeImmutable('now');
		return $now->setTimezone(new DateTimeZone('UTC'))->format('YmdHis');
	}//end stamp()

	/**
	 * The part of a slug a suffix is appended to, cut so the result fits the column.
	 *
	 * @param string $slug The slug.
	 *
	 * @return string The base.
	 */
	private function suffixBase(string $slug): string {
		return rtrim(substr($slug, 0, self::MAX_LENGTH - self::SUFFIX_LENGTH), '-');
	}//end suffixBase()

	/**
	 * Log a collision: the object was saved without a slug.
	 *
	 * @param Register    $register   The register.
	 * @param Schema      $schema     The schema.
	 * @param string      $slug       The wanted slug.
	 * @param string      $holderUuid Who holds it.
	 * @param string      $objectUuid Who wanted it.
	 * @param string|null $suffixed   The suffixed slug that was also taken, if tried.
	 * @param string      $path       single | bulk | backfill.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
	 */
	public function logCollision(
		Register $register,
		Schema $schema,
		string $slug,
		string $holderUuid,
		string $objectUuid,
		?string $suffixed,
		string $path,
	): void {
		$alsoTaken = '';
		if ($suffixed !== null) {
			$alsoTaken = sprintf(' (suffixed "%s" also taken)', $suffixed);
		}

		$outcome = 'was saved without a slug';
		if ($path === 'backfill') {
			$outcome = 'gets no slug from the backfill';
		}

		$this->logger->warning(
			message: sprintf(
				'[ObjectSlug] Slug "%s" is already held by object %s in register %s (%s) / schema %s (%s); object %s ' . $outcome . '%s',
				$slug,
				$holderUuid,
				(string)$register->getSlug(),
				(string)$register->getId(),
				(string)$schema->getSlug(),
				(string)$schema->getId(),
				$objectUuid,
				$alsoTaken
			),
			context: $this->context(register: $register, schema: $schema, path: $path) + [
				'slug' => $slug,
				'objectUuid' => $objectUuid,
				'holderUuid' => $holderUuid,
			]
		);
	}//end logCollision()

	/**
	 * Log a client slug that was ignored because the object's slug is frozen.
	 *
	 * @param Register|null $register   The register.
	 * @param Schema        $schema     The schema.
	 * @param string        $objectUuid The object.
	 * @param string        $sent       What the client sent.
	 * @param string        $frozen     The slug that stays.
	 * @param string        $path       single | bulk.
	 *
	 * @return void
	 */
	private function logIgnoredClientSlug(
		?Register $register,
		Schema $schema,
		string $objectUuid,
		string $sent,
		string $frozen,
		string $path,
	): void {
		$this->logger->warning(
			message: sprintf(
				'[ObjectSlug] Ignored client slug "%s" for object %s in register %s (%s) / schema %s (%s): slug "%s" is frozen',
				$sent,
				$objectUuid,
				(string)$register?->getSlug(),
				(string)$register?->getId(),
				(string)$schema->getSlug(),
				(string)$schema->getId(),
				$frozen
			),
			context: $this->context(register: $register, schema: $schema, path: $path) + [
				'slug' => $sent,
				'objectUuid' => $objectUuid,
				'frozenSlug' => $frozen,
			]
		);
	}//end logIgnoredClientSlug()

	/**
	 * The context keys every [ObjectSlug] line carries.
	 *
	 * @param Register|null $register The register.
	 * @param Schema        $schema   The schema.
	 * @param string        $path     single | bulk | backfill | lookup.
	 *
	 * @return array<string, mixed>
	 */
	private function context(?Register $register, Schema $schema, string $path): array {
		return [
			'app' => 'openregister',
			'path' => $path,
			'registerId' => $register?->getId(),
			'registerSlug' => $register?->getSlug(),
			'schemaId' => $schema->getId(),
			'schemaSlug' => $schema->getSlug(),
		];
	}//end context()

	/**
	 * Resolve the register of an entity, once per request.
	 *
	 * @param int|string|null $registerId The entity's register id.
	 *
	 * @return Register|null Null when it does not resolve (the slug is then left empty).
	 */
	private function resolveRegister(int|string|null $registerId): ?Register {
		if ($registerId === null || $registerId === '') {
			return null;
		}

		$key = (string)$registerId;
		if (array_key_exists($key, $this->registers) === false) {
			try {
				$this->registers[$key] = $this->registerMapper->find(id: (int)$registerId, _rbac: false, _multitenancy: false);
			} catch (Throwable $e) {
				$this->logger->warning(
					message: '[ObjectSlug] Could not resolve register ' . $key . ' for the slug check: ' . $e->getMessage(),
					context: ['app' => 'openregister']
				);
				$this->registers[$key] = null;
			}
		}

		return $this->registers[$key];
	}//end resolveRegister()
}//end class
