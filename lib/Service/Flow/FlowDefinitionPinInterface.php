<?php

/**
 * FlowDefinitionPinInterface: the FlowDefinitionPin as the flow-version backfill repair step reaches it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
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

namespace OCA\OpenRegister\Service\Flow;

/**
 * Pins a flow definition, typed for the repair step that backfills run pins.
 *
 * Declares only what callers outside the Service component use; the rest of the
 * class stays its own. Bound in ContractBindings to the same shared instance.
 */
interface FlowDefinitionPinInterface {

	/**
	 * Pin a definition and return the hash a run should carry.
	 *
	 * Returns null rather than throwing when the definition cannot be stored.
	 * A run that cannot be pinned still runs — unpinned, exactly as every run
	 * did before this class existed. Refusing to queue would turn a storage
	 * hiccup into a halted workflow, which is a worse failure than the one
	 * being fixed.
	 *
	 * @param array<string, mixed> $flow   The resolved flow document.
	 * @param string               $flowId The flow uuid, for provenance.
	 *
	 * @return string|null The hash to pin, or null when it could not be pinned.
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md
	 */
	public function pin(array $flow, string $flowId): ?string;
}//end interface
