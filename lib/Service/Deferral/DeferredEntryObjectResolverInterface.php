<?php

/**
 * DeferredEntryObjectResolverInterface: what deferred jobs outside the component use of DeferredEntryObjectResolver.
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Resolving a deferred job entry back to its live object, stale-safe.
 *
 * Declares only what jobs outside lib/Service use. ContractBindings binds it
 * to DeferredEntryObjectResolver.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */
interface DeferredEntryObjectResolverInterface {

	/**
	 * Re-fetch the current object for a job entry.
	 *
	 * @param array<string, mixed> $entry Entry carrying `uuid`, `register`, `schema`.
	 *
	 * @return ObjectEntity|null Current entity, or null when the entry is
	 *                           malformed, the object no longer exists, or it
	 *                           has been soft-deleted (stale no-op signal).
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function resolve(array $entry): ?ObjectEntity;
}//end interface
