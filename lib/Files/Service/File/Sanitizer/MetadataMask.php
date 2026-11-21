<?php

/**
 * OpenRegister metadata mask
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\File\Sanitizer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-a-value-inside-a-metadata-field-is-masked-and-unmaskable-fields-are-named-req-rrs-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File\Sanitizer;

/**
 * The substitution map of one anonymisation run, applied to descriptive
 * metadata fields (title, subject, keywords, description, text custom
 * properties), and the fields of that run that could not be masked.
 *
 * Without a map ({@see none()}) the sanitisers keep their plain behaviour:
 * descriptive fields are replaced by the sentinel and nothing is named.
 * A field name is recorded, never its value.
 *
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-a-value-inside-a-metadata-field-is-masked-and-unmaskable-fields-are-named-req-rrs-004
 */
final class MetadataMask {

	/**
	 * The fields removed because they could not be masked, in the order met.
	 *
	 * @var list<array{field: string, reason: string}>
	 */
	private array $unmaskable = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, string> $substitutions Needle to placeholder, as the body is replaced.
	 * @param bool                  $active        False for the plain sanitiser run (sentinel, nothing named).
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-a-value-inside-a-metadata-field-is-masked-and-unmaskable-fields-are-named-req-rrs-004
	 */
	public function __construct(
		private readonly array $substitutions = [],
		private readonly bool $active = true,
	) {
	}//end __construct()

	/**
	 * The plain run: no masking, descriptive fields go to the sentinel.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-a-value-inside-a-metadata-field-is-masked-and-unmaskable-fields-are-named-req-rrs-004
	 */
	public static function none(): self {
		return new self(substitutions: [], active: false);
	}//end none()

	/**
	 * Whether this run masks (true) or replaces by the sentinel (false).
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-a-value-inside-a-metadata-field-is-masked-and-unmaskable-fields-are-named-req-rrs-004
	 */
	public function isActive(): bool {
		return $this->active;
	}//end isActive()

	/**
	 * The text with every needle replaced by its placeholder, longest needle
	 * first and never re-replacing a placeholder.
	 *
	 * @param string $text The field's text.
	 *
	 * @return string The masked text.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-a-value-inside-a-metadata-field-is-masked-and-unmaskable-fields-are-named-req-rrs-004
	 */
	public function apply(string $text): string {
		$pairs = [];
		foreach ($this->substitutions as $needle => $placeholder) {
			if ((string) $needle !== '') {
				$pairs[(string) $needle] = (string) $placeholder;
			}
		}

		if ($pairs === []) {
			return $text;
		}

		return strtr($text, $pairs);
	}//end apply()

	/**
	 * Record a field removed because it could not be masked.
	 *
	 * @param string $field  The field's name (never its value).
	 * @param string $reason Why it could not be masked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-a-value-inside-a-metadata-field-is-masked-and-unmaskable-fields-are-named-req-rrs-004
	 */
	public function noteUnmaskable(string $field, string $reason): void {
		$this->unmaskable[] = ['field' => $field, 'reason' => $reason];
	}//end noteUnmaskable()

	/**
	 * The fields removed because they could not be masked.
	 *
	 * @return list<array{field: string, reason: string}>
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-a-value-inside-a-metadata-field-is-masked-and-unmaskable-fields-are-named-req-rrs-004
	 */
	public function unmaskable(): array {
		return $this->unmaskable;
	}//end unmaskable()

	/**
	 * The unmaskable fields as a report carries them: the list for a masking
	 * run, null for a plain run (whose report keeps its old keys).
	 *
	 * @return list<array{field: string, reason: string}>|null
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-a-value-inside-a-metadata-field-is-masked-and-unmaskable-fields-are-named-req-rrs-004
	 */
	public function reported(): ?array {
		if ($this->active === false) {
			return null;
		}

		return $this->unmaskable;
	}//end reported()
}//end class
