<?php

/**
 * OpenRegister translation search options
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

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\ListQuery;

/**
 * How a translation search runs: the listing (query and limit) and the two switches the caller sent.
 *
 * The switches arrive as query strings; `1`, `true`, `yes` and `on` turn them on.
 *
 * @spec openspec/specs/register-i18n/spec.md
 */
final class TranslationSearchOptions {
	/**
	 * Hold the options.
	 *
	 * @param ListQuery   $listing         The full-text query and the result limit.
	 * @param string|null $isOutOfDate     Restrict to outdated rows when truthy.
	 * @param string|null $compareToSource Attach each row's source value when truthy.
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	public function __construct(
		public readonly ListQuery $listing,
		public readonly ?string $isOutOfDate,
		public readonly ?string $compareToSource,
	) {
	}//end __construct()

	/**
	 * Whether only outdated rows are asked for.
	 *
	 * @return bool True when the switch is on.
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	public function outdatedOnly(): bool {
		return self::isOn(value: $this->isOutOfDate);
	}//end outdatedOnly()

	/**
	 * Whether each row's source value is asked for.
	 *
	 * @return bool True when the switch is on.
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	public function withSource(): bool {
		return self::isOn(value: $this->compareToSource);
	}//end withSource()

	/**
	 * Whether a query switch is on.
	 *
	 * @param string|null $value The raw query value.
	 *
	 * @return bool True for `1`, `true`, `yes` or `on`, in any case and with surrounding space.
	 */
	private static function isOn(?string $value): bool {
		if ($value === null || $value === '') {
			return false;
		}

		return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
	}//end isOn()
}//end class
