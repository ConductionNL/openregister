<?php

/**
 * OpenRegister MagicMappingOptions
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\Register
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

namespace OCA\OpenRegister\Db\Register;

/**
 * How magic mapping is enabled for one schema: whether its table is created on demand, and why.
 *
 * @psalm-immutable
 *
 * @spec exclude register plumbing, not a capability of its own
 */
final class MagicMappingOptions {
	/**
	 * Describe the options.
	 *
	 * @param bool        $autoCreateTable Whether to auto-create the table.
	 * @param string|null $comment         Optional comment describing why magic mapping is enabled.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Auto-create table toggle is intentional
	 */
	public function __construct(
		public readonly bool $autoCreateTable = true,
		public readonly ?string $comment = null,
	) {
	}//end __construct()
}//end class
