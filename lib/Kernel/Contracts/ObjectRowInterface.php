<?php
/**
 * ObjectRowInterface: what callers outside lib/Db need of a ObjectEntity row.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use DateTime;
use Exception;
use JsonSerializable;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCP\IUserSession;

/**
 * A stored object: its data, its register and schema, and its lifecycle, lock and retention state.
 *
 * Implemented by \OCA\OpenRegister\Db\ObjectEntity. The column accessors are
 * served by Nextcloud's Entity::__call, so they are listed as tags here.
 * getManagedByConfiguration() stays on the entity: it returns a Db\Configuration.
 *
 * @method int getId()
 * @method void setUuid(?string $uuid)
 * @method string|null getSlug()
 * @method void setSlug(?string $slug)
 * @method string|null getUri()
 * @method void setUri(?string $uri)
 * @method string|null getVersion()
 * @method void setVersion(?string $version)
 * @method void setRegister(?string $register)
 * @method void setSchema(?string $schema)
 * @method string|null getSchemaVersion()
 * @method void setSchemaVersion(?string $schemaVersion)
 * @method void setObject(?array $object)
 * @method array|null getFiles()
 * @method void setFiles(?array $files)
 * @method array|null getRelations()
 * @method void setRelations(?array $relations)
 * @method array|null getLocked()
 * @method void setLocked(?array $locked)
 * @method void setOwner(?string $owner)
 * @method array|null getAuthorization()
 * @method void setAuthorization(?array $authorization)
 * @method string|null getFolder()
 * @method void setFolder(?string $folder)
 * @method string|null getApplication()
 * @method void setApplication(?string $application)
 * @method void setOrganisation(?string $organisation)
 * @method array|null getValidation()
 * @method void setValidation(?array $validation)
 * @method array|null getQuality()
 * @method void setQuality(?array $quality)
 * @method array|null getDeleted()
 * @method void setDeleted(?array $deleted)
 * @method array|null getArchived()
 * @method void setArchived(?array $archived)
 * @method array|null getFrozen()
 * @method void setFrozen(?array $frozen)
 * @method string|null getStatus()
 * @method void setStatus(?string $status)
 * @method array|null getGeo()
 * @method void setGeo(?array $geo)
 * @method array|null getRetention()
 * @method void setRetention(?array $retention)
 * @method array|null getTmlo()
 * @method void setTmlo(?array $tmlo)
 * @method array|null getMail()
 * @method void setMail(?array $mail)
 * @method array|null getContacts()
 * @method void setContacts(?array $contacts)
 * @method array|null getNotes()
 * @method void setNotes(?array $notes)
 * @method array|null getTodos()
 * @method void setTodos(?array $todos)
 * @method array|null getCalendar()
 * @method void setCalendar(?array $calendar)
 * @method array|null getTalk()
 * @method void setTalk(?array $talk)
 * @method array|null getDeck()
 * @method void setDeck(?array $deck)
 * @method int|null getSize()
 * @method void setSize(?int $size)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string|null getSummary()
 * @method void setSummary(?string $summary)
 * @method string|null getImage()
 * @method void setImage(?string $image)
 * @method string|null getLabels()
 * @method void setLabels(?string $labels)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 * @method DateTime|null getLastUserActivity()
 * @method void setLastUserActivity(?DateTime $lastUserActivity)
 * @method DateTime|null getModified()
 * @method void setModified(?DateTime $modified)
 * @method float|null getRelevance()
 * @method void setRelevance(?float $relevance)
 * @method array|null getGroups()
 * @method void setGroups(?array $groups)
 */
interface ObjectRowInterface extends JsonSerializable, ObjectEntityInterface {

	/**
	 * Get the URN for this object.
	 *
	 * @return string|null URN string, or null when none is set.
	 */
	public function getUrn(): ?string;

	/**
	 * Get the transient processing-activity override used by the
	 * audit-trail trigger contract. Null when no override is set.
	 *
	 * @return string|null Processing-activity code or UUID, or null.
	 */
	public function getProcessingActivityId(): ?string;

