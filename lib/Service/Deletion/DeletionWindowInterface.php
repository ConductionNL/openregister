<?php

/**
 * DeletionWindowInterface: a soft-deleted object's recovery window, as the trash surface publishes it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Deletion
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Deletion;

use DateTimeImmutable;

/**
 * A soft-deleted object's recovery window, as the trash surface publishes it.
 *
 * Callers outside lib/Service type against this; DeletionWindow implements it.
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */
interface DeletionWindowInterface {
	/**
	 * The date from which the object may be destroyed.
	 *
	 * @return DateTimeImmutable The destroyable-from date.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function destroyableFrom(): DateTimeImmutable;

	/**
	 * Whether the window has lapsed, so destruction is now permitted.
	 *
	 * @return bool True once the destroyable-from date has passed.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function hasLapsed(): bool;

	/**
	 * The window as the API publishes it.
	 *
	 * @return array<string, mixed> The published shape.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function toArray(): array;
}//end interface
