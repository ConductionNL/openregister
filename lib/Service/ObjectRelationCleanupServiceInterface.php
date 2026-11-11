<?php

/**
 * ObjectRelationCleanupServiceInterface: what callers outside the Service component ask of ObjectRelationCleanupService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Removes what referred to an object once it has been deleted.
 */
interface ObjectRelationCleanupServiceInterface {

	/**
	 * Run every relation cleanup for one deleted object UUID.
	 *
	 * Idempotent by construction: each cleanup deletes rows matching the
	 * UUID, so a second run finds nothing and is a no-op. This is what makes
	 * the at-least-once delivery of the deferred job safe.
	 *
	 * @param string $objectUuid UUID of the deleted object.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-event-sync-async-split/specs/event-driven-architecture/spec.md
	 */
	public function cleanup(string $objectUuid): void;
}//end interface