	/**
	 * Set the transient processing-activity override (code or uuid).
	 * Not persisted to the object — read by `AuditTrailMapper` at
	 * write time only.
	 *
	 * @param string|null $processingActivityId Processing-activity code or UUID.
	 *
	 * @return void
	 */
	public function setProcessingActivityId(?string $processingActivityId): void;

	/**
	 * Get the transient import-job UUID that should be stamped on the
	 * next save's audit-trail `create` row. Null when the write is
	 * not part of a tagged bulk import.
	 *
	 * @return string|null Import-job UUID, or null when not part of an import.
	 */
	public function getImportJobId(): ?string;

	/**
	 * Set the transient import-job UUID. Not persisted to the object;
	 * read by `AuditTrailMapper::createAuditTrail()` at write time and
	 * stored on the audit row.
	 *
	 * @param string|null $importJobId Import-job UUID to stamp on the next audit row.
	 *
	 * @return void
	 */
	public function setImportJobId(?string $importJobId): void;

	/**
	 * Set the URN for this object (transient, not persisted).
	 *
	 * @param string|null $urn URN to attach to the object instance.
	 *
	 * @return void
	 */
	public function setUrn(?string $urn): void;

	/**
	 * Read the cached per-language translation completeness map.
	 *
	 * @return array<string, mixed>|null Completeness map keyed by language, or null when not computed.
	 */
	public function getTranslationCompleteness(): ?array;

	/**
	 * Write the cached per-language translation completeness map.
	 *
	 * @param array<string, mixed>|null $translationCompleteness Completeness map keyed by language.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.LongVariable) The name matches ObjectEntity's parameter, so named arguments work through the interface.
	 */
	public function setTranslationCompleteness(?array $translationCompleteness): void;

	/**
	 * Read the effective archival retention metadata.
	 *
	 * @return array<string, mixed>|null The retention decision, or null when not set.
	 */
	public function getArchivalRetention(): ?array;

	/**
	 * Write the effective archival retention metadata.
	 *
	 * Surfaced in the @self envelope as `_retention` by getObjectArray().
	 *
	 * @param array<string, mixed>|null $retention The resolved retention decision.
	 *
	 * @return void
	 */
	public function setArchivalRetention(?array $retention): void;

	/**
	 * Read the two retention clocks.
	 *
	 * @return array<string, mixed>|null The clocks, or null when not resolved.
	 *
	 * @spec openspec/changes/delete-window-and-recorded-destruction/specs/deletion-audit-trail/spec.md
	 */
	public function getRetentionClocks(): ?array;

	/**
	 * Write the two retention clocks.
	 *
	 * Surfaced in the @self envelope as `_clocks` by getObjectArray().
	 *
	 * @param array<string, mixed>|null $clocks The AVG and Archiefwet clocks with their rules.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/delete-window-and-recorded-destruction/specs/deletion-audit-trail/spec.md
	 */
	public function setRetentionClocks(?array $clocks): void;

	/**
	 * Get the registry subscription state, when set by the render layer.
	 *
	 * @return array<string, mixed>|null
	 */
	public function getRegistryState(): ?array;

	/**
	 * Write the registry subscription state.
	 *
	 * Surfaced in the @self envelope as `registry` by getObjectArray().
	 *
	 * @param array<string, mixed>|null $state The subscription state mirror.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function setRegistryState(?array $state): void;

	/**
	 * Get the lifecycle state's field rules, when set by the render layer.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function getFieldRules(): ?array;

	/**
	 * Write the lifecycle state's field rules.
	 *
	 * Surfaced in the @self envelope as `fieldRules` by getObjectArray().
	 *
	 * @param array<string, mixed>|null $rules The resolved hidden, read-only and required lists.
	 *
	 * @return void
	 */
	public function setFieldRules(?array $rules): void;

