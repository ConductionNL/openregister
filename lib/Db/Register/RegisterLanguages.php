<?php

/**
 * OpenRegister RegisterLanguages
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
 * The languages of a register; none configured means only Dutch.
 *
 * @spec exclude register plumbing moved out of the entity, not a capability of its own
 */
final class RegisterLanguages {

	/**
	 * The first configured language, or 'nl'.
	 *
	 * @param array<string>|null $languages The configured languages.
	 *
	 * @return string
	 *
	 * @spec exclude register plumbing moved out of the entity, not a capability of its own
	 */
	public function defaultOf(?array $languages): string {
		if (is_array($languages) === true && empty($languages) === false) {
			return $languages[0];
		}

		return 'nl';
	}//end defaultOf()

	/**
	 * Whether the language is configured, or is 'nl' when none are.
	 *
	 * @param array<string>|null $languages The configured languages.
	 * @param string             $language  The language code to check.
	 *
	 * @return bool
	 *
	 * @spec exclude register plumbing moved out of the entity, not a capability of its own
	 */
	public function has(?array $languages, string $language): bool {
		if (is_array($languages) === false || empty($languages) === true) {
			return $language === 'nl';
		}

		return in_array($language, $languages, true);
	}//end has()
}//end class
