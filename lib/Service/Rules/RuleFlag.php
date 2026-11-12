<?php

/**
 * OpenRegister rule flag
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

/**
 * Where a rule's `enabled` flag sits in a schema's declarations, and its value.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class RuleFlag {
	/**
	 * Name the flag.
	 *
	 * @param array<int, string> $path    The path into the declarations, never empty.
	 * @param bool               $enabled The value to write at its end.
	 */
	public function __construct(
		public readonly array $path,
		public readonly bool $enabled,
	) {
	}//end __construct()

	/**
	 * The first key of the path.
	 *
	 * @return string The key.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function key(): string {
		return (string)($this->path[0] ?? '');
	}//end key()

	/**
	 * Whether the first key is the last one.
	 *
	 * @return bool True when the flag is written at this key.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function isLast(): bool {
		return count($this->path) <= 1;
	}//end isLast()

	/**
	 * The same flag one level deeper.
	 *
	 * @return self The flag with the first key dropped.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function rest(): self {
		return new self(path: array_slice($this->path, 1), enabled: $this->enabled);
	}//end rest()
}//end class