	/**
	 * Write the current user's follow marker.
	 *
	 * Write-only on purpose: `mergeTransientRenderFields()` reads the property
	 * directly, so a public getter would have no caller. This entity is already
	 * at PHPMD's public-member ceiling, and a getter nothing calls is what it is
	 * there to stop.
	 *
	 * Surfaced in the @self envelope as `watching` (and, deprecated, as
	 * `favourite`) by getObjectArray(). The follow's notification switch rides
	 * along as the second argument rather than a setter of its own, for the
	 * same public-member ceiling; it is surfaced as `watchNotify` only when the
	 * reader follows the object.
	 *
	 * @param boolean|null $watching Whether the current user follows this object.
	 * @param boolean|null $notify   Whether that follow notifies them, or null when not following.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/merge-follow-and-favourites/specs/object-interactions/spec.md#requirement-a-user-can-watch-an-object-they-may-read
	 */
	public function setWatching(?bool $watching, ?bool $notify = null): void;

	/**
	 * Write the follower count.
	 *
	 * Write-only, for the same reason as `setWatching()` above.
	 *
	 * Surfaced in the @self envelope as `watcherCount` by getObjectArray().
	 *
	 * @param integer|null $count The number of followers, or null to omit it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-watchers-are-a-lens-and-a-list
	 */
	public function setWatcherCount(?int $count): void;

	/**
	 * Write the current user's unread marker.
	 *
	 * Write-only, for the same reason as `setWatching()` above:
	 * `mergeTransientRenderFields()` reads the property directly, so a public
	 * getter would have no caller and this entity is already at PHPMD's
	 * public-member ceiling.
	 *
	 * Surfaced in the @self envelope as `unread` by getObjectArray().
	 *
	 * @param boolean|null $unread Whether the object is unread for the current user.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
	 */
	public function setUnread(?bool $unread): void;

	/**
	 * Write the per-sub-resource unread counts.
	 *
	 * Write-only, for the same reason as `setUnread()` above.
	 *
	 * Surfaced in the @self envelope as `unreadCounts` by getObjectArray().
	 *
	 * @param array<string, int>|null $counts Sub-resource name to unread count, or null to omit.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-read-state/spec.md#requirement-unread-is-a-filter-and-a-badge-resolved-in-the-query-req-ors-002
	 */
	public function setUnreadCounts(?array $counts): void;

	/**
	 * Write when the reader last opened this object.
	 *
	 * Write-only, like setWatching(): mergeTransientRenderFields() reads the
	 * property directly. Surfaced in the @self envelope as `viewedAt`.
	 *
	 * @param string|null $viewedAt ISO 8601 moment of the reader's latest read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/read-history-on-audit-trail/specs/object-interactions/spec.md#requirement-recently-opened-is-read-from-the-audit-trail
	 */
	public function setViewedAt(?string $viewedAt): void;

	/**
	 * Write what the reader may do with this object.
	 *
	 * Write-only, like setWatching(): mergeTransientRenderFields() reads the
	 * property directly. Surfaced in the @self envelope as `can`.
	 *
	 * @param array<string, bool>|null $can The reader's rights, e.g. `['update' => true]`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-req-rfce-002-a-cell-in-the-records-list-can-be-edited-in-place
	 */
	public function setCan(?array $can): void;

	/**
	 * Get the object data and set the 'id' to the 'uuid'
	 *
	 * This getter has special logic to inject the UUID as 'id' field,
	 * so it must remain explicit rather than using the magic method.
	 *
	 * The payload keys come from stored JSON, so they are `array-key`, as in
	 * the published Contract\ObjectEntityInterface. A narrower shape here is
	 * more specific than ObjectEntity's own `@method` declaration, which psalm
	 * rejects (LessSpecificImplementedReturnType).
	 *
	 * @return array<array-key, mixed>
	 */
	public function getObject(): array;

