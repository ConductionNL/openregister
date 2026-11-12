<?php

/**
 * Which registers, schemas and rows one token grant reaches.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * The reach of a token grant. An absent axis (null) does not narrow; a present
 * one is a closed list, so `[]` means "none", never "all".
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
 */
final class TokenGrantReach {

	/**
	 * Constructor.
	 *
	 * @param array<int, string>|null   $registers Register slugs, or null for every one the holder may reach.
	 * @param array<int, string>|null   $schemas   Schema slugs, or null for every one.
	 * @param array<string, mixed>|null $match     A row condition, in the conditional-scope grammar.
	 */
	public function __construct(
		public readonly ?array $registers = null,
		public readonly ?array $schemas = null,
		public readonly ?array $match = null,
	) {
	}//end __construct()
}//end class
