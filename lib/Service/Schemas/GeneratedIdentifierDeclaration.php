<?php

/**
 * GeneratedIdentifierDeclaration: one reading of `x-openregister-generated`.
 *
 * The declaration is parsed once, here, and the same object answers every
 * question about it afterwards: which counter to draw from, which period keys
 * that counter, how a value renders, and what sequence number a value already
 * in hand was rendered from. Keeping all four together is the point. A format
 * that renders one way and parses another is an import that silently collides
 * with the next create, and it is invisible until two objects carry the same
 * number.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

use DateTimeInterface;

/**
 * A parsed, validated `x-openregister-generated` block.
 */
final class GeneratedIdentifierDeclaration {

	/**
	 * The annotation key a property carries.
	 *
	 * @var string
	 */
	public const ANNOTATION = 'x-openregister-generated';

	/**
	 * The counter is never reset; one running number for all time.
	 *
	 * @var string
	 */
	public const RESET_NEVER = 'never';

	/**
	 * The counter restarts each calendar year.
	 *
	 * @var string
	 */
	public const RESET_YEAR = 'year';

	/**
	 * The keys the annotation may carry.
	 *
	 * Checked rather than ignored, because a misspelled `reset` next to a
	 * working `format` is a counter that never resets and a year of case
	 * numbers that look right until the first of January.
	 *
	 * @var array<int, string>
	 */
	private const KNOWN_KEYS = ['sequence', 'format', 'resetOn'];

	/**
	 * Constructor.
	 *
	 * Private: a declaration only ever comes from {@see self::fromProperty()},
	 * so an instance that exists has been validated.
	 *
	 * @param string $sequence The counter's name.
	 * @param string $format The format, with its placeholders.
	 * @param string $resetOn When the counter restarts.
	 */
	private function __construct(
		private readonly string $sequence,
		private readonly string $format,
		private readonly string $resetOn,
	) {
	}//end __construct()

	/**
	 * Read the declaration off a property, or answer null when it carries none.
	 *
	 * @param array<string, mixed> $property The property definition.
	 * @param string $path The property's path, for the error message.
	 *
	 * @throws GeneratedIdentifierException When the declaration is malformed.
	 *
	 * @return self|null The declaration, or null when the property has none.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-property-declares-a-generated-identifier-from-a-sequence-and-a-format
	 */
	public static function fromProperty(array $property, string $path = ''): ?self {
		$raw = ($property[self::ANNOTATION] ?? null);
		if ($raw === null) {
			return null;
		}

		if (is_array($raw) === false) {
			throw new GeneratedIdentifierException(
				sprintf('%s at \'%s\' must be an object with a sequence and a format.', self::ANNOTATION, $path),
				path: $path
			);
		}

		self::assertStringProperty(property: $property, path: $path);
		self::assertNoUnknownKeys(raw: $raw, path: $path);

		$sequence = self::requireNonEmptyString(raw: $raw, key: 'sequence', path: $path);
		$format = self::requireNonEmptyString(raw: $raw, key: 'format', path: $path);
		$resetOn = (string)($raw['resetOn'] ?? self::RESET_NEVER);

		if (in_array($resetOn, [self::RESET_NEVER, self::RESET_YEAR], true) === false) {
			throw new GeneratedIdentifierException(
				sprintf(
					'%s.resetOn at \'%s\' is \'%s\'. It must be \'%s\' or \'%s\'.',
					self::ANNOTATION,
					$path,
					$resetOn,
					self::RESET_NEVER,
					self::RESET_YEAR
				),
				path: $path
			);
		}

		self::assertFormatIsRenderable(format: $format, resetOn: $resetOn, path: $path);

		return new self(sequence: $sequence, format: $format, resetOn: $resetOn);

	}//end fromProperty()

	/**
	 * The counter's name.
	 *
	 * Named rather than derived from the schema, because two schemas naming the
	 * same counter is how they come to share one.
	 *
	 * @return string The sequence name.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-two-schemas-may-share-one-sequence
	 */
	public function sequence(): string {
		return $this->sequence;

	}//end sequence()

	/**
	 * The format, as declared.
	 *
	 * @return string The format string.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-property-declares-a-generated-identifier-from-a-sequence-and-a-format
	 */
	public function format(): string {
		return $this->format;

	}//end format()

