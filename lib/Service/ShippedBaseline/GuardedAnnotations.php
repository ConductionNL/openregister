<?php

/**
 * GuardedAnnotations: the `x-openregister-*` annotations the shipped-baseline guard protects.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ShippedBaseline
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ShippedBaseline;

use OCA\OpenRegister\Db\Schema\AnnotationVocabulary;

/**
 * Reads and writes the guarded annotations as one `configuration` part.
 *
 * ONLY KEYS OF THE VOCABULARY. `Schema::setConfiguration()` drops an unknown
 * `x-openregister-*` key, so an unknown key guarded here would read as removed
 * on every import and conflict with itself forever. The vocabulary is exactly
 * the set of keys that survive a save, which is why it is the filter.
 *
 * ONLY ANNOTATIONS. The stored configuration also holds keys OpenRegister
 * maintains itself (`objectNameField`, ...); those are neither read into the
 * part nor touched when it is written back.
 *
 * An app's register JSON declares annotations beside `properties`, while the
 * stored schema keeps them under `configuration`. Both are read, the nested
 * form winning, the precedence `Schema::hydrate()` applies when it folds them.
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */
final class GuardedAnnotations {

	/**
	 * The part name the annotations are guarded under.
	 */
	public const PART = 'configuration';

	/**
	 * The guarded annotations of a stored configuration.
	 *
	 * @param array<string, mixed>|null $configuration The stored configuration.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function ofStored(?array $configuration): array {
		return array_filter(
			($configuration ?? []),
			static fn (int|string $key): bool => self::isGuarded(key: $key),
			ARRAY_FILTER_USE_KEY
		);
	}//end ofStored()

	/**
	 * The guarded annotations a shipped definition declares, top level or nested.
	 *
	 * @param array<string, mixed> $data The shipped definition.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function ofShipped(array $data): array {
		$nested = ($data[self::PART] ?? []);
		if (is_array($nested) === false) {
			$nested = [];
		}

		return $this->ofStored(configuration: array_replace($data, $nested));
	}//end ofShipped()

	/**
	 * Whether a shipped definition declares any guarded annotation, or a configuration at all.
	 *
	 * @param array<string, mixed> $data The shipped definition.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function isDeclaredIn(array $data): bool {
		return array_key_exists(self::PART, $data) === true || $this->ofStored(configuration: $data) !== [];
	}//end isDeclaredIn()

	/**
	 * A stored configuration with its guarded annotations replaced by these.
	 *
	 * @param array<string, mixed>|null $configuration The stored configuration.
	 * @param array<string, mixed>      $guarded       The guarded annotations to hold.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function intoStored(?array $configuration, array $guarded): array {
		$kept = array_filter(
			($configuration ?? []),
			static fn (int|string $key): bool => self::isGuarded(key: $key) === false,
			ARRAY_FILTER_USE_KEY
		);

		return array_replace($kept, $this->ofStored(configuration: $guarded));
	}//end intoStored()

	/**
	 * A shipped definition carrying these guarded annotations, nested, and no top-level copy.
	 *
	 * The top-level copies are removed: left in place, one the merge kept
	 * absent would be folded back in by `Schema::hydrate()`.
	 *
	 * @param array<string, mixed> $data    The shipped definition.
	 * @param array<string, mixed> $guarded The guarded annotations to hold.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function intoShipped(array $data, array $guarded): array {
		$nested = ($data[self::PART] ?? []);
		if (is_array($nested) === false) {
			$nested = [];
		}

		$data = array_filter($data, static fn (int|string $key): bool => self::isGuarded(key: $key) === false, ARRAY_FILTER_USE_KEY);
		$data[self::PART] = $this->intoStored(configuration: $nested, guarded: $guarded);

		return $data;
	}//end intoShipped()

	/**
	 * A baseline recorded before annotations were guarded, with the instance's own taken as shipped.
	 *
	 * Such a baseline holds no `configuration` part. Compared as it stands,
	 * every annotation the instance holds would read as a local addition and
	 * every one the app ships as a conflict with it, so no annotation update
	 * would ever land again. Taking the instance's current annotations as the
	 * baseline gives the outcome an import had before this guard existed: the
	 * app's changes land. From the next release on the baseline is real.
	 *
	 * @param array<string, mixed> $baseline The recorded baseline.
	 * @param array<string, mixed> $live     The instance's guarded parts.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function seededBaseline(array $baseline, array $live): array {
		if (array_key_exists(self::PART, $baseline) === true || array_key_exists(self::PART, $live) === false) {
			return $baseline;
		}

		$baseline[self::PART] = $live[self::PART];

		return $baseline;
	}//end seededBaseline()

	/**
	 * Whether a configuration key is a guarded annotation.
	 *
	 * @param int|string $key The key.
	 *
	 * @return bool
	 */
	private static function isGuarded(int|string $key): bool {
		return is_string($key) === true && in_array($key, AnnotationVocabulary::KEYS, true) === true;
	}//end isGuarded()
}//end class
