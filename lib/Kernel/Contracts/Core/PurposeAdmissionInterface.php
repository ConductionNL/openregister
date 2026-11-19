<?php
/**
 * PurposeAdmissionInterface: whether the request's declared purpose admits a read of one table.
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

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The purpose-limitation (doelbinding) question a read that spans tables asks per table.
 *
 * Object reads (Core) ask, and leave out a table the request may not read instead
 * of refusing the whole read; the purpose guard (Compliance) answers and binds
 * itself to this port in its own registrar.
 */
interface PurposeAdmissionInterface {

	/**
	 * Whether a read of this table may run, without recording a refusal.
	 *
	 * For a search that spans tables and leaves out the ones it may not read,
	 * rather than refusing the whole search.
	 *
	 * @param Register|null $register The register being read.
	 * @param Schema|null   $schema   The schema being read.
	 *
	 * @return bool True when no purpose is needed or the declared one is accepted.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function allows(?Register $register, ?Schema $schema): bool;
}//end interface
