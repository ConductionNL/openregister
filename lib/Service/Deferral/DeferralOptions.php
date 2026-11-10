<?php

/**
 * OpenRegister DeferralOptions
 *
 * How a deferred entry is chunked and coalesced.
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
 * How a deferred entry is chunked and coalesced.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */
final class DeferralOptions {
	/**
	 * Set the chunking and coalescing.
	 *
	 * @param int         $chunkSize Maximum entries per enqueued job.
	 * @param string|null $dedupeKey Optional coalescing key within the buffer.
	 */
	public function __construct(
		public readonly int $chunkSize = ListenerDeferralInterface::DEFAULT_CHUNK_SIZE,
		public readonly ?string $dedupeKey = null,
	) {
	}//end __construct()
}//end class
