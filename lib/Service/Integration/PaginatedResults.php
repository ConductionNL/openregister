<?php

/**
 * PaginatedResults: the canonical pagination envelope of an integration list, for callers outside lib/Service.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
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

namespace OCA\OpenRegister\Service\Integration;

/**
 * Normalises an integration list response into the `{items, total, nextCursor}` envelope.
 *
 * The integrations endpoint called PaginatedResult::fromMixed()->toArray()
 * itself; this makes the same two calls, so it no longer names the class.
 */
final class PaginatedResults {

	/**
	 * The canonical envelope of a raw provider value.
	 *
	 * @param mixed $value Raw value returned by a provider or service.
	 *
	 * @return array<string, mixed> The `{items, total, nextCursor}` envelope.
	 */
	public static function envelope(mixed $value): array {
		return PaginatedResult::fromMixed($value)->toArray();
	}//end envelope()
}//end class
