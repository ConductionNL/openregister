<?php

/**
 * OpenRegister ObjectFindScope
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec exclude Value object of the ObjectService::find() facade; read behaviour owned by object-interactions.
 */

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The register and schema one ObjectService::find() retrieval is scoped to; null means unscoped.
 *
 * @spec exclude Value object of the ObjectService::find() facade; read behaviour owned by object-interactions.
 */
final class ObjectFindScope {

	/**
	 * Constructor.
	 *
	 * @param Register|null $register The register the lookup is scoped to.
	 * @param Schema|null   $schema   The schema the lookup is scoped to.
	 *
	 * @spec exclude Value object of the ObjectService::find() facade; read behaviour owned by object-interactions.
	 */
	public function __construct(
		public readonly ?Register $register,
		public readonly ?Schema $schema,
	) {
	}//end __construct()
}//end class
