<?php

/**
 * LinkableUrl: which absolute URLs a notification action may link to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notification
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * The one rule for a `url` action target, shared by the schema save and the dispatcher.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class LinkableUrl {

	/**
	 * The schemes a `url` action target may link to.
	 *
	 * @var array<int, string>
	 */
	public const SCHEMES = ['http', 'https', 'mailto'];

	/**
	 * Whether a declared URL is a valid web or mail link.
	 *
	 * @param string $href The declared URL.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function accepts(string $href): bool {
		$scheme = strtolower((string)parse_url($href, PHP_URL_SCHEME));

		return filter_var($href, FILTER_VALIDATE_URL) !== false && in_array($scheme, self::SCHEMES, true) === true;
	}//end accepts()

	/**
	 * The declared URL when it is linkable, else null.
	 *
	 * @param string $href The declared URL.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function orNull(string $href): string|null {
		if ($this->accepts(href: $href) === false) {
			return null;
		}

		return $href;
	}//end orNull()
}//end class
