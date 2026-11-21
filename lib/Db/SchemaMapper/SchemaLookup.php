<?php

/**
 * SchemaLookup: one SchemaMapper::find() lookup, the identifier and both flags.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\SchemaMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\SchemaMapper;

/**
 * The identifier and flags of a lookup, and the not-found message they make.
 *
 * @spec exclude schema read plumbing of SchemaMapper, not a capability of its own
 */
final class SchemaLookup {
	/**
	 * Constructor
	 *
	 * @param string|int $id           The schema id, uuid or slug.
	 * @param bool       $rbac         Whether the caller asked for RBAC checks.
	 * @param bool       $multitenancy Whether the organisation filter applies.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string|int $id,
		public readonly bool $rbac,
		public readonly bool $multitenancy,
	) {
	}//end __construct()

	/**
	 * The message of the exception for a lookup that found nothing.
	 *
	 * @return string
	 *
	 * @spec exclude schema read plumbing of SchemaMapper, not a capability of its own
	 */
	public function notFoundMessage(): string {
		return sprintf(
			'Schema not found (id=%s, multitenancy=%s, rbac=%s)',
			var_export($this->id, true),
			var_export($this->multitenancy, true),
			var_export($this->rbac, true)
		);
	}//end notFoundMessage()
}//end class
