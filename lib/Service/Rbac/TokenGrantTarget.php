<?php

/**
 * The schema, and its register, that a token grant is checked against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * The schema slug and register slug a resolved block belongs to. Either may
 * be null: a register nobody can name is one a register-scoped grant does not
 * cover, which narrows rather than widens.
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
 */
final class TokenGrantTarget {

	/**
	 * Constructor.
	 *
	 * @param string|null $schemaSlug   The schema being resolved.
	 * @param string|null $registerSlug Its register.
	 */
	public function __construct(
		public readonly ?string $schemaSlug,
		public readonly ?string $registerSlug,
	) {
	}//end __construct()
}//end class
