<?php

/**
 * OpenRegister Anonymisation Backend Statement
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
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-training-use-and-retention-are-stated-per-backend-req-adi-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Anonymisation;

use JsonSerializable;

/**
 * What a detection backend does with the text it is sent: whether the text may
 * be used to train the vendor's model, and what the service retains.
 *
 * The pattern backend and the internal OpenAnonymiser ExApp run on this
 * server, so the product declares them `excluded` and `none` and an
 * administrator cannot change that. Every other backend is a service the
 * organisation chose, so an administrator states it; until someone does it
 * reads `unknown`. A stored value that is not one of the allowed forms reads
 * as `unknown` too: an unreadable statement must never pass for a stated one.
 *
 * @category ValueObject
 * @package  OCA\OpenRegister\Service\Anonymisation
 *
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-training-use-and-retention-are-stated-per-backend-req-adi-003
 */
final class BackendStatement implements JsonSerializable {

	/**
	 * The anonymisation settings key holding the statements per backend.
	 */
	public const SETTINGS_KEY = 'statements';

	/**
	 * Submitted text is never used for training.
	 */
	public const TRAINING_EXCLUDED = 'excluded';

	/**
	 * Submitted text may be used for training.
	 */
	public const TRAINING_PERMITTED = 'permitted';

	/**
	 * Not stated.
	 */
	public const UNKNOWN = 'unknown';

	/**
	 * The service retains nothing.
	 */
	public const RETENTION_NONE = 'none';

	/**
	 * Declared by the product, not editable.
	 */
	public const DECLARED_BY_PRODUCT = 'product';

	/**
	 * Stated, or still to be stated, by an administrator.
	 */
	public const DECLARED_BY_ADMINISTRATOR = 'administrator';

	/**
	 * The allowed training-use values.
	 */
	private const TRAINING_VALUES = [self::TRAINING_EXCLUDED, self::TRAINING_PERMITTED, self::UNKNOWN];

	/**
	 * An ISO 8601 duration with at least one part, such as P30D or PT12H.
	 */
	private const DURATION = '/^P(?!$)(\d+Y)?(\d+M)?(\d+W)?(\d+D)?(T(?=\d)(\d+H)?(\d+M)?(\d+S)?)?$/';

	/**
	 * Build a statement.
	 *
	 * @param string $trainingUse     excluded, permitted or unknown.
	 * @param string $retention       none, an ISO 8601 duration, or unknown.
	 * @param bool   $productDeclared Whether the product declares it.
	 */
	private function __construct(
		public readonly string $trainingUse,
		public readonly string $retention,
		public readonly bool $productDeclared,
	) {
	}//end __construct()

	/**
	 * Whether the product declares a backend's statement.
	 *
	 * @param string $backend              The backend method.
	 * @param string $openAnonymiserSource internal (the ExApp) or external (an operator URL).
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-training-use-and-retention-are-stated-per-backend-req-adi-003
	 */
	public static function isProductDeclared(string $backend, string $openAnonymiserSource): bool {
		if ($backend === BackendState::METHOD_REGEX) {
			return true;
		}

		return ($backend === BackendState::METHOD_OPENANONYMISER && $openAnonymiserSource !== 'external');
	}//end isProductDeclared()

	/**
	 * The statement of a backend: the product's, or the stored one.
	 *
	 * @param string $backend              The backend method.
	 * @param string $openAnonymiserSource internal (the ExApp) or external (an operator URL).
	 * @param mixed  $stored               The stored statement of this backend, if any.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-training-use-and-retention-are-stated-per-backend-req-adi-003
	 */
	public static function forBackend(string $backend, string $openAnonymiserSource, mixed $stored): self {
		if (self::isProductDeclared(backend: $backend, openAnonymiserSource: $openAnonymiserSource) === true) {
			return new self(trainingUse: self::TRAINING_EXCLUDED, retention: self::RETENTION_NONE, productDeclared: true);
		}

		return self::stated(stored: $stored);
	}//end forBackend()

	/**
	 * An administrator's statement, each value `unknown` unless it is an allowed form.
	 *
	 * @param mixed $stored The stored or submitted statement.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-training-use-and-retention-are-stated-per-backend-req-adi-003
	 */
	public static function stated(mixed $stored): self {
		$trainingUse = self::UNKNOWN;
		$retention = self::UNKNOWN;
		if (is_array($stored) === true) {
			$trainingUse = self::trainingUseOf(value: ($stored['trainingUse'] ?? null));
			$retention = self::retentionOf(value: ($stored['retention'] ?? null));
		}

		return new self(trainingUse: $trainingUse, retention: $retention, productDeclared: false);
	}//end stated()

	/**
	 * The two values as stored.
	 *
	 * @return array{trainingUse: string, retention: string}
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-training-use-and-retention-are-stated-per-backend-req-adi-003
	 */
	public function toStored(): array {
		return ['trainingUse' => $this->trainingUse, 'retention' => $this->retention];
	}//end toStored()

	/**
	 * Both values and who declares them.
	 *
	 * @return array{trainingUse: string, retention: string, declaredBy: string}
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-training-use-and-retention-are-stated-per-backend-req-adi-003
	 */
	public function jsonSerialize(): array {
		$declaredBy = self::DECLARED_BY_ADMINISTRATOR;
		if ($this->productDeclared === true) {
			$declaredBy = self::DECLARED_BY_PRODUCT;
		}

		return array_merge($this->toStored(), ['declaredBy' => $declaredBy]);
	}//end jsonSerialize()

	/**
	 * A training-use value, or `unknown`.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private static function trainingUseOf(mixed $value): string {
		if (is_string($value) === true && in_array($value, self::TRAINING_VALUES, true) === true) {
			return $value;
		}

		return self::UNKNOWN;
	}//end trainingUseOf()

	/**
	 * A retention value (none, an ISO 8601 duration), or `unknown`.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private static function retentionOf(mixed $value): string {
		if (is_string($value) === false) {
			return self::UNKNOWN;
		}

		$value = trim($value);
		if ($value === self::RETENTION_NONE || preg_match(self::DURATION, $value) === 1) {
			return $value;
		}

		return self::UNKNOWN;
	}//end retentionOf()
}//end class
