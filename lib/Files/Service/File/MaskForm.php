<?php

/**
 * The mask forms an anonymisation may use instead of a placeholder.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymisation-placeholder-id-scope/specs/entity-relation-grondslagen/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

use OCA\OpenRegister\Exception\UnknownMaskFormException;

/**
 * Validates the `anonymisation.maskForms` setting and masks a detected value.
 *
 * The setting maps an entity type to a form: `placeholder` (the default),
 * `first` or `last` with a keep count `n`, `email-local`, or `generalise`.
 * A mask that would show the whole value, or more than half of it, is not
 * produced: the caller then emits the placeholder.
 *
 * @spec openspec/changes/anonymisation-placeholder-id-scope/specs/entity-relation-grondslagen/spec.md
 */
final class MaskForm {

	/**
	 * The default form: the whole value becomes a placeholder.
	 *
	 * @var string
	 */
	public const PLACEHOLDER = 'placeholder';

	/**
	 * Keep the first n characters.
	 *
	 * @var string
	 */
	public const FIRST = 'first';

	/**
	 * Keep the last n characters.
	 *
	 * @var string
	 */
	public const LAST = 'last';

	/**
	 * Mask the local part of an e-mail address, keep `@` and the domain.
	 *
	 * @var string
	 */
	public const EMAIL_LOCAL = 'email-local';

	/**
	 * A date to its year, a Dutch postcode to its four digits.
	 *
	 * @var string
	 */
	public const GENERALISE = 'generalise';

	/**
	 * The character a masked position is written as.
	 *
	 * @var string
	 */
	private const MASK = '*';

	/**
	 * Every form the setting accepts.
	 *
	 * @var array<int, string>
	 */
	private const FORMS = [self::PLACEHOLDER, self::FIRST, self::LAST, self::EMAIL_LOCAL, self::GENERALISE];

	/**
	 * The setting as it is stored: each entry checked and reduced to its form
	 * and, for `first` and `last`, its keep count.
	 *
	 * @param mixed $maskForms The submitted `anonymisation.maskForms` value.
	 *
	 * @return array<string, array{form: string, n?: int}> Entity type to form.
	 *
	 * @throws UnknownMaskFormException When the value is not a map, a form is unknown, or n is below 1.
	 *
	 * @spec openspec/changes/anonymisation-placeholder-id-scope/specs/entity-relation-grondslagen/spec.md#requirement-a-detection-may-be-masked-in-part-with-the-form-set-per-entity-type-req-pis-010
	 */
	public static function normalise(mixed $maskForms): array {
		if (is_array($maskForms) === false) {
			throw new UnknownMaskFormException(message: 'anonymisation.maskForms must map an entity type to a mask form.');
		}

		$normalised = [];
		foreach ($maskForms as $entityType => $entry) {
			if (is_string($entityType) === false || trim($entityType) === '') {
				throw new UnknownMaskFormException(message: 'anonymisation.maskForms must map an entity type to a mask form.');
			}

			$normalised[$entityType] = self::normaliseEntry(entityType: $entityType, entry: $entry);
		}

		return $normalised;
	}//end normalise()

	/**
	 * One entry of the setting, checked.
	 *
	 * @param string $entityType The entity type the entry is for.
	 * @param mixed  $entry      The submitted entry.
	 *
	 * @return array{form: string, n?: int} The entry.
	 *
	 * @throws UnknownMaskFormException When the form is unknown or n is below 1.
	 */
	private static function normaliseEntry(string $entityType, mixed $entry): array {
		$form = null;
		if (is_array($entry) === true) {
			$form = ($entry['form'] ?? null);
		}

		if (is_string($form) === false || in_array($form, self::FORMS, true) === false) {
			throw new UnknownMaskFormException(message: 'anonymisation.maskForms.'.$entityType.' names an unknown mask form.');
		}

		if ($form !== self::FIRST && $form !== self::LAST) {
			return ['form' => $form];
		}

		$keep = ($entry['n'] ?? null);
		if (is_string($keep) === true && ctype_digit($keep) === true) {
			$keep = (int) $keep;
		}

		if (is_int($keep) === false || $keep < 1) {
			throw new UnknownMaskFormException(message: 'anonymisation.maskForms.'.$entityType.' needs a whole number n of at least 1.');
		}

		return ['form' => $form, 'n' => $keep];
	}//end normaliseEntry()

	/**
	 * The masked value, or null when the placeholder must be emitted instead.
	 *
	 * Null for the placeholder form, for a value the form does not fit (no `@`,
	 * no date or postcode), and for a `first` or `last` mask that would leave
	 * more than half of the value visible.
	 *
	 * @param string                           $value The detected value, trimmed.
	 * @param array{form?: mixed, n?: mixed}|null $form  The entry for the value's entity type.
	 *
	 * @return string|null The masked value, or null.
	 *
	 * @spec openspec/changes/anonymisation-placeholder-id-scope/specs/entity-relation-grondslagen/spec.md#requirement-a-detection-may-be-masked-in-part-with-the-form-set-per-entity-type-req-pis-010
	 */
	public static function apply(string $value, ?array $form): ?string {
		if ($form === null || $value === '') {
			return null;
		}

		return match ($form['form'] ?? null) {
			self::FIRST => self::keep(value: $value, keep: (int) ($form['n'] ?? 0), fromStart: true),
			self::LAST => self::keep(value: $value, keep: (int) ($form['n'] ?? 0), fromStart: false),
			self::EMAIL_LOCAL => self::maskEmailLocal(value: $value),
			self::GENERALISE => self::generalise(value: $value),
			default => null,
		};
	}//end apply()

	/**
	 * Keep n characters at the start or the end, mask the rest.
	 *
	 * @param string $value     The value.
	 * @param int    $keep      How many characters stay visible.
	 * @param bool   $fromStart Keep the first characters, else the last.
	 *
	 * @return string|null The mask, or null when it would show more than half.
	 */
	private static function keep(string $value, int $keep, bool $fromStart): ?string {
		$length = mb_strlen($value);
		if ($keep < 1 || ($keep * 2) > $length) {
			return null;
		}

		$masked = str_repeat(self::MASK, ($length - $keep));
		if ($fromStart === true) {
			return mb_substr($value, 0, $keep).$masked;
		}

		return $masked.mb_substr($value, ($length - $keep));
	}//end keep()

	/**
	 * Mask the local part of an e-mail address.
	 *
	 * @param string $value The value.
	 *
	 * @return string|null The mask, or null when the value is no address.
	 */
	private static function maskEmailLocal(string $value): ?string {
		$at = mb_strrpos($value, '@');
		if ($at === false || $at === 0 || $at === (mb_strlen($value) - 1)) {
			return null;
		}

		return str_repeat(self::MASK, $at).mb_substr($value, $at);
	}//end maskEmailLocal()

	/**
	 * A Dutch postcode to its four digits, a date to its year.
	 *
	 * @param string $value The value.
	 *
	 * @return string|null The generalised value, or null when it is neither.
	 */
	private static function generalise(string $value): ?string {
		if (preg_match('/^([1-9][0-9]{3})\s?[A-Za-z]{2}$/', $value, $postcode) === 1) {
			return $postcode[1];
		}

		$years = [];
		preg_match_all('/(?<![0-9])((?:1[89]|20)[0-9]{2})(?![0-9])/', $value, $years);
		if (count($years[1]) !== 1 || $value === $years[1][0]) {
			return null;
		}

		return $years[1][0];
	}//end generalise()
}//end class
