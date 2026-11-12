<?php

/**
 * OpenRegister PurposeReadScope
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The register and schema a doelbinding-guarded read touches, each when known.
 */
final class PurposeReadScope {

	/**
	 * Constructor.
	 *
	 * @param Register|null $register The register being read, when known.
	 * @param Schema|null   $schema   The schema being read, when known.
	 */
	public function __construct(
		public readonly ?Register $register = null,
		public readonly ?Schema $schema = null,
	) {
	}//end __construct()
}//end class
