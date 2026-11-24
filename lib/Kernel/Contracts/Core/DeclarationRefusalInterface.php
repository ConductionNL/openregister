<?php
/**
 * DeclarationRefusalInterface: a schema declaration refused at save, with every fault.
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
 * An exception that refuses a schema declaration and names each fault.
 *
 * The schema API (Core) answers such a refusal with a 422 carrying the
 * errors; an area whose validator refuses a declaration implements this on
 * its exception, so the API need not name it.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
interface DeclarationRefusalInterface {

	/**
	 * Every fault of the refused declaration.
	 *
	 * @return array<int, mixed> The faults, in the order they were found.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function getErrors(): array;
}//end interface
