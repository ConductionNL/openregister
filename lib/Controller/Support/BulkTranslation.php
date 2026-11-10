<?php

/**
 * OpenRegister bulk translation
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

/**
 * A bulk translation of one object as the caller asked for it: from and to which language, and which properties.
 *
 * @spec openspec/specs/register-i18n/spec.md
 */
final class BulkTranslation {
	/**
	 * Describe the translation.
	 *
	 * @param string|null        $from       Source language code.
	 * @param string|null        $to         Target language code.
	 * @param array<string>|null $properties Optional whitelist of property names.
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	public function __construct(
		public readonly ?string $from,
		public readonly ?string $to,
		public readonly ?array $properties,
	) {
	}//end __construct()

	/**
	 * Whether either language code is missing.
	 *
	 * @return bool True when either is null or empty.
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	public function languagesMissing(): bool {
		return (in_array($this->from, [null, ''], true) === true || in_array($this->to, [null, ''], true) === true);
	}//end languagesMissing()
}//end class
