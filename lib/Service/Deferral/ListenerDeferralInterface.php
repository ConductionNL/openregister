<?php

/**
 * ListenerDeferralInterface: what listeners outside the component use of ListenerDeferralService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Deferral
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Deferral;

/**
 * Deferring listener work to actor-forwarded chunk jobs.
 *
 * Declares only what listeners outside lib/Service use. ContractBindings
 * binds it to ListenerDeferralService, so every listener resolves the same
 * shared instance and its buffers coalesce.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */
interface ListenerDeferralInterface {

	/**
	 * Default maximum entries per enqueued job.
	 *
	 * @var int
	 */
	public const DEFAULT_CHUNK_SIZE = 100;

	/**
	 * Whether deferral is enabled on this instance.
	 *
	 * @return bool True when listener work should be deferred to jobs.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function isDeferralEnabled(): bool;

	/**
	 * Buffer one entry for a deferred job class.
	 *
	 * @param string      $jobClass  Job class the entry is enqueued for.
	 * @param array       $entry     The entry payload.
	 * @param int         $chunkSize Maximum entries per enqueued job.
	 * @param string|null $dedupeKey Optional coalescing key within the buffer.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function defer(
		string $jobClass,
		array $entry,
		int $chunkSize = self::DEFAULT_CHUNK_SIZE,
		?string $dedupeKey = null,
	): void;
}//end interface
