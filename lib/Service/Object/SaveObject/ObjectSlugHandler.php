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


namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

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
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Design D1: this is the one owner of every slug
 *   write (save, bulk, restore, move, seed, backfill), so each writer has its entry point here.
 */
class ObjectSlugHandler {

	/**
	 * The `objectSlugField` value that builds the slug from the schema name and the
	 * object's name instead of from a property: `<schema slug>-<slug of the value of
	 * objectNameField>`. Offered as the second choice next to a property (Robert
	 * Zondervan, 2026-10-26); frozen like every slug once set.
	 */
	public const SCHEMA_NAME_SOURCE = '@schema.name';

	/**
	 * Paths whose suffixed outcome is always logged: the object silently changes its
	 * public slug there.
	 */
	private const SUFFIX_ALWAYS_SAID = ['restore', 'move', 'seed'];

	/**
	 * Decides the slugs of a bulk save group.
	 *
	 * @var ObjectSlugBatch
	 */
	private ObjectSlugBatch $batch;

	/**
	 * Constructor.
	 *
	 * @param ObjectSlugStore  $store  Slug holders and registers.
	 * @param ObjectSlugSource $source The candidate, and the date-time suffix.
	 * @param ObjectSlugLog    $log    Writes the [ObjectSlug] warnings.
	 */
	public function __construct(
		private readonly ObjectSlugStore $store,
		private readonly ObjectSlugSource $source,
		private readonly ObjectSlugLog $log,
	) {
		$this->batch = new ObjectSlugBatch(handler: $this, store: $store, log: $log);
	}//end __construct()

	/**
	 * Whether a schema has opted into managed slugs.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return bool True when `objectSlugField` is a non-empty string.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-schema-configuration-must-accept-and-persist-the-object-slug-keys
	 */
	public function isManaged(Schema $schema): bool {
		$field = ($schema->getConfiguration() ?? [])['objectSlugField'] ?? null;
		return is_string($field) === true && trim($field) !== '';
	}//end isManaged()

	/**
	 * The slug an object would like to have, before any uniqueness check.
	 *
	 * A non-empty client slug wins (BC5), normalised with the one slugify routine
	 * that produced every stored slug and cut to the column width; otherwise the
	 * value of `objectSlugField` (dot path or template) is slugified the same way.
	 *
	 * @param array       $objectData The object's business data.
	 * @param Schema      $schema     The schema.
	 * @param string|null $clientSlug `@self.slug` or top-level `slug` as sent.
	 *
	 * @return string|null The candidate, or null when there is none.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-an-object-slug-must-come-only-from-the-configured-slug-field-and-must-not-be-written-into-the-object-data
	 */
	public function candidate(array $objectData, Schema $schema, ?string $clientSlug): ?string {
		$normalised = $this->source->normaliseClient(clientSlug: $clientSlug);
		if ($normalised !== null) {
			// Normalised like a generated slug, so `Hello` and `hello` are one slug
			// in both the single and the bulk path and the value fits the column.
			return $normalised;
		}

		if ($this->isManaged(schema: $schema) === false) {
			return null;
		}

		return $this->source->candidate(objectData: $objectData, schema: $schema, maxLength: ObjectSlugSource::MAX_LENGTH);
	}//end candidate()

	/**
	 * Whether a client slug asks for something other than the stored slug.
	 *
	 * Compared after the normalisation `candidate()` applies, so re-sending the slug
	 * the object was created with (in another case or format) is not a change request.
	 *
	 * @param string|null $clientSlug `@self.slug` or top-level `slug` as sent.
	 * @param string      $stored     The stored slug.
	 *
	 * @return bool True when the client asked for a different slug.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-client-supplied-slug-must-follow-the-same-uniqueness-and-freeze-rules
	 */
	public function differsFromStored(?string $clientSlug, string $stored): bool {
		return $this->source->differsFromStored(clientSlug: $clientSlug, stored: $stored);
	}//end differsFromStored()

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
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-an-object-slug-once-set-must-not-be-changed-by-a-later-save
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-client-supplied-slug-must-follow-the-same-uniqueness-and-freeze-rules
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-slug-freed-by-deleting-its-holder-must-go-to-the-next-object-that-saves-without-a-slug
	 */
	public function applyOnSave(ObjectEntity $entity, Schema $schema, ?string $originalSlug, ?string $clientSlug): void {
		if ($this->isManaged(schema: $schema) === false) {
			$this->applyLegacy(entity: $entity, clientSlug: $clientSlug);
			return;
		}

		$scope = new ObjectSlugScope(register: $this->store->register(registerId: $entity->getRegister()), schema: $schema);

		// Freeze (D2): a stored slug stays, whatever the source field or the
		// client says now.
		if ($originalSlug !== null && $originalSlug !== '') {
			$this->keepFrozen(entity: $entity, scope: $scope, originalSlug: $originalSlug, clientSlug: $clientSlug);
			return;
		}

		$this->applyFresh(entity: $entity, scope: $scope, clientSlug: $clientSlug, path: 'single');
	}//end applyOnSave()

