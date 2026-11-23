<?php

/**
 * OpenRegister masked write
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\Schema;

/**
 * A write payload that may send back the mask of an explicitly revealed property, with its schema and the stored object.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class MaskedWrite {
	/**
	 * Constructor.
	 *
	 * @param Schema|null          $schema   The schema.
	 * @param array<string, mixed> $incoming The write payload.
	 * @param array<string, mixed> $stored   The stored object, unrendered.
	 */
	public function __construct(
		public readonly ?Schema $schema,
		public readonly array $incoming,
		public readonly array $stored,
	) {
	}//end __construct()
}//end class
