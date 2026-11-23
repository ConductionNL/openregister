<?php
/**
 * ObjectTrailReaderInterface: where Core reads one object's audit trail.
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
 * Reads one object's audit trail entries, newest first, as plain fields.
 *
 * Core asks; the audit trail (Compliance) reads its rows and binds its reader outside Core.
 * A failed read is thrown to the caller.
 */
interface ObjectTrailReaderInterface {

	/**
	 * One object's entries, every action, newest first.
	 *
	 * @param string $objectUuid The object's uuid.
	 * @param int    $limit      The most entries to read.
	 *
	 * @return list<array{action: string|null, user: string|null, userName: string|null, changed: mixed, created: \DateTimeInterface|null}> The entries.
	 *
	 * @throws \OCP\DB\Exception When the trail cannot be read.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function objectTrail(string $objectUuid, int $limit): array;
}//end interface