	/**
	 * Decide and set the slug of an object a seed-data import creates.
	 *
	 * The same rules as a save of a new object; a suffixed outcome is always logged.
	 *
	 * @param ObjectEntity $entity     The entity being seeded (uuid already set).
	 * @param Schema       $schema     Its schema.
	 * @param string|null  $clientSlug An explicit `slug` in the seed data.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
	 */
	public function applyOnSeed(ObjectEntity $entity, Schema $schema, ?string $clientSlug): void {
		if ($this->isManaged(schema: $schema) === false) {
			$this->applyLegacy(entity: $entity, clientSlug: $clientSlug);
			return;
		}

		$scope = new ObjectSlugScope(register: $this->store->register(registerId: $entity->getRegister()), schema: $schema);
		$this->applyFresh(entity: $entity, scope: $scope, clientSlug: $clientSlug, path: 'seed');
	}//end applyOnSeed()

	/**
	 * Legacy branch (D8): a schema that did not opt in keeps today's behaviour
	 * exactly: a client slug overwrites, nothing else happens.
	 *
	 * @param ObjectEntity $entity     The entity.
	 * @param string|null  $clientSlug What the client sent.
	 *
	 * @return void
	 */
	private function applyLegacy(ObjectEntity $entity, ?string $clientSlug): void {
		if ($clientSlug !== null && $clientSlug !== '') {
			$entity->setSlug($clientSlug);
		}
	}//end applyLegacy()

	/**
	 * Give an object without a stored slug its candidate, when it is free.
	 *
	 * @param ObjectEntity    $entity     The entity.
	 * @param ObjectSlugScope $scope      Its register (null when it did not resolve) and schema.
	 * @param string|null     $clientSlug What the client sent.
	 * @param string          $path       single | seed (log context).
	 *
	 * @return void
	 */
	private function applyFresh(ObjectEntity $entity, ObjectSlugScope $scope, ?string $clientSlug, string $path): void {
		$candidate = $this->candidate(objectData: $entity->getObject() ?? [], schema: $scope->schema, clientSlug: $clientSlug);
		if ($candidate === null) {
			$entity->setSlug(null);
			return;
		}

		$uuid = (string)$entity->getUuid();
		if ($scope->register === null) {
			// Fail closed: without the register no uniqueness check is possible.
			$this->log->registerUnresolved(schema: $scope->schema, objectUuid: $uuid, slug: $candidate);
			$entity->setSlug(null);
			return;
		}

		$entity->setSlug(
			$this->decide(
				want: new ObjectSlugWant(slug: $candidate, scope: $scope, objectUuid: $uuid, path: $path),
				holderOf: fn (string $slug): ?string => $this->store->holderOf(scope: $scope, slug: $slug, excludeUuid: $uuid)
			)
		);
	}//end applyFresh()

	/**
	 * Keep the stored slug of an object and log a different client slug.
	 *
	 * @param ObjectEntity    $entity       The entity being saved.
	 * @param ObjectSlugScope $scope        Its register (when it resolved) and schema.
	 * @param string          $originalSlug The stored slug.
	 * @param string|null     $clientSlug   What the client sent.
	 *
	 * @return void
	 */
	private function keepFrozen(ObjectEntity $entity, ObjectSlugScope $scope, string $originalSlug, ?string $clientSlug): void {
		$entity->setSlug($originalSlug);
		if ($this->differsFromStored(clientSlug: $clientSlug, stored: $originalSlug) === false) {
			return;
		}

		$this->log->ignoredClientSlug(
			want: new ObjectSlugWant(slug: $originalSlug, scope: $scope, objectUuid: (string)$entity->getUuid(), path: 'single'),
			sent: (string)$clientSlug
		);
	}//end keepFrozen()

	/**
	 * The slug a soft-deleted object gets back when it is restored.
	 *
	 * A slug freed by a delete goes to the next object that wants it, so the
	 * old holder may find it taken on restore. In that case it is restored
	 * with the suffixed slug when `objectSlugSuffix` is on and without a slug
	 * otherwise (it then re-enters the normal save flow), and the collision is
	 * logged. A schema without `objectSlugField` is left alone.
	 *
	 * @param ObjectEntity $entity   The object being restored.
	 * @param Register     $register Its register.
	 * @param Schema       $schema   Its schema.
	 *
	 * @return string|null The slug to store; the entity's own slug when nothing changes.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
	 */
	public function slugForRestore(ObjectEntity $entity, Register $register, Schema $schema): ?string {
		return $this->slugAtTarget(entity: $entity, scope: new ObjectSlugScope(register: $register, schema: $schema), path: 'restore');
	}//end slugForRestore()

