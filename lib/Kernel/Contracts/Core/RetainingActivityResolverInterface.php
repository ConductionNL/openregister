<?php
/**
 * RetainingActivityResolverInterface: resolves a processing activity reference for the retention clocks.
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
 * Turns the processing activity an object names into the activity whose retention period applies.
 *
 * The retention clocks (Core) ask; the processing-activity register
 * (Compliance) answers through its mapper, which is bound to this contract
 * in Compliance's own mapper map.
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */
interface RetainingActivityResolverInterface {

	/**
	 * Resolve a `code|uuid` reference to the processing activity it names.
	 *
	 * @param string $reference The reference string (code or uuid).
	 *
	 * @return RetainingActivityInterface|null The activity, or null when none matches.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function resolveReference(string $reference): ?RetainingActivityInterface;
}//end interface
