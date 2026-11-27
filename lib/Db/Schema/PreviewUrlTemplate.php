<?php

/**
 * PreviewUrlTemplate: the `previewUrl` a schema may declare for previewing a draft on an outside site.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\Schema
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\Schema;

use InvalidArgumentException;

/**
 * Validates and fills a preview URL template.
 *
 * A template is an absolute http(s) URL that may use the placeholders
 * `{uuid}` (the object), `{version}` (the draft key) and `{token}` (the
 * preview link's anchor), for example
 * `https://www.voorbeeld.nl/preview/{uuid}?versie={version}&token={token}`.
 * Any other `{name}` and any unbalanced brace is refused, so a typo is
 * caught when the schema is saved rather than when a preview is opened.
 *
 * @spec openspec/changes/records-draft-versions/specs/content-versioning/spec.md#requirement-a-draft-can-be-previewed-on-an-outside-site-through-an-expiring-link
 */
final class PreviewUrlTemplate {

	/**
	 * The configuration key a schema declares the template under.
	 *
	 * @var string
	 */
	public const KEY = 'previewUrl';

	/**
	 * The placeholders a template may use.
	 *
	 * @var array<int, string>
	 */
	public const PLACEHOLDERS = ['uuid', 'version', 'token'];

	/**
	 * The longest template accepted.
	 *
	 * @var int
	 */
	private const MAX_LENGTH = 2048;

	/**
	 * The template to store: trimmed, or null when none is declared.
	 *
	 * @param mixed $value The declared value.
	 *
	 * @return string|null The template, or null for an empty declaration.
	 *
	 * @throws InvalidArgumentException When the value is not a usable template.
	 *
	 * @spec openspec/changes/records-draft-versions/specs/content-versioning/spec.md#requirement-a-draft-can-be-previewed-on-an-outside-site-through-an-expiring-link
	 */
	public static function validated(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		if (is_string($value) === false) {
			throw new InvalidArgumentException('previewUrl must be a URL template string.');
		}

		$template = trim($value);
		if ($template === '') {
			return null;
		}

		if (strlen($template) > self::MAX_LENGTH) {
			throw new InvalidArgumentException('previewUrl is longer than ' . self::MAX_LENGTH . ' characters.');
		}

		preg_match_all('/\{([^{}]*)\}/', $template, $matches);
		$unknown = array_values(array_diff($matches[1], self::PLACEHOLDERS));
		if ($unknown !== []) {
			throw new InvalidArgumentException(
				'previewUrl uses an unknown placeholder {' . $unknown[0] . '}; use ' . self::placeholderList() . '.'
			);
		}

		$sample = self::fill(template: $template, uuid: 'uuid', version: 'version', token: 'token');
		if (str_contains($sample, '{') === true || str_contains($sample, '}') === true) {
			throw new InvalidArgumentException('previewUrl has an unbalanced brace; use ' . self::placeholderList() . '.');
		}

		$scheme = strtolower((string)parse_url($sample, PHP_URL_SCHEME));
		$host   = (string)parse_url($sample, PHP_URL_HOST);
		if (in_array($scheme, ['http', 'https'], true) === false || $host === '' || preg_match('/\s/', $sample) === 1) {
			throw new InvalidArgumentException('previewUrl must be an absolute http or https URL.');
		}

		return $template;
	}//end validated()

	/**
	 * The template with its placeholders filled, each value URL-encoded.
	 *
	 * @param string $template The template.
	 * @param string $uuid     The object uuid.
	 * @param string $version  The draft key.
	 * @param string $token    The preview link's anchor.
	 *
	 * @return string The URL.
	 *
	 * @spec openspec/changes/records-draft-versions/specs/content-versioning/spec.md#requirement-a-draft-can-be-previewed-on-an-outside-site-through-an-expiring-link
	 */
	public static function fill(string $template, string $uuid, string $version, string $token): string {
		return strtr(
			$template,
			[
				'{uuid}'    => rawurlencode($uuid),
				'{version}' => rawurlencode($version),
				'{token}'   => rawurlencode($token),
			]
		);
	}//end fill()

	/**
	 * The placeholders, as a message lists them.
	 *
	 * @return string
	 */
	private static function placeholderList(): string {
		return implode(', ', array_map(static fn (string $name): string => '{' . $name . '}', self::PLACEHOLDERS));
	}//end placeholderList()
}//end class
