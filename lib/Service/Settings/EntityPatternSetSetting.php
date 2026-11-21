<?php

/**
 * OpenRegister EntityPatternSetSetting
 *
 * The `entityPatternSets` file setting: its default and its validation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Settings
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

namespace OCA\OpenRegister\Service\Settings;

use OCA\OpenRegister\Exception\UnknownPatternSetException;
use OCA\OpenRegister\Files\Service\TextExtraction\PatternSet\JurisdictionPatternSets;
use OCP\IConfig;

/**
 * Which jurisdiction pattern sets detection runs.
 *
 * Never saved, the setting follows the instance's `default_phone_region`: the
 * set of that country when one exists (`NL` gives `["nl"]`), none otherwise,
 * so an instance outside the Netherlands never matches a Dutch plate by
 * accident. A saved list of codes is kept as saved; an unknown code is refused
 * naming it.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) JurisdictionPatternSets::codes() and ::unknown() read the registry constant.
 *
 * @spec openspec/changes/detection-dutch-licence-plates/specs/pii-entity-detection/spec.md
 */
class EntityPatternSetSetting {

	/**
	 * The key in the file settings.
	 *
	 * @var string
	 */
	public const SETTINGS_KEY = 'entityPatternSets';

	/**
	 * Constructor.
	 *
	 * @param IConfig|null $config The system config, for `default_phone_region`; none gives no default set.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/detection-dutch-licence-plates/specs/pii-entity-detection/spec.md
	 */
	public function __construct(
		private readonly ?IConfig $config=null,
	) {
	}//end __construct()

	/**
	 * The codes when none were ever saved: the phone region's set, if one exists.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/detection-dutch-licence-plates/specs/pii-entity-detection/spec.md
	 */
	public function defaultCodes(): array {
		$region = strtolower(trim((string) ($this->config?->getSystemValueString('default_phone_region', '') ?? '')));
		if ($region !== '' && in_array($region, JurisdictionPatternSets::codes(), true) === true) {
			return [$region];
		}

		return [];
	}//end defaultCodes()

	/**
	 * The codes stored in the settings, or the default when none were saved.
	 *
	 * @param array<string, mixed> $stored The stored file settings.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/detection-dutch-licence-plates/specs/pii-entity-detection/spec.md
	 */
	public function read(array $stored): array {
		if (array_key_exists(self::SETTINGS_KEY, $stored) === false || is_array($stored[self::SETTINGS_KEY]) === false) {
			return $this->defaultCodes();
		}

		return array_values(array_filter($stored[self::SETTINGS_KEY], 'is_string'));
	}//end read()

	/**
	 * A submitted list of codes, validated.
	 *
	 * @param mixed $codes The submitted value.
	 *
	 * @return array<int, string> The codes, lower case and without duplicates.
	 *
	 * @throws UnknownPatternSetException When the value is not a list, or names a code no set has.
	 *
	 * @spec openspec/changes/detection-dutch-licence-plates/specs/pii-entity-detection/spec.md
	 */
	public function submitted(mixed $codes): array {
		if (is_array($codes) === false) {
			throw new UnknownPatternSetException(message: self::SETTINGS_KEY.' must be a list of pattern set codes.');
		}

		$codes   = array_map(self::normalised(...), array_values($codes));
		$unknown = JurisdictionPatternSets::unknown(codes: $codes);
		if ($unknown !== []) {
			$known = implode(separator: ', ', array: JurisdictionPatternSets::codes());
			throw new UnknownPatternSetException(
				message: 'Unknown pattern set: '.implode(separator: ', ', array: $unknown).'. Known: '.$known.'.'
			);
		}

		return array_values(array_unique($codes));
	}//end submitted()

	/**
	 * A submitted code lower-cased and trimmed; anything but a string as given.
	 *
	 * @param mixed $code The submitted code.
	 *
	 * @return mixed
	 */
	private static function normalised(mixed $code): mixed {
		if (is_string($code) === false) {
			return $code;
		}

		return strtolower(trim($code));
	}//end normalised()
}//end class