	/**
	 * The period that keys the counter at a given moment.
	 *
	 * An empty string for `resetOn: never`, the four-digit year for
	 * `resetOn: year`. The period is part of the counter's identity, which is
	 * what makes `Z-2026-00001` and `Z-2027-00001` two different first values
	 * rather than one collision.
	 *
	 * @param DateTimeInterface $at The moment the object is being created.
	 *
	 * @return string The period key.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-property-declares-a-generated-identifier-from-a-sequence-and-a-format
	 */
	public function periodAt(DateTimeInterface $at): string {
		if ($this->resetOn === self::RESET_YEAR) {
			return $at->format('Y');
		}

		return '';

	}//end periodAt()

	/**
	 * Render one value of this identifier.
	 *
	 * @param int $sequenceValue The number taken from the counter.
	 * @param DateTimeInterface $at The moment the object is being created.
	 *
	 * @return string The rendered identifier.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-property-declares-a-generated-identifier-from-a-sequence-and-a-format
	 */
	public function render(int $sequenceValue, DateTimeInterface $at): string {
		return (string)preg_replace_callback(
			'/\{(seq(?::(\d+))?|year|month)\}/',
			static function (array $match) use ($sequenceValue, $at): string {
				if ($match[1] === 'year') {
					return $at->format('Y');
				}

				if ($match[1] === 'month') {
					return $at->format('m');
				}

				$pad = (int)($match[2] ?? 1);

				return str_pad((string)$sequenceValue, $pad, '0', STR_PAD_LEFT);
			},
			$this->format
		);

	}//end render()

	/**
	 * Read back the sequence number and period a value was rendered from.
	 *
	 * This is what lets an import advance the counter past the values it
	 * supplied. Without it, importing `Z-2026-00120` leaves the counter at zero
	 * and the next create issues `Z-2026-00001`, which collides on the
	 * hundred-and-twentieth create rather than the first, so the bug ships.
	 *
	 * The pattern is built from the SAME format that renders, so the two cannot
	 * drift: a placeholder added to one is added to the other.
	 *
	 * @param string $value A value already in hand.
	 *
	 * @return array{sequence: int, period: string}|null The parsed parts, or null when the value does not match.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-generated-identifier-is-frozen-after-creation
	 */
	public function parse(string $value): ?array {
		$pattern = '';
		$groups = [];
		$offset = 0;

		preg_match_all('/\{(seq(?::(\d+))?|year|month)\}/', $this->format, $matches, PREG_OFFSET_CAPTURE);
		foreach ($matches[0] as $index => $placeholder) {
			$pattern .= preg_quote(substr($this->format, $offset, ($placeholder[1] - $offset)), '/');
			$offset = ($placeholder[1] + strlen($placeholder[0]));

			$name = $matches[1][$index][0];
			if ($name === 'year') {
				$pattern .= '(\d{4})';
				$groups[] = 'year';
				continue;
			}

			if ($name === 'month') {
				$pattern .= '(\d{2})';
				$groups[] = 'month';
				continue;
			}

			// A padded sequence can also have grown past its padding, so the
			// lower bound is the pad and there is no upper bound.
			$declaredPad = $matches[2][$index][0];
			$pad = 1;
			if ($declaredPad !== '') {
				$pad = (int)$declaredPad;
			}

			$pattern .= '(\d{'.$pad.',})';
			$groups[] = 'seq';
		}//end foreach

		$pattern .= preg_quote(substr($this->format, $offset), '/');

		if (preg_match('/^'.$pattern.'$/', $value, $found) !== 1) {
			return null;
		}

		$sequence = null;
		$year = null;
		foreach ($groups as $index => $name) {
			$captured = ($found[($index + 1)] ?? '');
			if ($name === 'seq') {
				$sequence = (int)$captured;
			}

			if ($name === 'year') {
				$year = $captured;
			}
		}

		if ($sequence === null) {
			return null;
		}

		// The period comes from the value itself, not from the clock: an import
		// landing in January that carries last year's numbers must advance LAST
		// year's counter, not this one's.
		$period = '';
		if ($this->resetOn === self::RESET_YEAR) {
			$period = (string)$year;
		}

		return ['sequence' => $sequence, 'period' => $period];

	}//end parse()

