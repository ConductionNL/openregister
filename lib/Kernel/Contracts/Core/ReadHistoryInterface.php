<?php
/**
 * ReadHistoryInterface: the read history the object read path writes to and the `_recent` lens reads.
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

use JsonSerializable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\RecentLensInterface;

/**
 * The read history, as the object read path (Core) asks it.
 *
 * The read path registers an audited read and the search lenses read the caller's
 * history back; the area that keeps the history binds its answer in its own wiring.
 *
 * @spec openspec/specs/object-interactions/spec.md#requirement-recently-opened-is-read-from-the-audit-trail
 */
interface ReadHistoryInterface extends RecentLensInterface {

	/**
	 * The reason the `_recent` lens gives when the history could not be read.
	 *
	 * @var string
	 */
	public const REASON_UNAVAILABLE = 'read-history-unavailable';

	/**
	 * Register an audited read of an object; the row written, or null when none was due.
	 *
	 * @param ObjectEntity $object The object that was read.
	 * @param bool         $audit  False for an internal load that must not be audited.
	 *
	 * @return JsonSerializable|null The row written, or null when none was due.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Mirrors the `audit` flag of an object read request.
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-every-user-facing-read-of-an-object-is-logged-as-a-read
	 */
	public function registerAuditRead(ObjectEntity $object, bool $audit = true): ?JsonSerializable;

	/**
	 * The `_recent` lens for one caller: whether it can answer, why not, and the history.
	 *
	 * @param string|null $userId The caller's uid, or null when anonymous.
	 *
	 * @return array{available: bool, reason: string|null, views: array<string, string>}
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-the-recent-lens-says-why-it-is-empty
	 */
	public function resolveRecentLens(?string $userId): array;
}//end interface
