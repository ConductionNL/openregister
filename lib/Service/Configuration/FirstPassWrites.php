<?php

/**
 * FirstPassWrites: what pass 1 of a schema import wrote, so pass 2 can leave an identical definition alone.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md#requirement-the-second-schema-pass-must-write-only-what-resolving-references-changed
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

/**
 * The definitions the first schema pass of one import wrote.
 *
 * Pass 2 re-imports every schema, forced, so references to schemas declared
 * later in the file resolve. For a schema with no such reference it hands the
 * store the definition pass 1 just wrote: the change detection, the write, the
 * shipped baseline and the installers all over again, for nothing (#9945).
 *
 * Only an identical definition is recognised, and only one pass 1 of the same
 * import WROTE. A schema pass 1 skipped as unchanged is still written by pass
 * 2, as before, and nothing is remembered once the import's pass 2 is over.
 *
 * @spec openspec/specs/data-import-export/spec.md#requirement-the-second-schema-pass-must-write-only-what-resolving-references-changed
 */
final class FirstPassWrites {

	private const IDLE = 0;

	private const FIRST_PASS = 1;

	private const SECOND_PASS = 2;

	/**
	 * Which pass is running.
	 *
	 * @var int
	 */
	private int $pass = self::IDLE;

	/**
	 * A digest of the definition pass 1 wrote, by schema id.
	 *
	 * @var array<int, string>
	 */
	private array $written = [];

	/**
	 * Pass 1 begins: forget any earlier import and start remembering writes.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-the-second-schema-pass-must-write-only-what-resolving-references-changed
	 */
	public function beginFirstPass(): void {
		$this->written = [];
		$this->pass = self::FIRST_PASS;
	}//end beginFirstPass()

	/**
	 * Pass 2 begins: from here writes are compared, not remembered.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-the-second-schema-pass-must-write-only-what-resolving-references-changed
	 */
	public function beginSecondPass(): void {
		$this->pass = self::SECOND_PASS;
	}//end beginSecondPass()

	/**
	 * The import's schema passes are over.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-the-second-schema-pass-must-write-only-what-resolving-references-changed
	 */
	public function end(): void {
		$this->written = [];
		$this->pass = self::IDLE;
	}//end end()

	/**
	 * Remember a definition a schema was written with; ignored outside pass 1.
	 *
	 * @param int|null $schemaId   The stored schema's id.
	 * @param array    $definition The normalised definition handed to the store.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-the-second-schema-pass-must-write-only-what-resolving-references-changed
	 */
	public function remember(?int $schemaId, array $definition): void {
		if ($this->pass === self::FIRST_PASS && $schemaId !== null) {
			$this->written[$schemaId] = $this->digest(definition: $definition);
		}
	}//end remember()

	/**
	 * Whether pass 2 is about to write exactly what pass 1 wrote for this schema.
	 *
	 * @param int|null $schemaId   The stored schema's id.
	 * @param array    $definition The normalised definition pass 2 would hand to the store.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-the-second-schema-pass-must-write-only-what-resolving-references-changed
	 */
	public function wroteTheSame(?int $schemaId, array $definition): bool {
		return $this->pass === self::SECOND_PASS
			&& $schemaId !== null
			&& ($this->written[$schemaId] ?? null) === $this->digest(definition: $definition);
	}//end wroteTheSame()

	/**
	 * A digest of a definition; both passes derive it from the same component in the same order.
	 *
	 * @param array $definition The normalised definition.
	 *
	 * @return string
	 */
	private function digest(array $definition): string {
		return hash('xxh128', serialize($definition));
	}//end digest()
}//end class
