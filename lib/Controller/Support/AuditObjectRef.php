<?php

/**
 * OpenRegister audit object reference
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Controller\Support;

/**
 * The object whose audit history a route reads, as the route path names it.
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-history-of-one-object-is-readable-by-whoever-may-read-that-object
 */
final readonly class AuditObjectRef {
	/**
	 * Bundle the three path segments that name one object.
	 *
	 * @param string $register The register identifier.
	 * @param string $schema   The schema identifier.
	 * @param string $id       The object identifier.
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-history-of-one-object-is-readable-by-whoever-may-read-that-object
	 */
	public function __construct(
		public string $register,
		public string $schema,
		public string $id,
	) {
	}//end __construct()
}//end class
