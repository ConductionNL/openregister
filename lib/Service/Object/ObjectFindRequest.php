<?php

/**
 * OpenRegister ObjectFindRequest
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

/**
 * What one ObjectService::find() call asks for: the identifier and how to retrieve it.
 *
 * @spec exclude Value object of the ObjectService::find() facade; read behaviour owned by object-interactions.
 */
final class ObjectFindRequest {

	/**
	 * Constructor.
	 *
	 * @param int|string $id           The object ID or UUID.
	 * @param array|null $extend       Properties to extend the object with.
	 * @param bool       $files        Whether to include file information.
	 * @param bool       $rbac         Whether to apply RBAC checks.
	 * @param bool       $multitenancy Whether to apply multitenancy filtering.
	 * @param bool       $audit        Whether this read is worth an audit-trail row.
	 *
	 * @spec exclude Value object of the ObjectService::find() facade; read behaviour owned by object-interactions.
	 */
	public function __construct(
		public readonly int|string $id,
		public readonly ?array $extend,
		public readonly bool $files,
		public readonly bool $rbac,
		public readonly bool $multitenancy,
		public readonly bool $audit,
	) {
	}//end __construct()
}//end class