	/**
	 * The object's UUID.
	 *
	 * 🔑 EXPLICIT ON PURPOSE — an interface cannot be satisfied by `__call`.
	 *
	 * These five getters were magic (`@method` annotations over Nextcloud's
	 * Entity `__call`). Magic methods do not implement an interface: PHP checks
	 * DECLARED methods, so `implements ObjectEntityInterface` would have been a
	 * fatal error with the annotations alone. The same blind spot has bitten
	 * this fleet before — `method_exists()` is false for every magic method.
	 *
	 * Their `@method` annotations are deliberately LEFT IN PLACE above, even
	 * though a declared method makes them redundant. Removing them is correct
	 * and was tried here: it surfaces **214 phpstan findings** across the app,
	 * because the annotations had been hiding the real return types from static
	 * analysis. That is genuine debt and it deserves its own change with its own
	 * baseline — openregister#2288 already set that precedent for lib/Db — not a
	 * silent passenger on a PR about publishing a contract.
	 *
	 * `@method array|null getObject()` in particular reads like an obvious
	 * deletion — the method has been explicitly declared for a long time, and
	 * psalm reports it as LessSpecificImplementedReturnType. Deleting it was
	 * tried, and it is a trap twice over: psalm's finding is ALREADY in the
	 * baseline, so removing the line trades a suppressed warning for an
	 * `UnusedBaselineEntry` error, and it re-opens the same phpstan array-shape
	 * findings the other five shadows are keeping closed. Leave it.
	 *
	 * @return string|null
	 */
	public function getUuid(): ?string;

	/**
	 * The register this object belongs to.
	 *
	 * @return string|null
	 */
	public function getRegister(): ?string;

	/**
	 * The schema this object conforms to.
	 *
	 * @return string|null
	 */
	public function getSchema(): ?string;

	/**
	 * The owning organisation.
	 *
	 * @return string|null
	 */
	public function getOrganisation(): ?string;

	/**
	 * The owner's user id.
	 *
	 * @return string|null
	 */
	public function getOwner(): ?string;

	/**
	 * Get array of field names that are JSON type
	 *
	 * @return string[] List of field names that are JSON type
	 *
	 * @psalm-return list<string>
	 */
	public function getJsonFields(): array;

	/**
	 * Hydrate the entity from an array of data
	 *
	 * @param array $object Array of data to hydrate the entity with
	 *
	 * @return static Returns the hydrated entity
	 */
	public function hydrate(array $object): static;

	/**
	 * Hydrate the entity from an serialized array of data
	 *
	 * @param array $object Array of data to hydrate the entity with
	 *
	 * @return static Returns the hydrated entity
	 */
	public function hydrateObject(array $object): static;

	/**
	 * Serialize the entity to JSON format
	 *
	 * Merges the object's own data with a '@self' key containing metadata.
	 * Ensures that if a name is not set, the UUID is used as a fallback.
	 *
	 * @return ((array|int|mixed|null|string)[]|mixed)[]
	 *
	 * @psalm-return array{'@self': array{id: null|string, slug: null|string,
	 *     name: null|string, description: int|string, summary: null|string,
	 *     image: null|string, uri: null|string, version: null|string,
	 *     register: array|null|string, schema: array|null|string,
	 *     schemaVersion: null|string, files: array|null,
	 *     relations: array|null, locked: array|null,
	 *     owner: array|null|string, organisation: array|null|string,
	 *     groups: mixed, authorization: array|null, folder: null|string,
	 *     application: array|null|string, validation: array|null,
	 *     geo: array|null, retention: array|null, tmlo: array|null, size: null|string,
	 *     updated: null|string, created: null|string,
	 *     deleted: array|null},...}
	 */
	public function jsonSerialize(): array;

