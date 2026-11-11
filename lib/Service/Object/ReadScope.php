<?php

/**
 * ReadScope: the register and schema one object read is scoped to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The register and schema a GetObject read is scoped to; either may be absent.
 *
 * Unlike RegisterSchema (a resolved magic table, both present) a read may be
 * unscoped, so both members are nullable.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class ReadScope {

	/**
	 * Constructor
	 *
	 * @param Register|null $register The register, when the read names one.
	 * @param Schema|null   $schema   The schema, when the read names one.
	 */
	public function __construct(
		public readonly ?Register $register = null,
		public readonly ?Schema $schema = null,
	) {
	}//end __construct()
}//end class
