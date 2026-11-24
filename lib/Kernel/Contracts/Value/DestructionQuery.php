<?php

/**
 * OpenRegister DestructionQuery
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Kernel\Contracts\Value
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

use DateTimeImmutable;

/**
 * Which destruction entries of the audit trail a sync read asks for.
 *
 * Every destruction entry created at or after `since`, optionally within one
 * register and one schema, optionally only the ones a named actor wrote, in
 * the order they were written.
 *
 * @spec openspec/changes/incremental-sync-for-api-consumers/specs/incremental-sync/spec.md#requirement-destroyed-objects-are-reported-as-tombstones-req-sync-003
 */
final class DestructionQuery {

	/**
	 * The audit action that records a destruction: the rows this query reads.
	 *
	 * Core's DestructionActionInterface names the same value from here, so the
	 * audit trail mapper reads it without naming a Core class.
	 *
	 * @var string
	 */
	public const DESTRUCTION_ACTION = 'object.destroyed';

	/**
	 * Constructor.
	 *
	 * @param DateTimeImmutable $since      Inclusive lower bound on the entry's `created`.
	 * @param int|null          $registerId Only this register; every register when null.
	 * @param int|null          $schemaId   Only this schema; every schema when null.
	 * @param string|null       $actor      Only entries this user wrote; every entry when null.
	 * @param int|null          $limit      Maximum rows; no maximum when null.
	 * @param int               $offset     Rows to skip.
	 *
	 * @spec openspec/changes/incremental-sync-for-api-consumers/specs/incremental-sync/spec.md#requirement-destroyed-objects-are-reported-as-tombstones-req-sync-003
	 */
	public function __construct(
		public readonly DateTimeImmutable $since,
		public readonly ?int $registerId = null,
		public readonly ?int $schemaId = null,
		public readonly ?string $actor = null,
		public readonly ?int $limit = null,
		public readonly int $offset = 0,
	) {
	}//end __construct()

	/**
	 * The same query over another page.
	 *
	 * @param int|null $limit  Maximum rows; no maximum when null.
	 * @param int      $offset Rows to skip.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/incremental-sync-for-api-consumers/specs/incremental-sync/spec.md#requirement-destroyed-objects-are-reported-as-tombstones-req-sync-003
	 */
	public function page(?int $limit, int $offset): self {
		return new self(
			since: $this->since,
			registerId: $this->registerId,
			schemaId: $this->schemaId,
			actor: $this->actor,
			limit: $limit,
			offset: $offset
		);
	}//end page()
}//end class
