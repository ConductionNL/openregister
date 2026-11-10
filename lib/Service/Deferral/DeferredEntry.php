<?php

/**
 * OpenRegister DeferredEntry
 *
 * One listener entry handed to the deferral, and the job class it is enqueued for.
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
 * One listener entry handed to the deferral, and the job class it is enqueued for.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */
final class DeferredEntry {
	/**
	 * Name the entry.
	 *
	 * @param string               $jobClass FQCN of the ActorForwardedJob subclass.
	 * @param array<string, mixed> $entry    Entry payload (uuid/register/schema/version + extras).
	 */
	public function __construct(
		public readonly string $jobClass,
		public readonly array $entry,
	) {
	}//end __construct()
}//end class
