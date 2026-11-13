<?php

/**
 * Image URL value check
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Formats
 * @package  OCA\OpenRegister\Formats
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/changes/extended-field-types-range-and-image/specs/extended-field-types/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Formats;

/**
 * Checks and normalises an `image-url` value: a URL string, or an object
 * `{ url, width?, height?, altText?, format? }`. A bare URL is stored as `{ url }`.
 *
 * @spec openspec/changes/extended-field-types-range-and-image/specs/extended-field-types/spec.md
 */
final class ImageUrlValue {

	/**
	 * The image formats an `image-url` may declare.
	 *
	 * @var array<int, string>
	 */
	public const FORMATS = ['jpeg', 'png', 'webp', 'avif', 'svg'];

	/**
	 * The object form of a value: a bare string becomes `{ url }`, anything else is returned as it is.
	 *
	 * @param mixed $value The submitted or stored value.
	 *
	 * @return mixed The object form.
	 *
	 * @spec openspec/changes/extended-field-types-range-and-image/specs/extended-field-types/spec.md
	 */
	public static function normalised(mixed $value): mixed {
		if (is_string($value) === true) {
			return ['url' => $value];
		}

		return $value;
	}//end normalised()

	/**
	 * What is wrong with an image value, or null when it is valid.
	 *
	 * @param mixed  $value        The submitted value, a string or an object.
	 * @param string $propertyName The property name, for the message.
	 *
	 * @return string|null Null on success, or the 422 message naming the property.
	 *
	 * @spec openspec/changes/extended-field-types-range-and-image/specs/extended-field-types/spec.md
	 */
	public function validate(mixed $value, string $propertyName): ?string {
		$image = self::normalised(value: $value);
		if (is_array($image) === false || ($image !== [] && array_is_list($image) === true)) {
			return "Property '{$propertyName}': image-url must be a URL or an object with a 'url'";
		}

		$problem = $this->urlProblem(url: ($image['url'] ?? null));
		$problem ??= $this->sizeProblem(image: $image);
		$problem ??= $this->formatProblem(image: $image);
		if ($problem === null) {
			return null;
		}

		return "Property '{$propertyName}': {$problem}";
	}//end validate()

	/**
	 * What is wrong with the image address.
	 *
	 * @param mixed $url The `url` member.
	 *
	 * @return string|null Null when it is a valid URL.
	 */
	private function urlProblem(mixed $url): ?string {
		if ($url === null) {
			return "image-url is missing 'url'";
		}

		if (is_string($url) === false) {
			return "'url' must be a string";
		}

		if (filter_var($url, FILTER_VALIDATE_URL) === false) {
			return "'{$url}' is not a valid URL";
		}

		return null;
	}//end urlProblem()

	/**
	 * What is wrong with the optional width and height.
	 *
	 * @param array $image The object form.
	 *
	 * @return string|null Null when each one given is a positive integer.
	 */
	private function sizeProblem(array $image): ?string {
		foreach (['width', 'height'] as $member) {
			$size = ($image[$member] ?? null);
			if ($size !== null && (is_int($size) === false || $size <= 0)) {
				return "'{$member}' must be a positive integer";
			}
		}

		return null;
	}//end sizeProblem()

	/**
	 * What is wrong with the optional alt text and image format.
	 *
	 * @param array $image The object form.
	 *
	 * @return string|null Null when the alt text is a string and the format a known one.
	 */
	private function formatProblem(array $image): ?string {
		$altText = ($image['altText'] ?? null);
		if ($altText !== null && is_string($altText) === false) {
			return "'altText' must be a string";
		}

		$format = ($image['format'] ?? null);
		if ($format === null || (is_string($format) === true && in_array($format, self::FORMATS, true) === true)) {
			return null;
		}

		$label = $format;
		if (is_string($format) === false) {
			$label = json_encode($format);
		}

		return "format '{$label}' is not one of " . implode('|', self::FORMATS);
	}//end formatProblem()
}//end class
