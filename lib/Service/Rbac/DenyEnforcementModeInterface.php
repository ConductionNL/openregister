<?php

/**
 * The deny enforcement switch, as read by the callers outside access control.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * Reads the `openregister.deny_enforcement` mode.
 *
 * The narrow face of {@see DenyEnforcementMode} that the list emitters and the
 * permission reports read: the current mode, and whether a denial refuses.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
interface DenyEnforcementModeInterface {

	/**
	 * The mode this instance runs in.
	 *
	 * @return string One of {@see DenyEnforcementMode::MODES}.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function current(): string;

	/**
	 * Whether a resolved denial is allowed to change the answer.
	 *
	 * @return bool True only in `enforcing`.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function enforces(): bool;
}//end interface
