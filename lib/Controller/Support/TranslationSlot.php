<?php

/**
 * OpenRegister translation slot
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
 * One translation slot: an object's property in one language.
 *
 * @spec openspec/specs/register-i18n/spec.md
 */
final class TranslationSlot {
	/**
	 * Name the slot.
	 *
	 * @param string $uuid     The object UUID.
	 * @param string $property The property name.
	 * @param string $language The language code.
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly string $property,
		public readonly string $language,
	) {
	}//end __construct()
}//end class
