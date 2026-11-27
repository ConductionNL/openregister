<?php

/**
 * ShellBootReadsInterface: the app shell's boot reads, carried by the page.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/shell-reads-from-initial-state/specs/apphost-boilerplate/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Called by every SPA render (the UI pages and the dashboard page) so the
 * page carries the reads nextcloud-vue's app shell would otherwise make after
 * boot. It lives in the kernel so each area's page controller can call it
 * without depending on the area that implements it.
 *
 * @spec openspec/changes/shell-reads-from-initial-state/specs/apphost-boilerplate/spec.md
 */
interface ShellBootReadsInterface {
	/**
	 * Provide the carried reads as initial state of the current page.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/shell-reads-from-initial-state/specs/apphost-boilerplate/spec.md
	 */
	public function provide(): void;
}//end interface
