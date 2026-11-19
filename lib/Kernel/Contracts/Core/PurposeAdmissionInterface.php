<?php
/**
 * PurposeAdmissionInterface: whether a read of a register and schema is admitted by its declared purpose.
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
 * Admits or refuses a read of a register and schema by its purpose (doelbinding).
 *
 * The object read paths (Core) ask; the purpose guard (Compliance) answers
 * and is bound to this contract. A refusal is thrown as a PurposeRefusalInterface.
 *
 * @spec openspec/specs/verwerkingsregister-api/spec.md
 */
interface PurposeAdmissionInterface {

	/**
	 * Enforce doelbinding on a read, and remember the purpose it ran under.
	 *
	 * Returns null when the read needs no purpose, which is every read on an
	 * instance that has not turned doelbinding on.
	 *
	 * @param Register|null $register The register being read.
	 * @param Schema|null   $schema   The schema being read.
	 *
	 * @return string|null The accepted purpose code, or null when none was needed.
	 *
	 * @throws PurposeRefusalInterface When a purpose is needed and none is usable.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function enforce(?Register $register, ?Schema $schema): ?string;

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
