<?php

/**
 * OpenRegister UuidPattern
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * The shape of a UUID as object data carries it.
 *
 * @spec exclude Shared regular expression; each caller's spec covers its own predicate.
 */
final class UuidPattern {
	/**
	 * A UUID with dashes (8-4-4-4-12) or as 32 hex characters, optionally behind
	 * one word prefix such as `id-`; case-insensitive.
	 */
	public const ANY = '/^([a-z]+-)?([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{32})$/i';
}//end class
