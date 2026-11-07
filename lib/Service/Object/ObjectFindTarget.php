<?php

/**
 * OpenRegister ObjectFindTarget
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

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The register and schema one ObjectService::find() call names, as the caller gave them.
 *
 * @spec exclude Value object of the ObjectService::find() facade; read behaviour owned by object-interactions.
 */
final class ObjectFindTarget {

	/**
	 * Constructor.
	 *
	 * @param Register|string|int|null $register The register object or its ID/UUID.
	 * @param Schema|string|int|null   $schema   The schema object or its ID/UUID.
	 *
	 * @spec exclude Value object of the ObjectService::find() facade; read behaviour owned by object-interactions.
	 */
	public function __construct(
		public readonly Register|string|int|null $register,
		public readonly Schema|string|int|null $schema,
	) {
	}//end __construct()
}//end class
