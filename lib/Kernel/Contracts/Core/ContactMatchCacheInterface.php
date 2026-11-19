<?php
/**
 * ContactMatchCacheInterface: the contact-match cache a save clears.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

/**
 * The cached contact matches that a saved object's e-mail values can make stale.
 *
 * The object save path (Core) clears them after a save; contact matching keeps the
 * cache and binds its answer in its own wiring.
 *
 * @spec openspec/specs/contacts-actions/spec.md
 */
interface ContactMatchCacheInterface {

	/**
	 * Drop the cached matches of every e-mail value in the object.
	 *
	 * @param array<string, mixed> $object The saved object's data.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	public function invalidateCacheForObject(array $object): void;
}//end interface
