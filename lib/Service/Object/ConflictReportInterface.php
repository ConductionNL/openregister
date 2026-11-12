<?php

/**
 * ConflictReportInterface: what callers outside the component use of ConflictReport.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\AuditTrail;

/**
 * Declares only what callers outside the component use of ConflictReport; the rest
 * of the class stays its own.
 */
interface ConflictReportInterface {

	/**
	 * The machine-readable code a client branches on.
	 *
	 * 🔴 IT IS `code`, AND `error` KEEPS THE SENTENCE IT ALWAYS HELD. The rest
	 * of this app puts a slug in `error`, and this endpoint has always put a
	 * sentence there instead. Correcting that today would break every client
	 * branching on the substring "Conflict", which is the shape the published
	 * body invited. So the sentence stays where clients expect it and the code
	 * arrives beside it, and the outlier is retired when somebody can count the
	 * clients rather than guess at them.
	 *
	 * @var string
	 */
	public const CODE = 'version-conflict';

	/**
	 * The sentence `error` has carried since this endpoint shipped.
	 *
	 * @var string
	 */
	public const ERROR = 'Conflict: the object was modified since it was read. Re-read and retry.';

	/**
	 * The conflicting properties, with the three readings each.
	 *
	 * @param RefusedWrite           $write       The stored object, its schema and what the caller sent.
	 * @param array<int, AuditTrail> $intervening The changes since the caller read, newest first.
	 *
	 * @return array<string, mixed> The 409 body.
	 *
	 * @spec openspec/changes/a-conflicting-save-shows-the-other-value/specs/objects-crud/spec.md#requirement-a-refused-write-names-the-values-that-conflict-req-cso-001
	 */
	public function build(RefusedWrite $write, array $intervening): array;
}//end interface
