<?php

/**
 * The `website` named format: a web address a person could type.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Format
 * @package  OCA\OpenRegister\Formats
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Formats;

use Opis\JsonSchema\Format;

/**
 * A website is a host, with an optional `http://` or `https://` scheme and an
 * optional path. The host is one or more DNS labels and a letter-only TLD of at
 * least two letters, so `www.nl` passes while `http://.nl` (no host) and a
 * hyphen-only label are refused — the cases the app-local validator forks this
 * format replaces disagreed on.
 *
 * The same value enforces identically through the UI and the API, because both
 * reach this one format.
 *
 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-named-formats-must-replace-the-app-local-validator-forks
 */
final class WebsiteFormat implements Format {

	/**
	 * One DNS label: a letter or digit, then letters, digits or hyphens, ending
	 * in a letter or digit. No leading or trailing hyphen, so a hyphen-only
	 * label cannot match.
	 */
	private const LABEL = '[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?';

	/**
	 * Whether the value is a website a person could type.
	 *
	 * @param mixed $data The value to validate.
	 *
	 * @inheritDoc
	 *
	 * @return bool True when the value is a website.
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-named-formats-must-replace-the-app-local-validator-forks
	 */
	public function validate(mixed $data): bool {
		if (is_string($data) === false || trim($data) === '') {
			return false;
		}

		$host = $this->host(value: trim($data));
		if ($host === null) {
			return false;
		}

		$pattern = '/^' . self::LABEL . '(?:\.' . self::LABEL . ')*\.[A-Za-z]{2,}$/';
		return preg_match($pattern, $host) === 1;
	}//end validate()

	/**
	 * The host part of a value: the scheme and any path, query or fragment
	 * stripped, or null when there is no host between them.
	 *
	 * @param string $value The trimmed value.
	 *
	 * @return string|null The host, or null when it is empty.
	 */
	private function host(string $value): ?string {
		$withoutScheme = preg_replace('#^https?://#i', '', $value, 1);
		if ($withoutScheme === null) {
			return null;
		}

		// Everything from the first path, query or fragment separator is not the
		// host. A leading separator leaves an empty host, which is refused.
		$host = preg_split('~[/?#]~', $withoutScheme, 2)[0];
		if ($host === '') {
			return null;
		}

		return $host;
	}//end host()
}//end class
