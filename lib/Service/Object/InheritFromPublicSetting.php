<?php

/**
 * An inheritFromPublic flag as stored at one level of the authorization cascade.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * The stored inheritFromPublic value, the cascade level it was read at ('schema' | 'register') and the schema id.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class InheritFromPublicSetting {

	/**
	 * Constructor for InheritFromPublicSetting.
	 *
	 * @param mixed    $value    The stored value, as stored (not necessarily a boolean).
	 * @param string   $level    The cascade level ('schema' | 'register').
	 * @param int|null $schemaId The schema id.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly mixed $value,
		public readonly string $level,
		public readonly ?int $schemaId,
	) {
	}//end __construct()
}//end class
