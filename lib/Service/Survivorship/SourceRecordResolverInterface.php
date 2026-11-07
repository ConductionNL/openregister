<?php

/**
 * Finds the source records linked to a golden record, as callers outside lib/Service ask for them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Survivorship
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Survivorship;

/**
 * Finds the source records linked to a golden record, as callers outside lib/Service ask for them.
 *
 * Declares only what callers outside lib/Service use; the container binds it
 * to {@see SourceRecordResolver} in ContractBindings.
 */
interface SourceRecordResolverInterface {

	/**
	 * Resolve the competing source records for a master, honouring the
	 * annotation's `sourceLink.mode`.
	 *
	 * @param array<string, mixed> $masterData Master object payload.
	 * @param string $masterUuid Master object uuid (needed for reverse-FK).
	 * @param array<string, mixed> $config `x-openregister-survivorship` (or `-merge`) config.
	 * @param string $masterRegister Master's register ref, used as the reverse-FK
	 *                               source register when the annotation omits one
	 *                               (the magic-table query needs a register + schema).
	 *
	 * @return array<int, array<string, mixed>> Resolved source-record payloads.
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#2.1
	 */
	public function resolveSources(array $masterData, string $masterUuid, array $config, string $masterRegister = ''): array;

	/**
	 * Whether the config selects reverse-FK resolution.
	 *
	 * @param array<string, mixed> $config Survivorship/merge config.
	 *
	 * @return bool True when a well-formed reverse-FK `sourceLink` is present.
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#2.1
	 */
	public function isReverseFk(array $config): bool;
}//end interface
