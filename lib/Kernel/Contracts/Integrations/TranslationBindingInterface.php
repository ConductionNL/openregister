<?php

/**
 * TranslationBindingInterface: what answers behind the translation seam, for the connection report.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Integrations
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Integrations;

/**
 * Describes the bound translation provider as a connection status and message.
 *
 * The connection seam report (Integrations) asks; the translation services
 * (Configuration) answer and are bound to this contract.
 *
 * @spec openspec/specs/app-connections/spec.md
 */
interface TranslationBindingInterface {

	/**
	 * Resolve the bound translation provider and describe it.
	 *
	 * @return array{0: string, 1: string} The status and the message.
	 *
	 * @spec openspec/specs/app-connections/spec.md
	 */
	public function describe(): array;
}//end interface
