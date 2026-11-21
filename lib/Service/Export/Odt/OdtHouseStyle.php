<?php

/**
 * OdtHouseStyle — the parts of a document house style the ODT writer applies.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Built from thematiq's document style profile (see DocumentStyleReader).
 * Every value is checked before it reaches the ODT: a colour must be a hex
 * colour, a footer line a non-empty string, and anything else is dropped, so
 * a malformed profile can never fail an export or inject markup.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export\Odt
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/export-pdf-house-style/specs/export-pdf-format/spec.md#requirement-a-pdf-export-carries-the-organisations-house-style
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Export\Odt;

/**
 * The table header colours and footer lines of a house style.
 *
 * @spec openspec/changes/export-pdf-house-style/specs/export-pdf-format/spec.md#requirement-a-pdf-export-carries-the-organisations-house-style
 */
final class OdtHouseStyle {

	/**
	 * At most this many footer lines are printed.
	 *
	 * @var int
	 */
	public const MAX_FOOTER_LINES = 6;

	/**
	 * A footer line is cut to this many characters.
	 *
	 * @var int
	 */
	public const MAX_LINE_LENGTH = 200;

	/**
	 * Constructor.
	 *
	 * @param string|null  $headBackground The table header background as `#rrggbb`, or null for the default.
	 * @param string|null  $headText       The table header text colour as `#rrggbb`, or null for the default.
	 * @param list<string> $footerLines    The footer lines printed above the page number.
	 */
	public function __construct(
		public readonly ?string $headBackground = null,
		public readonly ?string $headText = null,
		public readonly array $footerLines = [],
	) {
	}//end __construct()

	/**
	 * The house style of a profile, or null when it carries nothing to apply.
	 *
	 * Reads `colours.primary`, `colours.primaryText`, `footer.lines`,
	 * `footer.accessibilityUrl` and `footer.privacyUrl`. Never throws.
	 *
	 * @param array<string, mixed>|null $profile Thematiq's document style profile, or null.
	 *
	 * @return self|null The style, or null.
	 *
	 * @spec openspec/changes/export-pdf-house-style/specs/export-pdf-format/spec.md#requirement-a-pdf-export-carries-the-organisations-house-style
	 */
	public static function fromProfile(?array $profile): ?self {
		if ($profile === null) {
			return null;
		}

		$colours = self::section(profile: $profile, key: 'colours');
		$footer = self::section(profile: $profile, key: 'footer');

		$lines = [];
		$candidates = ($footer['lines'] ?? []);
		if (is_array($candidates) === false) {
			$candidates = [];
		}

		$candidates[] = ($footer['accessibilityUrl'] ?? null);
		$candidates[] = ($footer['privacyUrl'] ?? null);
		foreach ($candidates as $candidate) {
			$line = self::line(value: $candidate);
			if ($line !== null && count($lines) < self::MAX_FOOTER_LINES) {
				$lines[] = $line;
			}
		}

		$style = new self(
			headBackground: self::colour(value: ($colours['primary'] ?? null)),
			headText: self::colour(value: ($colours['primaryText'] ?? null)),
			footerLines: $lines
		);

		if ($style->headBackground === null && $style->headText === null && $style->footerLines === []) {
			return null;
		}

		return $style;
	}//end fromProfile()

	/**
	 * One array section of the profile, or an empty array.
	 *
	 * @param array<string, mixed> $profile The profile.
	 * @param string               $key     The section key.
	 *
	 * @return array<string, mixed>
	 */
	private static function section(array $profile, string $key): array {
		$section = ($profile[$key] ?? []);
		if (is_array($section) === false) {
			return [];
		}

		return $section;
	}//end section()

	/**
	 * A hex colour as lowercase `#rrggbb`, or null when the value is not one.
	 *
	 * @param mixed $value The profile value.
	 *
	 * @return string|null
	 */
	private static function colour(mixed $value): ?string {
		if (is_string($value) === false) {
			return null;
		}

		$value = strtolower(trim($value));
		if (preg_match('/^#[0-9a-f]{6}$/', $value) === 1) {
			return $value;
		}

		if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $value, $matches) === 1) {
			return '#' . $matches[1] . $matches[1] . $matches[2] . $matches[2] . $matches[3] . $matches[3];
		}

		return null;
	}//end colour()

	/**
	 * A footer line, trimmed and cut to length, or null when it is empty or not a string.
	 *
	 * @param mixed $value The profile value.
	 *
	 * @return string|null
	 */
	private static function line(mixed $value): ?string {
		if (is_string($value) === false) {
			return null;
		}

		$line = trim((string) preg_replace('/\s+/u', ' ', $value));
		if ($line === '') {
			return null;
		}

		return mb_substr($line, 0, self::MAX_LINE_LENGTH);
	}//end line()
}//end class