	/**
	 * Get array representation of all object properties
	 *
	 * @param array $object Object array parameter
	 *
	 * @return (array|int|mixed|null|string)[] Array containing all object properties
	 *
	 * @psalm-return array{id: null|string, slug: null|string,
	 *     name: null|string, description: int|string, summary: null|string,
	 *     image: null|string, uri: null|string, version: null|string,
	 *     register: array|null|string, schema: array|null|string,
	 *     schemaVersion: null|string, files: array|null,
	 *     relations: array|null, locked: array|null,
	 *     owner: array|null|string, organisation: array|null|string,
	 *     groups: mixed, authorization: array|null, folder: null|string,
	 *     application: array|null|string, validation: array|null,
	 *     geo: array|null, retention: array|null, tmlo: array|null, size: null|string,
	 *     updated: null|string, created: null|string,
	 *     deleted: array|null}
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	public function getObjectArray(array $object = []): array;

	/**
	 * Whether the live lock on this object is held AGAINST the given caller.
	 *
	 * This is the ONE production predicate for lock ownership. Every write
	 * guard, every unlock authorization and every flow node calls it, and
	 * nothing restates the comparison, tests included. It used to be spelled
	 * three different ways in three places and two of them were wrong.
	 *
	 * The rules:
	 *  - No live lock: held against nobody. An EXPIRED lock is not a lock.
	 *  - User lock: held against everyone whose uid differs from the recorded
	 *    one. This is the historical behaviour, unchanged.
	 *  - Run lock: held against everyone whose run uuid differs from the
	 *    recorded one, INCLUDING a caller presenting the run's own `runAs`
	 *    user and no run uuid. That exclusion is the point: if the runAs user
	 *    were admitted, a lock taken by a run under `alice` would be no
	 *    obstacle to alice, and on an instance where flows run as one service
	 *    account it would be no obstacle to anybody.
	 *  - Run lock with no `runUuid`: held against everybody. A malformed lock
	 *    fails CLOSED, because the alternative is silently converting a
	 *    writer's bug into an open door.
	 *  - User lock, run caller: held against the run. A run is not the person
	 *    it runs as, in EITHER direction. Reading them as one holder let a run
	 *    walk into a person's lock, take the extend branch and rewrite the
	 *    payload as its own — and then release it at the end of the run. The
	 *    person's lock was destroyed by a flow merely passing over the object,
	 *    with no error and no audit of a displacement.
	 *
	 * @param string|null $userId The caller's user id, or null when anonymous.
	 * @param string|null $runUuid The caller's flow-run uuid, when the caller is a run.
	 *
	 * @return bool True when the caller must be refused.
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-ownership-is-decided-by-one-predicate
	 */
	public function isLockedBySomeoneElse(?string $userId, ?string $runUuid = null): bool;

	/**
	 * Describe the live lock's holder for a refusal message.
	 *
	 * A refusal that does not say who holds the lock leaves the reader with
	 * nowhere to go, which is why the message is built here rather than at
	 * each of the four guards.
	 *
	 * @return string|null The holder description, or null when not locked.
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-refuses-a-write-and-names-its-holder
	 */
	public function describeLockHolder(): ?string;

	/**
	 * Get the flow run holding this object, when a run holds it.
	 *
	 * @return string|null The holding run's uuid, or null when the lock is a
	 *                     user lock, malformed, expired or absent.
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-records-whether-a-run-or-a-person-holds-it
	 */
	public function getLockedByRun(): ?string;

	/**
	 * Lock the object for a specific duration
	 *
	 * Passing `$runUuid` takes a RUN-scoped lock: one that refuses every
	 * caller but that run, the run's own `runAs` user included. Omitting it
	 * takes the user lock this method has always taken.
	 *
	 * @param IUserSession $userSession Current user session
	 * @param string|null $process Optional process identifier
	 * @param int|null $duration Lock duration in seconds (default: 1 hour)
	 * @param string|null $runUuid Flow run taking the lock, for a run-scoped lock
	 *
	 * @throws Exception If the object is already locked by another holder
	 *
	 * @return true True if lock was successful
	 *
	 * @psalm-suppress PossiblyUnusedReturnValue
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-ownership-is-decided-by-one-predicate
	 */
	public function lock(
		IUserSession $userSession,
		?string $process = null,
		?int $duration = 3600,
		?string $runUuid = null,
	): bool;

