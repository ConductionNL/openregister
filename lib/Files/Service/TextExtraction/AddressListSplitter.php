<?php

/**
 * AddressListSplitter — splits an RFC 2822 address-list into address tokens.
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

/**
 * One walk over one address-list header value.
 *
 * Naive comma-split breaks on quoted display names containing commas
 * (e.g. `"Doe, John" <john@example.com>` parses as two bad tokens).
 * The walk preserves commas inside double-quoted strings and inside
 * angle-bracketed addresses (the two contexts where RFC 2822 permits
 * structural characters to appear). Backslash-escaped characters inside
 * quoted strings are honoured.
 *
 * The walk keeps state, so use a new instance for every value.
 *
 * @spec openspec/specs/text-extraction-eml/spec.md
 */
final class AddressListSplitter {
	/**
	 * Tokens completed so far.
	 *
	 * @var array<int, string>
	 */
	private array $tokens = [];

	/**
	 * The token being read.
	 *
	 * @var string
	 */
	private string $buffer = '';

	/**
	 * Whether the walk is inside a double-quoted string.
	 *
	 * @var bool
	 */
	private bool $inQuote = false;

	/**
	 * Whether the walk is inside an angle-bracketed address.
	 *
	 * @var bool
	 */
	private bool $inAngle = false;

	/**
	 * Split a header value into trimmed, non-empty address tokens.
	 *
	 * @param string $raw Raw header value.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/text-extraction-eml/spec.md
	 */
	public function split(string $raw): array {
		$length = strlen($raw);
		for ($i = 0; $i < $length; $i++) {
			$i = $this->consume(raw: $raw, offset: $i);
		}

		if ($this->buffer !== '') {
			$this->tokens[] = trim($this->buffer);
		}

		return array_values(
			array: array_filter(
				array: $this->tokens,
				callback: static fn (string $part): bool => $part !== ''
			)
		);
	}//end split()

	/**
	 * Read the character at an offset, plus the escaped one after it inside a quoted string.
	 *
	 * @param string $raw The header value.
	 * @param int    $offset Offset of the character.
	 *
	 * @return int Offset of the last character read.
	 */
	private function consume(string $raw, int $offset): int {
		$char = $raw[$offset];
		if ($char === '\\' && $this->inQuote === true && $offset + 1 < strlen($raw)) {
			// Honour escaped character inside a quoted display name.
			$this->buffer .= $char . $raw[$offset + 1];
			return $offset + 1;
		}

		$this->apply(char: $char);
		return $offset;
	}//end consume()

	/**
	 * Close the token on a structural comma, else add the character to it.
	 *
	 * @param string $char The character.
	 *
	 * @return void
	 */
	private function apply(string $char): void {
		if ($char === ',' && $this->inQuote === false && $this->inAngle === false) {
			$this->tokens[] = trim($this->buffer);
			$this->buffer = '';
			return;
		}

		$this->track(char: $char);
		$this->buffer .= $char;
	}//end apply()

	/**
	 * Track entering and leaving quoted strings and angle brackets.
	 *
	 * @param string $char The character.
	 *
	 * @return void
	 */
	private function track(string $char): void {
		if ($char === '"' && $this->inAngle === false) {
			$this->inQuote = !$this->inQuote;
			return;
		}

		if ($this->inQuote === false) {
			$this->trackAngle(char: $char);
		}
	}//end track()

	/**
	 * Track entering and leaving angle brackets outside a quoted string.
	 *
	 * @param string $char The character.
	 *
	 * @return void
	 */
	private function trackAngle(string $char): void {
		if ($char === '<') {
			$this->inAngle = true;
		}

		if ($char === '>') {
			$this->inAngle = false;
		}
	}//end trackAngle()
}//end class