	/**
	 * Refuse a declaration on a property that is not a string.
	 *
	 * @param array<string, mixed> $property The property definition.
	 * @param string $path The property's path.
	 *
	 * @throws GeneratedIdentifierException When the property is not a string.
	 *
	 * @return void
	 */
	private static function assertStringProperty(array $property, string $path): void {
		$type = ($property['type'] ?? null);
		if ($type === 'string') {
			return;
		}

		$label = $type;
		if (is_string($label) === false) {
			$label = (string)json_encode($label);
		}

		throw new GeneratedIdentifierException(
			sprintf(
				'%s at \'%s\' is declared on a \'%s\' property. A generated identifier is a string.',
				self::ANNOTATION,
				$path,
				$label
			),
			path: $path
		);

	}//end assertStringProperty()

	/**
	 * Refuse a key the annotation does not define.
	 *
	 * @param array<string, mixed> $raw The annotation block.
	 * @param string $path The property's path.
	 *
	 * @throws GeneratedIdentifierException When an unknown key is present.
	 *
	 * @return void
	 */
	private static function assertNoUnknownKeys(array $raw, string $path): void {
		$unknown = array_diff(array_map('strval', array_keys($raw)), self::KNOWN_KEYS);
		if ($unknown === []) {
			return;
		}

		throw new GeneratedIdentifierException(
			sprintf(
				'%s at \'%s\' carries unknown key(s) \'%s\'. It accepts %s.',
				self::ANNOTATION,
				$path,
				implode(', ', $unknown),
				implode(', ', self::KNOWN_KEYS)
			),
			path: $path
		);

	}//end assertNoUnknownKeys()

	/**
	 * Read a required non-empty string off the annotation.
	 *
	 * @param array<string, mixed> $raw The annotation block.
	 * @param string $key The key to read.
	 * @param string $path The property's path.
	 *
	 * @throws GeneratedIdentifierException When the key is missing or empty.
	 *
	 * @return string The value.
	 */
	private static function requireNonEmptyString(array $raw, string $key, string $path): string {
		$value = ($raw[$key] ?? null);
		if (is_string($value) === true && trim($value) !== '') {
			return $value;
		}

		throw new GeneratedIdentifierException(
			sprintf('%s.%s at \'%s\' is required and must be a non-empty string.', self::ANNOTATION, $key, $path),
			path: $path
		);

	}//end requireNonEmptyString()

	/**
	 * Refuse a format that cannot produce a unique value.
	 *
	 * Three refusals, each for a value that would look right and collide:
	 *
	 * - An unknown placeholder renders as itself, so every object gets the
	 *   same literal text where its number should be.
	 * - A format with no `{seq}` has nothing that varies, so every object gets
	 *   the same identifier.
	 * - `resetOn: year` without `{year}` restarts the counter each January
	 *   while rendering the same strings as last year, which is the worst of
	 *   the three: it works for a year.
	 *
	 * @param string $format The declared format.
	 * @param string $resetOn The declared reset period.
	 * @param string $path The property's path.
	 *
	 * @throws GeneratedIdentifierException When the format cannot produce a unique value.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-property-declares-a-generated-identifier-from-a-sequence-and-a-format
	 */
	private static function assertFormatIsRenderable(string $format, string $resetOn, string $path): void {
		preg_match_all('/\{([^}]*)\}/', $format, $matches);
		foreach ($matches[1] as $placeholder) {
			if (preg_match('/^(seq(?::\d+)?|year|month)$/', $placeholder) !== 1) {
				throw new GeneratedIdentifierException(
					sprintf(
						'%s.format at \'%s\' uses unknown placeholder \'{%s}\'. It accepts {seq}, {seq:n}, {year} and {month}.',
						self::ANNOTATION,
						$path,
						$placeholder
					),
					path: $path
				);
			}
		}

		if (preg_match('/\{seq(?::\d+)?\}/', $format) !== 1) {
			throw new GeneratedIdentifierException(
				sprintf(
					'%s.format at \'%s\' has no {seq} placeholder, so every object would get the same identifier.',
					self::ANNOTATION,
					$path
				),
				path: $path
			);
		}

		if ($resetOn === self::RESET_YEAR && str_contains($format, '{year}') === false) {
			throw new GeneratedIdentifierException(
				sprintf(
					'%s.format at \'%s\' resets each year but does not render {year}, so next year repeats this year\'s identifiers.',
					self::ANNOTATION,
					$path
				),
				path: $path
			);
		}

	}//end assertFormatIsRenderable()
}//end class