	/**
	 * Unlock the object
	 *
	 * @param IUserSession $userSession Current user session
	 * @param string|null $runUuid Flow run releasing the lock, for a run-scoped lock
	 * @param bool $break Release the lock regardless of who holds it. Reserved
	 *                    for the administrator break-lock, which audits the
	 *                    displacement at its call site.
	 *
	 * @throws Exception If the object is locked by another holder
	 *
	 * @return true True if unlock was successful
	 *
	 * @psalm-suppress PossiblyUnusedReturnValue
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The break flag follows the established RBAC-bypass pattern.
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-ownership-is-decided-by-one-predicate
	 */
	public function unlock(IUserSession $userSession, ?string $runUuid = null, bool $break = false): bool;

	/**
	 * Check if the object is currently locked
	 *
	 * @return bool True if object is locked and lock hasn't expired
	 */
	public function isLocked(): bool;

	/**
	 * Get lock information
	 *
	 * @return array|null Lock information or null if not locked
	 */
	public function getLockInfo(): ?array;

	/**
	 * Get the user ID who locked the object
	 *
	 * Returns the user ID (UID) of the user who has locked this object.
	 * Returns null if the object is not locked or lock information is missing.
	 *
	 * @return string|null User ID who locked the object, or null if not locked
	 */
	public function getLockedBy(): ?string;

	/**
	 * Delete the object
	 *
	 * @param IUserSession $userSession Current user session
	 * @param string $deletedReason Reason for deletion
	 * @param int $retentionPeriod Retention period in days (default: 30 days)
	 *
	 * @throws Exception If no user is logged in
	 *
	 * @return static Returns the entity
	 *
	 * @spec openspec/changes/delete-window-and-recorded-destruction/specs/deletion-audit-trail/spec.md
	 */
	public function delete(IUserSession $userSession, ?string $deletedReason = null, ?int $retentionPeriod = 30): static;

	/**
	 * Whether an active legal hold keeps this record from being destroyed.
	 *
	 * THE SINGLE DEFINITION OF "HELD". A legal hold is a property of the
	 * record, not of its schema, so the answer lives on the record. Three
	 * copies of this two-line predicate used to sit in
	 * {@see \OCA\OpenRegister\Service\RetentionService::hasActiveLegalHold},
	 * {@see \OCA\OpenRegister\Service\Archival\LegalHoldService::hasActiveHold}
	 * and its `hasActiveHoldFromRetention()` sibling. The first two delegate
	 * here now; the third still reads a raw retention array rather than a
	 * record, so it cannot, and it drives no delete path.
	 *
	 * A RELEASED HOLD IS NOT A HOLD. `releaseLegalHold()` leaves the
	 * `legalHold` key in place with `active: false` and the reason in
	 * `history`, so "the key exists" is not the question and never was.
	 *
	 * @return bool True when the record carries an active legal hold.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function hasActiveLegalHold(): bool;

	/**
	 * Whether this object is in the trash.
	 *
	 * The one honest answer to "has this been soft-deleted?". The raw
	 * `getDeleted()` accessor cannot be compared with `null`: the property
	 * defaults to `[]` and the hydrator never overwrites that default for a
	 * live row, so `getDeleted() === null` is false for every object, deleted
	 * or not. Every guard that means "already in the trash" MUST call this.
	 *
	 * @return bool True when the object carries deletion metadata.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function isSoftDeleted(): bool;

	/**
	 * Whether this object has been archived.
	 *
	 * The one honest answer to "is this archived?", for the same reason
	 * {@see self::isSoftDeleted()} exists: the property defaults to `[]` and
	 * the row hydrator never overwrites that default for a row whose
	 * `_archived` column is NULL, so `getArchived() === null` is false for
	 * every object that has ever existed. Every guard that means "already
	 * archived" MUST call this.
	 *
	 * @return bool True when the object carries archive metadata.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function isArchived(): bool;

	/**
	 * Whether this object has been frozen.
	 *
	 * Same trap as {@see self::isArchived()}: never compare `getFrozen()` with
	 * null.
	 *
	 * @return bool True when the object carries freeze metadata.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function isFrozen(): bool;

	/**
	 * Whether this object is a draft (decision 180).
	 *
	 * An object with no stored status is never a draft: its status is deduced
	 * from its dates, as it was before the field existed.
	 *
	 * @return bool True for a stored `draft` status.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-an-object-must-be-able-to-carry-the-explicit-lifecycle-status-draft
	 */
	public function isDraft(): bool;

