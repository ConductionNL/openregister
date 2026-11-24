<?php
/**
 * CascadeRetentionCheckInterface: whether a referential-integrity cascade may delete a record.
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
 * Says whether a record a parent's delete cascades onto is legally retained.
 *
 * The referential-integrity cascade (Core) asks before it deletes a target; the
 * archival retention guard (Compliance) answers and binds itself to this contract
 * in its own wiring. A retained target is skipped and the parent delete proceeds.
 *
 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
 */
interface CascadeRetentionCheckInterface {

	/**
	 * The retained report for a cascade target, or null when the cascade may delete it.
	 *
	 * @param string          $uuid             UUID of the cascade target.
	 * @param string|int|null $schemaIdentifier Schema identifier carried on the cascade target.
	 *
	 * @return array<string, mixed>|null The retained report, or null when the cascade may proceed.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	public function cascadeRefusal(string $uuid, string|int|null $schemaIdentifier): ?array;
}//end interface
