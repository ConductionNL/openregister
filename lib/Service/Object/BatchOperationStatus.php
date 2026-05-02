<?php

/**
 * BatchOperationStatus value object.
 *
 * Aggregates per-row outcomes for a batched bulk-upsert call so the
 * caller can observe progress and surface granular results to the
 * user (job dashboard, retry tooling, telemetry). Closes the
 * `reference-existence-validation` 2c batch-optimisation primitive
 * task: a streaming bulk-upsert path needs a status object that can
 * be observed mid-operation AND inspected at completion.
 *
 * Design:
 *  - Mutable container — `record*` methods append outcomes as the
 *    streaming loop progresses; the caller reads totals at any point
 *    or marks the run complete.
 *  - Per-row outcomes are typed: `created` / `updated` / `unchanged`
 *    / `failed`. The first three carry the resulting object UUID;
 *    `failed` carries `(uuid?, message, exceptionClass)` so a retry
 *    pass can rebuild the failed input set.
 *  - `referenceCacheHits` / `referenceCacheMisses` capture the value
 *    of the streaming primitive: rows that referenced the same
 *    target UUID a second time within the batch reuse the cache
 *    verdict, avoiding O(N) database round-trips.
 *  - `start()` and `complete()` capture wall-clock timing so the
 *    caller can report duration without a separate stopwatch.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/reference-existence-validation/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * Mutable, in-memory aggregation of per-row outcomes for a streaming
 * bulk-upsert batch.
 */
class BatchOperationStatus
{

    /**
     * @var float|null
     */
    private ?float $startedAt = null;

    /**
     * @var float|null
     */
    private ?float $completedAt = null;

    /**
     * @var list<string>
     */
    private array $created = [];

    /**
     * @var list<string>
     */
    private array $updated = [];

    /**
     * @var list<string>
     */
    private array $unchanged = [];

    /**
     * @var list<array{uuid: string|null, message: string, exceptionClass: string}>
     */
    private array $failed = [];

    private int $referenceCacheHits = 0;

    private int $referenceCacheMisses = 0;

    /**
     * Mark the start of the batch. Idempotent — re-calling does not
     * reset; use a fresh instance for a fresh run.
     */
    public function start(): void
    {
        if ($this->startedAt === null) {
            $this->startedAt = microtime(true);
        }
    }//end start()

    /**
     * Mark the end of the batch.
     */
    public function complete(): void
    {
        if ($this->completedAt === null) {
            $this->completedAt = microtime(true);
        }
    }//end complete()

    /**
     * Append a created-row outcome.
     */
    public function recordCreated(string $uuid): void
    {
        $this->created[] = $uuid;
    }//end recordCreated()

    /**
     * Append an updated-row outcome.
     */
    public function recordUpdated(string $uuid): void
    {
        $this->updated[] = $uuid;
    }//end recordUpdated()

    /**
     * Append an unchanged-row outcome (input matched stored data).
     */
    public function recordUnchanged(string $uuid): void
    {
        $this->unchanged[] = $uuid;
    }//end recordUnchanged()

    /**
     * Append a failed-row outcome.
     *
     * @param string|null $uuid           UUID of the input row, or null when not yet assigned.
     * @param string      $message        Human-readable failure message.
     * @param string      $exceptionClass Fully-qualified class name of the exception.
     */
    public function recordFailed(?string $uuid, string $message, string $exceptionClass): void
    {
        $this->failed[] = [
            'uuid'           => $uuid,
            'message'        => $message,
            'exceptionClass' => $exceptionClass,
        ];
    }//end recordFailed()

    /**
     * Increment the reference-cache-hit counter. Called by the
     * streaming primitive each time a referenced UUID resolves
     * via the request-scoped cache instead of a fresh DB lookup.
     */
    public function recordReferenceCacheHit(): void
    {
        $this->referenceCacheHits++;
    }//end recordReferenceCacheHit()

    /**
     * Increment the reference-cache-miss counter (fresh DB lookup).
     */
    public function recordReferenceCacheMiss(): void
    {
        $this->referenceCacheMisses++;
    }//end recordReferenceCacheMiss()

    /**
     * @return list<string>
     */
    public function getCreated(): array
    {
        return $this->created;
    }//end getCreated()

    /**
     * @return list<string>
     */
    public function getUpdated(): array
    {
        return $this->updated;
    }//end getUpdated()

    /**
     * @return list<string>
     */
    public function getUnchanged(): array
    {
        return $this->unchanged;
    }//end getUnchanged()

    /**
     * @return list<array{uuid: string|null, message: string, exceptionClass: string}>
     */
    public function getFailed(): array
    {
        return $this->failed;
    }//end getFailed()

    public function getCreatedCount(): int
    {
        return count($this->created);
    }//end getCreatedCount()

    public function getUpdatedCount(): int
    {
        return count($this->updated);
    }//end getUpdatedCount()

    public function getUnchangedCount(): int
    {
        return count($this->unchanged);
    }//end getUnchangedCount()

    public function getFailedCount(): int
    {
        return count($this->failed);
    }//end getFailedCount()

    public function getProcessedCount(): int
    {
        return ($this->getCreatedCount() + $this->getUpdatedCount() + $this->getUnchangedCount() + $this->getFailedCount());
    }//end getProcessedCount()

    public function getReferenceCacheHits(): int
    {
        return $this->referenceCacheHits;
    }//end getReferenceCacheHits()

    public function getReferenceCacheMisses(): int
    {
        return $this->referenceCacheMisses;
    }//end getReferenceCacheMisses()

    /**
     * Wall-clock duration in seconds, or null when the batch has not
     * been completed yet.
     */
    public function getDurationSeconds(): ?float
    {
        if ($this->startedAt === null || $this->completedAt === null) {
            return null;
        }

        return ($this->completedAt - $this->startedAt);
    }//end getDurationSeconds()

    /**
     * Serialise the status as a flat array suitable for JSON
     * responses, log lines, or persistence.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'startedAt'            => $this->startedAt,
            'completedAt'          => $this->completedAt,
            'durationSeconds'      => $this->getDurationSeconds(),
            'processedCount'       => $this->getProcessedCount(),
            'createdCount'         => $this->getCreatedCount(),
            'updatedCount'         => $this->getUpdatedCount(),
            'unchangedCount'       => $this->getUnchangedCount(),
            'failedCount'          => $this->getFailedCount(),
            'created'              => $this->created,
            'updated'              => $this->updated,
            'unchanged'            => $this->unchanged,
            'failed'               => $this->failed,
            'referenceCacheHits'   => $this->referenceCacheHits,
            'referenceCacheMisses' => $this->referenceCacheMisses,
        ];
    }//end toArray()
}//end class