	/**
	 * Archive the object.
	 *
	 * Writes the marker beside the object's data, never into it: the data is
	 * what the audit trail and the versions are about, so archiving leaves it
	 * and its version history exactly as they were.
	 *
	 * @param IUserSession $userSession Current user session.
	 * @param string|null $reason Why the object was archived.
	 *
	 * @throws Exception If no user is logged in.
	 *
	 * @return static Returns the entity.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function archive(IUserSession $userSession, ?string $reason = null): static;

	/**
	 * Restore the object from the archive.
	 *
	 * Clears the marker outright rather than flipping an `active` key, so
	 * "is this archived?" stays a single question with a single answer. The
	 * archive and the restore both live on the audit trail, which is where the
	 * history belongs.
	 *
	 * @return static Returns the entity.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function unarchive(): static;

	/**
	 * Freeze the object.
	 *
	 * A frozen object stays in every working view and in search, and refuses
	 * every write to its data. `$state` names the lifecycle state that froze
	 * it when a state declared the freeze, and is null when a person did.
	 *
	 * @param IUserSession $userSession Current user session.
	 * @param string|null $reason Why the object was frozen.
	 * @param string|null $state The lifecycle state that declared the freeze.
	 * @param string|null $actor Explicit actor, for a freeze declared by a
	 *                           lifecycle transition running without a session.
	 *
	 * @throws Exception If no user is logged in and no actor was named.
	 *
	 * @return static Returns the entity.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function freeze(
		IUserSession $userSession,
		?string $reason = null,
		?string $state = null,
		?string $actor = null,
	): static;

	/**
	 * Unfreeze the object.
	 *
	 * @return static Returns the entity.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function unfreeze(): static;

	/**
	 * Get the last log entry for this object (runtime only)
	 *
	 * @return array|null The last log entry or null if not set
	 * @phpstan-return array<string, mixed>|null
	 * @psalm-return   array<string, mixed>|null
	 */
	public function getLastLog(): ?array;

	/**
	 * Set the last log entry for this object (runtime only)
	 *
	 * @param array|null $log The log entry to set
	 *
	 * @phpstan-param array<string, mixed>|null $log
	 * @psalm-param   array<string, mixed>|null $log
	 *
	 * @return void
	 */
	public function setLastLog(?array $log = null): void;

	/**
	 * Get the source of this object data (runtime only)
	 *
	 * Returns where this object was loaded from:
	 * - "orm": Magic tables (structured storage)
	 * - "index": Search index
	 *
	 * @return string|null The source identifier, or null if not set
	 */
	public function getSource(): ?string;

	/**
	 * Set the source of this object data (runtime only)
	 *
	 * @param string|null $source The source identifier ("orm" or "index")
	 *
	 * @return void
	 */
	public function setSource(?string $source = null): void;

	/**
	 * String representation of the object entity
	 *
	 * This magic method is required for proper entity handling in Nextcloud
	 * when the framework needs to convert the object to a string.
	 *
	 * @return string String representation of the object entity
	 */
	public function __toString(): string;

	/**
	 * Check if this object is managed by any configuration
	 *
	 * This method checks if the object's ID is present in the objects array
	 * of any provided configuration entities.
	 *
	 * @param array<Configuration> $configurations Array of Configuration entities to check against
	 *
	 * @return bool True if this object is managed by at least one configuration
	 *
	 * @phpstan-param array<Configuration> $configurations
	 * @psalm-param   array<Configuration> $configurations
	 */
	public function isManagedByConfiguration(array $configurations): bool;
}//end interface
