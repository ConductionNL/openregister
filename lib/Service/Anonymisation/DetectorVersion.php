<?php

/**
 * OpenRegister Anonymisation Detector Version
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  ValueObject
 * @package   OCA\OpenRegister\Service\Anonymisation
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-every-detection-backend-reports-what-it-is-built-from-req-adi-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Anonymisation;

use JsonSerializable;

/**
 * What a detection backend is built from: its engine, model, pattern set and
 * datasets, each with a version, and when that combination last changed.
 *
 * A value the backend does not expose is the string `unknown`, never left
 * out, so a reader can tell "not reported" from "not asked".
 *
 * @category ValueObject
 * @package  OCA\OpenRegister\Service\Anonymisation
 *
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-every-detection-backend-reports-what-it-is-built-from-req-adi-001
 */
final class DetectorVersion implements JsonSerializable {

	/**
	 * The value of anything a backend does not expose.
	 */
	public const UNKNOWN = 'unknown';

	/**
	 * The engine, e.g. `openregister-patterns`, `presidio` or an ExApp id.
	 *
	 * @var string
	 */
	public readonly string $engine;

	/**
	 * The engine's version.
	 *
	 * @var string
	 */
	public readonly string $engineVersion;

	/**
	 * The recognition model.
	 *
	 * @var string
	 */
	public readonly string $model;

	/**
	 * The model's version.
	 *
	 * @var string
	 */
	public readonly string $modelVersion;

	/**
	 * The version of the pattern set the backend applies.
	 *
	 * @var string
	 */
	public readonly string $patternSetVersion;

	/**
	 * The named datasets the backend was built or evaluated with.
	 *
	 * @var array<int, array{name: string, version: string}>
	 */
	public readonly array $datasets;

	/**
	 * When this combination last changed, or `unknown`.
	 *
	 * @var string
	 */
	public readonly string $changedAt;

	/**
	 * Build a record; an empty value reads as `unknown`.
	 *
	 * @param array<string, mixed> $fields engine, engineVersion, model, modelVersion,
	 *                                     patternSetVersion, datasets and changedAt.
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-every-detection-backend-reports-what-it-is-built-from-req-adi-001
	 */
	public function __construct(array $fields) {
		$this->engine            = self::text(value: ($fields['engine'] ?? null));
		$this->engineVersion     = self::text(value: ($fields['engineVersion'] ?? null));
		$this->model             = self::text(value: ($fields['model'] ?? null));
		$this->modelVersion      = self::text(value: ($fields['modelVersion'] ?? null));
		$this->patternSetVersion = self::text(value: ($fields['patternSetVersion'] ?? null));
		$this->datasets          = self::datasetList(value: ($fields['datasets'] ?? null));
		$this->changedAt         = self::text(value: ($fields['changedAt'] ?? null));
	}//end __construct()

	/**
	 * The same record, changed at the given moment.
	 *
	 * @param string $changedAt The moment.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-the-detector-says-when-it-last-changed-req-adi-004
	 */
	public function withChangedAt(string $changedAt): self {
		return new self(fields: array_merge($this->jsonSerialize(), ['changedAt' => $changedAt]));
	}//end withChangedAt()

	/**
	 * Whether two records describe the same detector, whatever their `changedAt`.
	 *
	 * @param DetectorVersion $other The other record.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-the-detector-says-when-it-last-changed-req-adi-004
	 */
	public function sameDetectorAs(DetectorVersion $other): bool {
		$mine   = $this->jsonSerialize();
		$theirs = $other->jsonSerialize();
		unset($mine['changedAt'], $theirs['changedAt']);

		return $mine === $theirs;
	}//end sameDetectorAs()

	/**
	 * The record with every key present.
	 *
	 * @return array{engine: string, engineVersion: string, model: string, modelVersion: string, patternSetVersion: string, datasets: array<int, array{name: string, version: string}>, changedAt: string}
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-every-detection-backend-reports-what-it-is-built-from-req-adi-001
	 */
	public function jsonSerialize(): array {
		return [
			'engine' => $this->engine,
			'engineVersion' => $this->engineVersion,
			'model' => $this->model,
			'modelVersion' => $this->modelVersion,
			'patternSetVersion' => $this->patternSetVersion,
			'datasets' => $this->datasets,
			'changedAt' => $this->changedAt,
		];
	}//end jsonSerialize()

	/**
	 * A value as text, or `unknown` when it is empty or not a scalar.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private static function text(mixed $value): string {
		if (is_scalar($value) === false) {
			return self::UNKNOWN;
		}

		$text = trim((string)$value);
		if ($text === '') {
			return self::UNKNOWN;
		}

		return $text;
	}//end text()

	/**
	 * The named datasets, each with a name and a version; anything else is left out.
	 *
	 * @param mixed $value The datasets as given.
	 *
	 * @return array<int, array{name: string, version: string}>
	 */
	private static function datasetList(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$datasets = [];
		foreach ($value as $dataset) {
			if (is_array($dataset) === false || self::text(value: ($dataset['name'] ?? null)) === self::UNKNOWN) {
				continue;
			}

			$datasets[] = [
				'name' => self::text(value: $dataset['name']),
				'version' => self::text(value: ($dataset['version'] ?? null)),
			];
		}

		return $datasets;
	}//end datasetList()
}//end class
