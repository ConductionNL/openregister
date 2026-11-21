<?php

/**
 * AccentFoldingSetting: whether an administrator wants object search to ignore accents.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-accent-insensitive/specs/zoeken-filteren/spec.md#requirement-accent-folding-is-visible-to-administrators-and-can-be-bypassed
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCP\IAppConfig;

/**
 * The administrator's choice, stored in app config. Enabled unless switched
 * off, so a fresh instance folds accents wherever the database can.
 *
 * Whether folding actually happens also depends on availability
 * ({@see MagicUnaccentProbe}); this class only holds the wish.
 *
 * @spec openspec/changes/search-accent-insensitive/specs/zoeken-filteren/spec.md#requirement-accent-folding-is-visible-to-administrators-and-can-be-bypassed
 */
final class AccentFoldingSetting {

	/**
	 * The app config key.
	 */
	public const KEY = 'search_accent_insensitive';

	/**
	 * Build the setting.
	 *
	 * @param IAppConfig $appConfig The app config.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Whether accent-insensitive search is wanted; true unless switched off.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/search-accent-insensitive/specs/zoeken-filteren/spec.md#requirement-accent-folding-is-visible-to-administrators-and-can-be-bypassed
	 */
	public function enabled(): bool {
		return $this->appConfig->getValueString('openregister', self::KEY, 'true') !== 'false';
	}//end enabled()

	/**
	 * Store the choice.
	 *
	 * @param bool $enabled Whether accents are to be ignored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/search-accent-insensitive/specs/zoeken-filteren/spec.md#requirement-accent-folding-is-visible-to-administrators-and-can-be-bypassed
	 */
	public function store(bool $enabled): void {
		$value = 'false';
		if ($enabled === true) {
			$value = 'true';
		}

		$this->appConfig->setValueString('openregister', self::KEY, $value);
	}//end store()
}//end class
