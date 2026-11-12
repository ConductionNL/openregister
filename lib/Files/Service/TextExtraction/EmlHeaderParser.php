<?php

/**
 * EmlHeaderParser — reads raw RFC 2822 header values: address lists and dates.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TextExtraction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/text-extraction-eml/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\TextExtraction;

use DateTimeImmutable;
use Throwable;

/**
 * Parses raw header values for {@see EmlParser}.
 *
 * A `Date` header is read with the known formats first, then PHP's
 * free-form parser; an address list is split by {@see AddressListSplitter}.
 *
 * @spec openspec/specs/text-extraction-eml/spec.md
 */
final class EmlHeaderParser {
	/**
	 * Date header formats tried before the free-form parse, in order.
	 *
	 * @var array<int, string>
	 */
	private const DATE_PATTERNS = [
		\DateTimeInterface::RFC2822,
		\DateTimeInterface::RFC822,
		'D, d M Y H:i:s O',
		'D, d M Y H:i:s O (T)',
		'd M Y H:i:s O',
	];

	/**
	 * Parse a raw Date header into a DateTimeImmutable.
	 *
	 * @param string|null $raw Raw header value.
	 *
	 * @return DateTimeImmutable|null Null when the header is missing or unparseable.
	 *
	 * @spec openspec/specs/text-extraction-eml/spec.md#requirement-extracteml-and-parseemlstructured-must-not-log-pii-adr-005
	 */
	public function date(?string $raw): ?DateTimeImmutable {
		if ($raw === null || $raw === '') {
			return null;
		}

		return $this->fromPatterns(raw: $raw) ?? $this->fromFreeForm(raw: $raw);
	}//end date()

	/**
	 * Split a non-empty address-list header value into address tokens.
	 *
	 * @param string $raw Raw header value.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/text-extraction-eml/spec.md
	 */
	public function addresses(string $raw): array {
		return (new AddressListSplitter())->split(raw: $raw);
	}//end addresses()

	/**
	 * Parse a Date header with the first of the known formats that fits.
	 *
	 * @param string $raw Raw header value.
	 *
	 * @return DateTimeImmutable|null Null when no format fits.
	 */
	private function fromPatterns(string $raw): ?DateTimeImmutable {
		foreach (self::DATE_PATTERNS as $pattern) {
			$parsed = DateTimeImmutable::createFromFormat(format: $pattern, datetime: $raw);
			if ($parsed instanceof DateTimeImmutable) {
				return $parsed;
			}
		}

		return null;
	}//end fromPatterns()

	/**
	 * Parse a Date header with PHP's free-form date parser.
	 *
	 * @param string $raw Raw header value.
	 *
	 * @return DateTimeImmutable|null Null when PHP cannot read it.
	 */
	private function fromFreeForm(string $raw): ?DateTimeImmutable {
		try {
			return new DateTimeImmutable(datetime: $raw);
		} catch (Throwable) {
			return null;
		}
	}//end fromFreeForm()
}//end class
