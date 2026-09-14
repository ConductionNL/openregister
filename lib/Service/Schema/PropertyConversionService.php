<?php

/**
 * OpenRegister PropertyConversionService
 *
 * Changing a property's type on objects that already hold values is
 * dangerous, so the honest answer is a short list of supported conversions, a
 * preview over the stored values, and a refusal with a reason for everything
 * else (design.md D-6).
 *
 * The product's job is to say what a conversion would cost BEFORE the save,
 * not after. "3,988 of 4,000 convert, these 12 do not" is a decision someone
 * can take. A silent best-effort conversion that nulls twelve values is a
 * data-loss incident discovered months later by the person who needed one of
 * them.
 *
 * Our own notes already record that adding a `format` to an existing property
 * is breaking while adding a property is not. This class is where that kind of
 * knowledge stops being a note and becomes something the API will tell you.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schema
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/runtime-schema-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schema;

use DateTimeImmutable;
use Throwable;

/**
 * Publishes the supported property-type conversions and previews one.
 */
class PropertyConversionService {

	/**
	 * How many non-convertible values a preview names.
	 *
	 * A preview that names every failure on a four-million-row table is a
	 * response nobody reads. Ten is enough to recognise the pattern, and the
	 * count is exact regardless.
	 *
	 * @var integer
	 */
	public const SAMPLE_SIZE = 10;

	/**
	 * The conversions the system supports, keyed by source type.
	 *
	 * Deliberately short. Every entry here is a conversion whose failure mode
	 * is detectable per value, which is what makes a preview possible. Nothing
	 * involving a file, a relation or a nested object is on it, because for
	 * those there is no per-value test that answers honestly.
	 *
	 * @var array<string,array<int,string>>
	 */
	public const SUPPORTED = [
		'string' => ['number', 'integer', 'boolean', 'date', 'datetime'],
		'integer' => ['number', 'string'],
		'number' => ['string'],
		'boolean' => ['string'],
		'date' => ['string', 'datetime'],
		'datetime' => ['string', 'date'],
	];

	/**
	 * The published conversion matrix.
	 *
	 * @return array<int,array{from:string,to:array<int,string>}> The supported conversions.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/runtime-schema-api/spec.md
	 */
	public function supported(): array {
		$published = [];
		foreach (self::SUPPORTED as $from => $targets) {
			$published[] = ['from' => (string)$from, 'to' => $targets];
		}

		return $published;
	}//end supported()

	/**
	 * Whether one conversion is on the published list.
	 *
	 * A conversion to the same type is trivially supported and converts every
	 * value, which keeps a no-op request from being refused as unsupported.
	 *
	 * @param string $from The current type.
	 * @param string $to The requested type.
	 *
	 * @return boolean True when the conversion may be attempted.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/runtime-schema-api/spec.md
	 */
	public function isSupported(string $from, string $to): bool {
		if ($from === $to) {
			return true;
		}

		return in_array($to, (self::SUPPORTED[$from] ?? []), true);
	}//end isSupported()

	/**
	 * The reason an unsupported conversion is refused.
	 *
	 * @param string $from The current type.
	 * @param string $to The requested type.
	 *
	 * @return string The reason.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/runtime-schema-api/spec.md
	 */
	public function refusalReason(string $from, string $to): string {
		$targets = (self::SUPPORTED[$from] ?? []);
		if ($targets === []) {
			return sprintf(
				'A property of type "%s" cannot be converted to another type, because there is no per-value test that says honestly whether a value would survive it.',
				$from
			);
		}

		return sprintf(
			'A property of type "%s" cannot be converted to "%s". The supported targets are: %s.',
			$from,
			$to,
			implode(', ', $targets)
		);
	}//end refusalReason()

	/**
	 * Preview a conversion over a set of stored values.
	 *
	 * @param array<int,mixed> $values The stored values, one per object.
	 * @param string $from The current type.
	 * @param string $to The requested type.
	 *
	 * @return array{supported:bool,total:int,convertible:int,rejected:int,samples:array<int,array{value:string,reason:string}>,reason:string|null}
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/runtime-schema-api/spec.md
	 */
	public function preview(array $values, string $from, string $to): array {
		if ($this->isSupported(from: $from, to: $to) === false) {
			return [
				'supported' => false,
				'total' => count($values),
				'convertible' => 0,
				'rejected' => count($values),
				'samples' => [],
				'reason' => $this->refusalReason(from: $from, to: $to),
			];
		}

		$convertible = 0;
		$rejected = 0;
		$samples = [];

		foreach ($values as $value) {
			// A value that is simply absent is not a conversion failure: the
			// property stays empty, whatever its type. Counting it as a
			// rejection would make every optional property look unconvertible.
			if ($value === null || $value === '') {
				$convertible++;
				continue;
			}

			if ($this->converts(value: $value, to: $to) === true) {
				$convertible++;
				continue;
			}

			$rejected++;
			if (count($samples) < self::SAMPLE_SIZE) {
				$samples[] = [
					'value' => $this->readable(value: $value),
					'reason' => sprintf('Not a valid %s.', $to),
				];
			}
		}//end foreach

		return [
			'supported' => true,
			'total' => count($values),
			'convertible' => $convertible,
			'rejected' => $rejected,
			'samples' => $samples,
			'reason' => null,
		];
	}//end preview()

	/**
	 * Whether one value survives the conversion.
	 *
	 * @param mixed $value The stored value.
	 * @param string $to The requested type.
	 *
	 * @return boolean True when the value converts.
	 */
	private function converts(mixed $value, string $to): bool {
		return match ($to) {
			'number' => is_numeric($value),
			'integer' => (is_numeric($value) === true && (float)$value === floor((float)$value)),
			'boolean' => $this->convertsToBoolean(value: $value),
			'string' => is_scalar($value),
			'date', 'datetime' => $this->convertsToDate(value: $value),
			default => false,
		};
	}//end converts()

	/**
	 * Whether a value is one of the literals a boolean accepts.
	 *
	 * Deliberately strict. Accepting any non-empty string as `true` is how a
	 * column of free text silently becomes a column of `true`.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return boolean True when the value converts to a boolean.
	 */
	private function convertsToBoolean(mixed $value): bool {
		if (is_bool($value) === true) {
			return true;
		}

		if (is_int($value) === true) {
			return ($value === 0 || $value === 1);
		}

		if (is_string($value) === false) {
			return false;
		}

		return in_array(
			strtolower(trim($value)),
			['true', 'false', '1', '0', 'yes', 'no', 'ja', 'nee'],
			true
		);
	}//end convertsToBoolean()

	/**
	 * Whether a value parses as a date.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return boolean True when the value converts to a date.
	 */
	private function convertsToDate(mixed $value): bool {
		if (is_string($value) === false || trim($value) === '') {
			return false;
		}

		try {
			new DateTimeImmutable($value);
		} catch (Throwable $unparseable) {
			return false;
		}

		return true;
	}//end convertsToDate()

	/**
	 * A value rendered for a preview sample.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string The rendering, truncated.
	 */
	private function readable(mixed $value): string {
		$rendered = (string)json_encode($value);
		if (is_scalar($value) === true) {
			$rendered = (string)$value;
		}

		if (mb_strlen($rendered) > 120) {
			return (mb_substr($rendered, 0, 117) . '...');
		}

		return $rendered;
	}//end readable()
}//end class
