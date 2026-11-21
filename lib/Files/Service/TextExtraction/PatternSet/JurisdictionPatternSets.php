<?php

/**
 * OpenRegister JurisdictionPatternSets
 *
 * The known jurisdiction pattern sets, and how their matches join a backend's.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TextExtraction\PatternSet
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

namespace OCA\OpenRegister\Files\Service\TextExtraction\PatternSet;

use OCA\OpenRegister\Files\Service\TextExtraction\EntityDetectionQuery;
use OCA\OpenRegister\Files\Service\TextExtraction\EntityRecognitionHandler;

/**
 * The registry of jurisdiction pattern sets by code, and the merge that runs
 * the enabled ones after whichever detection method ran.
 *
 * Which backend and which country are independent choices: the sets run under
 * regex, Presidio, OpenAnonymiser, LLM and hybrid alike. Where the backend
 * already returned an entity for an overlapping span, the backend's entity is
 * kept and the set's dropped, so a backend keeps its type and confidence. The
 * one exception is the generic regex PHONE pattern, which matches any run of
 * digits, including the `12` of `12-GBK-3`: a span a set proved with its
 * validator (a BSN by the elfproef, a plate by its sidecode) is not a phone
 * number, so under the regex method that PHONE gives way.
 *
 * @spec openspec/changes/detection-dutch-licence-plates/specs/pii-entity-detection/spec.md
 */
class JurisdictionPatternSets {

	/**
	 * The known sets by code (ISO 3166-1 alpha-2, lower case).
	 *
	 * @var array<string, class-string<JurisdictionPatternSet>>
	 */
	public const SETS = [
		'nl' => NlPatternSet::class,
	];

	/**
	 * The sets built so far, by code.
	 *
	 * @var array<string, JurisdictionPatternSet>
	 */
	private array $built = [];

	/**
	 * The known codes.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/detection-dutch-licence-plates/specs/pii-entity-detection/spec.md
	 */
	public static function codes(): array {
		return array_keys(self::SETS);
	}//end codes()

	/**
	 * The codes of a list that name no known set.
	 *
	 * @param array<int, mixed> $codes The codes.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/detection-dutch-licence-plates/specs/pii-entity-detection/spec.md
	 */
	public static function unknown(array $codes): array {
		return array_values(
			array_map(
				'strval',
				array_filter($codes, static fn (mixed $code): bool => is_string($code) === false || isset(self::SETS[$code]) === false)
			)
		);
	}//end unknown()

	/**
	 * The backend's entities with the enabled sets' matches merged in.
	 *
	 * @param array<int, array<string, mixed>> $entities The backend's entities.
	 * @param EntityDetectionQuery             $query    The text, the type filter and the confidence threshold.
	 * @param array<int, string>               $codes    The enabled set codes; unknown codes are ignored.
	 * @param bool                             $isRegex  Whether the backend was the generic regex method.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/detection-dutch-licence-plates/specs/pii-entity-detection/spec.md
	 */
	public function merge(array $entities, EntityDetectionQuery $query, array $codes, bool $isRegex): array {
		$found = $this->found(query: $query, codes: $codes);
		if ($found === []) {
			return array_values($entities);
		}

		if ($isRegex === true) {
			$entities = self::without(entities: $entities, covered: $found, type: EntityRecognitionHandler::ENTITY_TYPE_PHONE);
		}

		return array_merge(array_values($entities), self::without(entities: $found, covered: $entities, type: null));
	}//end merge()

	/**
	 * The enabled sets' matches the query wants, at or above its threshold.
	 *
	 * @param EntityDetectionQuery $query The query.
	 * @param array<int, string>   $codes The enabled set codes.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function found(EntityDetectionQuery $query, array $codes): array {
		$found = [];
		foreach (array_unique($codes) as $code) {
			$set = $this->set(code: $code);
			if ($set === null) {
				continue;
			}

			foreach ($set->detect(text: $query->text) as $entity) {
				if ($query->wants(type: $entity['type']) === true && $entity['confidence'] >= $query->confidenceThreshold) {
					$found[] = $entity;
				}
			}
		}

		return $found;
	}//end found()

	/**
	 * The set of a code, built once, or null for an unknown code.
	 *
	 * @param string $code The code.
	 *
	 * @return JurisdictionPatternSet|null
	 */
	private function set(string $code): ?JurisdictionPatternSet {
		$class = (self::SETS[$code] ?? null);
		if ($class === null) {
			return null;
		}

		if (isset($this->built[$code]) === false) {
			$this->built[$code] = new $class();
		}

		return $this->built[$code];
	}//end set()

	/**
	 * The entities (of one type, or of any type when null) that overlap none of the covered spans.
	 *
	 * @param array<int, array<string, mixed>> $entities The entities filtered.
	 * @param array<int, array<string, mixed>> $covered  The entities whose spans win.
	 * @param string|null                      $type     Only entities of this type are dropped; null drops any.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function without(array $entities, array $covered, ?string $type): array {
		return array_values(
			array_filter(
				$entities,
				static function (array $entity) use ($covered, $type): bool {
					if ($type !== null && $entity['type'] !== $type) {
						return true;
					}

					foreach ($covered as $other) {
						if ($entity['position_start'] < $other['position_end'] && $other['position_start'] < $entity['position_end']) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}//end without()
}//end class
