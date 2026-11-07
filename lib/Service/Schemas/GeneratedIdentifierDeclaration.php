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
	 * A placeholder the format renders: `{seq}`, `{seq:n}`, `{year}` or `{month}`.
	 *
	 * One expression for rendering and for reading back, so the two cannot drift.
	 *
	 * @var string
	 */
	private const PLACEHOLDER = '/\{(seq(?::(\d+))?|year|month)\}/';

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
			throw self::refusal(message: sprintf('%s at \'%s\' must be an object with a sequence and a format.', self::ANNOTATION, $path), path: $path);
		}

		self::assertStringProperty(property: $property, path: $path);
		self::assertNoUnknownKeys(raw: $raw, path: $path);
		return self::fromAnnotation(raw: $raw, path: $path);
	}//end fromProperty()

	/**
	 * Build a declaration from an annotation block whose keys are known.
	 *
	 * The sequence and format are required first, then the reset period, then
	 * whether the format can produce a unique value.
	 *
	 * @param array<string, mixed> $raw  The annotation block.
	 * @param string               $path The property's path, for the error message.
	 *
	 * @throws GeneratedIdentifierException When the declaration is malformed.
	 *
	 * @return self The validated declaration.
	 */
	private static function fromAnnotation(array $raw, string $path): self {
		$sequence = (self::required(raw: $raw, key: 'sequence') ?? throw self::missing(key: 'sequence', path: $path));
		$format = (self::required(raw: $raw, key: 'format') ?? throw self::missing(key: 'format', path: $path));
		$resetOn = (string)($raw['resetOn'] ?? self::RESET_NEVER);
		if (in_array($resetOn, [self::RESET_NEVER, self::RESET_YEAR], true) === false) {
			throw self::unknownReset(resetOn: $resetOn, path: $path);
		}

		$declaration = new self(sequence: $sequence, format: $format, resetOn: $resetOn);
		self::assertRenderable(declaration: $declaration, path: $path);
		return $declaration;
	}//end fromAnnotation()

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
	 * @param DateTimeInterface $moment The moment the object is being created.
	 *
	 * @return string The period key.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-property-declares-a-generated-identifier-from-a-sequence-and-a-format
	 */
	public function periodAt(DateTimeInterface $moment): string {
		if ($this->resetOn === self::RESET_YEAR) {
			return $moment->format('Y');
		}

		return '';

	}//end periodAt()

	/**
	 * Render one value of this identifier.
	 *
	 * @param int $sequenceValue The number taken from the counter.
	 * @param DateTimeInterface $moment The moment the object is being created.
	 *
	 * @return string The rendered identifier.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-property-declares-a-generated-identifier-from-a-sequence-and-a-format
	 */
	public function render(int $sequenceValue, DateTimeInterface $moment): string {
		return (string)preg_replace_callback(
			self::PLACEHOLDER,
			static fn (array $match): string => match ($match[1]) {
				'year' => $moment->format('Y'),
				'month' => $moment->format('m'),
				default => str_pad((string)$sequenceValue, (int)($match[2] ?? 1), '0', STR_PAD_LEFT),
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
		['pattern' => $pattern, 'groups' => $groups] = $this->readBackPattern();
		if (preg_match('/^'.$pattern.'$/', $value, $found) !== 1) {
			return null;
		}

		$captured = $this->capturedByKind(groups: $groups, found: $found);
		if (array_key_exists('seq', $captured) === false) {
			return null;
		}

		return ['sequence' => (int)$captured['seq'], 'period' => $this->periodOf(year: ($captured['year'] ?? null))];
	}//end parse()

	/**
	 * What each kind of placeholder captured; a later group of one kind wins.
	 *
	 * @param array<int, string>     $groups The kind of each capture group, in order.
	 * @param array<int|string, string> $found  The preg_match captures.
	 *
	 * @return array<string, string> The captured text by kind.
	 */
	private function capturedByKind(array $groups, array $found): array {
		$captured = [];
		foreach ($groups as $index => $kind) {
			$captured[$kind] = ($found[($index + 1)] ?? '');
		}

		return $captured;
	}//end capturedByKind()

	/**
	 * The period a parsed value belongs to.
	 *
	 * The period comes from the value itself, not from the clock: an import
	 * landing in January that carries last year's numbers must advance LAST
	 * year's counter, not this one's.
	 *
	 * @param string|null $year The year the value rendered, when it rendered one.
	 *
	 * @return string The period key.
	 */
	private function periodOf(?string $year): string {
		if ($this->resetOn === self::RESET_YEAR) {
			return (string)$year;
		}

		return '';
	}//end periodOf()

	/**
	 * Build the expression that reads a rendered value back apart.
	 *
	 * Derived from the SAME format string that renders, walked placeholder by
	 * placeholder, so the two cannot drift: a placeholder added to `render()` is
	 * added here in the same edit or neither works.
	 *
	 * The group NAMES come back beside the pattern, because a capture group's
	 * position means nothing on its own. `Z-{year}-{seq:5}` and `{seq:5}-{year}`
	 * both capture two numbers, and only the name says which is which.
	 *
	 * @return array{pattern: string, groups: array<int, string>} The expression and what each group holds.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-generated-identifier-is-frozen-after-creation
	 */
	private function readBackPattern(): array {
		[$pattern, $groups, $offset] = ['', [], 0];
		preg_match_all(self::PLACEHOLDER, $this->format, $matches, PREG_OFFSET_CAPTURE);
		foreach ($matches[0] as $index => $placeholder) {
			$name = $matches[1][$index][0];
			$pattern .= preg_quote(substr($this->format, $offset, ($placeholder[1] - $offset)), '/');
			$pattern .= $this->groupFor(name: $name, declaredPad: $matches[2][$index][0]);
			$offset = ($placeholder[1] + strlen($placeholder[0]));
			$groups[] = $this->kindOf(name: $name);
		}

		$pattern .= preg_quote(substr($this->format, $offset), '/');
		return ['pattern' => $pattern, 'groups' => $groups];
	}//end readBackPattern()

	/**
	 * The kind of a placeholder, with a padded sequence normalised to `seq`.
	 *
	 * `{seq:5}` captures as `seq:5`, so the KIND has to be normalised before it
	 * is recorded. Storing the raw capture instead leaves every padded sequence
	 * unrecognised further down, and `parse()` then answers null for a value
	 * this very format rendered.
	 *
	 * @param string $name The placeholder's name as captured.
	 *
	 * @return string `seq`, `year` or `month`.
	 */
	private function kindOf(string $name): string {
		if (str_starts_with($name, 'seq') === true) {
			return 'seq';
		}

		return $name;
	}//end kindOf()

	/**
	 * The capture group one placeholder reads back as.
	 *
	 * A padded sequence can also have GROWN past its padding, so the lower bound
	 * is the pad and there is no upper bound. Anchoring it at exactly the pad
	 * would make the hundred-thousand-and-first value of a five-digit format
	 * unparseable, and an import of it would then advance no counter at all.
	 *
	 * @param string $name The placeholder's name.
	 * @param string $declaredPad The digits after `seq:`, or an empty string.
	 *
	 * @return string The capture group.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-generated-identifier-is-frozen-after-creation
	 */
	private function groupFor(string $name, string $declaredPad): string {
		return match ($name) {
			'year' => '(\d{4})',
			'month' => '(\d{2})',
			default => '(\d{'.$this->padOf(declaredPad: $declaredPad).',})',
		};
	}//end groupFor()

	/**
	 * The least number of digits a sequence reads back as: its declared pad, else one.
	 *
	 * @param string $declaredPad The digits after `seq:`, or an empty string.
	 *
	 * @return int The pad.
	 */
	private function padOf(string $declaredPad): int {
		if ($declaredPad === '') {
			return 1;
		}

		return (int)$declaredPad;
	}//end padOf()

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

		$message = sprintf('%s at \'%s\' is declared on a \'%s\' property. A generated identifier is a string.', self::ANNOTATION, $path, $label);
		throw self::refusal(message: $message, path: $path);
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

		$message = sprintf(
			'%s at \'%s\' carries unknown key(s) \'%s\'. It accepts %s.',
			self::ANNOTATION,
			$path,
			implode(', ', $unknown),
			implode(', ', self::KNOWN_KEYS)
		);
		throw self::refusal(message: $message, path: $path);
	}//end assertNoUnknownKeys()

	/**
	 * A required key's value when it is a non-empty string, else null.
	 *
	 * @param array<string, mixed> $raw The annotation block.
	 * @param string               $key The key to read.
	 *
	 * @return string|null The value, or null when it is missing, blank or not a string.
	 */
	private static function required(array $raw, string $key): ?string {
		$value = ($raw[$key] ?? null);
		if (is_string($value) === true && trim($value) !== '') {
			return $value;
		}

		return null;
	}//end required()

	/**
	 * The refusal for a required key that is missing or empty.
	 *
	 * @param string $key  The key.
	 * @param string $path The property's path.
	 *
	 * @return GeneratedIdentifierException The refusal.
	 */
	private static function missing(string $key, string $path): GeneratedIdentifierException {
		return self::refusal(
			message: sprintf('%s.%s at \'%s\' is required and must be a non-empty string.', self::ANNOTATION, $key, $path),
			path: $path
		);
	}//end missing()

	/**
	 * The refusal for a reset period other than never or year.
	 *
	 * @param string $resetOn The declared reset period.
	 * @param string $path    The property's path.
	 *
	 * @return GeneratedIdentifierException The refusal.
	 */
	private static function unknownReset(string $resetOn, string $path): GeneratedIdentifierException {
		$message = sprintf(
			'%s.resetOn at \'%s\' is \'%s\'. It must be \'%s\' or \'%s\'.',
			self::ANNOTATION,
			$path,
			$resetOn,
			self::RESET_NEVER,
			self::RESET_YEAR
		);
		return self::refusal(message: $message, path: $path);
	}//end unknownReset()

	/**
	 * A refusal naming the property's path.
	 *
	 * @param string $message The refusal.
	 * @param string $path    The property's path.
	 *
	 * @return GeneratedIdentifierException The refusal.
	 */
	private static function refusal(string $message, string $path): GeneratedIdentifierException {
		return new GeneratedIdentifierException($message, path: $path);
	}//end refusal()

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
	 * @param self   $declaration The declaration, built but not yet handed out.
	 * @param string $path        The property's path.
	 *
	 * @throws GeneratedIdentifierException When the format cannot produce a unique value.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-property-declares-a-generated-identifier-from-a-sequence-and-a-format
	 */
	private static function assertRenderable(self $declaration, string $path): void {
		self::assertKnownPlaceholders(format: $declaration->format, path: $path);
		if (preg_match('/\{seq(?::\d+)?\}/', $declaration->format) !== 1) {
			$message = '%s.format at \'%s\' has no {seq} placeholder, so every object would get the same identifier.';
			throw self::refusal(message: sprintf($message, self::ANNOTATION, $path), path: $path);
		}

		if ($declaration->resetOn === self::RESET_YEAR && str_contains($declaration->format, '{year}') === false) {
			$message = '%s.format at \'%s\' resets each year but does not render {year}, so next year repeats this year\'s identifiers.';
			throw self::refusal(message: sprintf($message, self::ANNOTATION, $path), path: $path);
		}
	}//end assertRenderable()

	/**
	 * Refuse a placeholder the format cannot render.
	 *
	 * @param string $format The declared format.
	 * @param string $path   The property's path.
	 *
	 * @throws GeneratedIdentifierException When the format uses an unknown placeholder.
	 *
	 * @return void
	 */
	private static function assertKnownPlaceholders(string $format, string $path): void {
		preg_match_all('/\{([^}]*)\}/', $format, $matches);
		foreach ($matches[1] as $placeholder) {
			if (preg_match('/^(seq(?::\d+)?|year|month)$/', $placeholder) !== 1) {
				$message = '%s.format at \'%s\' uses unknown placeholder \'{%s}\'. It accepts {seq}, {seq:n}, {year} and {month}.';
				throw self::refusal(message: sprintf($message, self::ANNOTATION, $path, $placeholder), path: $path);
			}
		}
	}//end assertKnownPlaceholders()
}//end class