	/**
	 * The slug an object keeps when a move or migration takes it to another register or schema.
	 *
	 * The slug was decided in the source register+schema, so it is checked
	 * again against the target's live holders before the write: it stays
	 * when it is free there, is suffixed when the target has
	 * `objectSlugSuffix` on, and is cleared otherwise (the collision is
	 * logged as on a save). A target schema without `objectSlugField` keeps
	 * the slug unchanged, as a save there would.
	 *
	 * @param ObjectEntity $entity   The object being moved (still carrying its uuid).
	 * @param Register     $register The target register.
	 * @param Schema       $schema   The target schema.
	 *
	 * @return string|null The slug to store at the target.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
	 */
	public function slugForMove(ObjectEntity $entity, Register $register, Schema $schema): ?string {
		return $this->slugAtTarget(entity: $entity, scope: new ObjectSlugScope(register: $register, schema: $schema), path: 'move');
	}//end slugForMove()

	/**
	 * Re-check an object's existing slug against the live holders of a register+schema.
	 *
	 * @param ObjectEntity    $entity The object.
	 * @param ObjectSlugScope $scope  The register and schema it lands in.
	 * @param string          $path   restore | move (log context).
	 *
	 * @return string|null The slug to store; the entity's own slug when nothing changes.
	 */
	private function slugAtTarget(ObjectEntity $entity, ObjectSlugScope $scope, string $path): ?string {
		$slug = $entity->getSlug();
		if ($slug === null || $slug === '' || $this->isManaged(schema: $scope->schema) === false) {
			return $slug;
		}

		$uuid = (string)$entity->getUuid();
		return $this->decide(
			want: new ObjectSlugWant(slug: $slug, scope: $scope, objectUuid: $uuid, path: $path),
			holderOf: fn (string $wanted): ?string => $this->store->holderOf(scope: $scope, slug: $wanted, excludeUuid: $uuid)
		);
	}//end slugAtTarget()

	/**
	 * The key two slugs share when a case-insensitive collation treats them as one.
	 *
	 * Bulk save and the backfill planner compare slugs through this one fold.
	 *
	 * @param string $slug The slug.
	 *
	 * @return string The folded key.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-bulk-save-must-apply-the-same-slug-rules-as-a-single-save
	 */
	public function foldKey(string $slug): string {
		return $this->batch->fold(slug: $slug);
	}//end foldKey()

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
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-bulk-save-must-apply-the-same-slug-rules-as-a-single-save
	 */
	public function decideBatch(array $rows, Register $register, Schema $schema): array {
		if ($this->isManaged(schema: $schema) === false) {
			return [];
		}

		return $this->batch->decide(rows: $rows, scope: new ObjectSlugScope(register: $register, schema: $schema));
	}//end decideBatch()

	/**
	 * Resolve the slug a candidate becomes, given who holds what.
	 *
	 * @param ObjectSlugWant $want     The wanted slug, its scope, the object and the path
	 *                                 (restore, move and seed always log a suffixed outcome).
	 * @param callable       $holderOf fn(string $slug): ?string — uuid holding $slug, other than this object.
	 * @param bool           $log      Log a collision (false for a backfill dry run).
	 * @param string|null    $suffixed The suffixed slug to try, when the caller already computed it.
	 *
	 * @return string|null The slug to write, or null when the object stays without one.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-objectslugsuffix-must-append-a-utc-date-time-suffix-to-a-colliding-slug
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `log` is false only for the backfill dry run,
	 *   which must decide without writing a warning.
	 */
	public function decide(ObjectSlugWant $want, callable $holderOf, bool $log=true, ?string $suffixed=null): ?string {
		$holder = $holderOf($want->slug);
		if ($holder === null) {
			return $want->slug;
		}

		$tried = null;
		if ($want->scope->suffixOn() === true) {
			$tried = ($suffixed ?? $this->withSuffix(slug: $want->slug));
			if ($holderOf($tried) === null) {
				if ($log === true && in_array($want->path, self::SUFFIX_ALWAYS_SAID, true) === true) {
					$this->log->suffixedInstead(want: $want, holderUuid: $holder, suffixed: $tried);
				}

				return $tried;
			}
		}

		if ($log === true) {
			$this->log->collision(want: $want, holderUuid: $holder, suffixed: $tried);
		}

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
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-objectslugsuffix-must-append-a-utc-date-time-suffix-to-a-colliding-slug
	 */
	public function withSuffix(string $slug, ?string $stamp=null): string {
		return $this->source->withSuffix(slug: $slug, stamp: $stamp);
	}//end withSuffix()

	/**
	 * Whether a slug is a suffixed variant of a candidate (`<base>-<14 digits>`).
	 *
	 * @param string $slug      The stored slug.
	 * @param string $candidate The unsuffixed candidate.
	 *
	 * @return bool True when $slug is $candidate with a date-time suffix.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-objectslugsuffix-must-append-a-utc-date-time-suffix-to-a-colliding-slug
	 */
	public function isSuffixOf(string $slug, string $candidate): bool {
		return $this->source->isSuffixOf(slug: $slug, candidate: $candidate);
	}//end isSuffixOf()

	/**
	 * The current UTC time as a `YmdHis` stamp.
	 *
	 * @return string The stamp.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-objectslugsuffix-must-append-a-utc-date-time-suffix-to-a-colliding-slug
	 */
	public function currentStamp(): string {
		return $this->source->currentStamp();
	}//end currentStamp()
}//end class
