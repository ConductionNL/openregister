<?php

/**
 * OpenRegister ArchivalDecisionSources
 *
 * The four blocks an archival decision is resolved from, read once off the
 * object and handed to each step of {@see ArchivalDecisionResolver}.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * The stored blocks one object's archival decision rests on.
 *
 * @spec openspec/specs/retention-management/spec.md
 */
final readonly class ArchivalDecisionSources {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>  $retention  The stored retention block.
	 * @param array<string, mixed>  $tmlo       The stored TMLO block.
	 * @param array<string, mixed>  $annotation The evaluated schema annotation block.
	 * @param array<string, string> $declared   The record's own archival fields.
	 */
	public function __construct(
		public array $retention,
		public array $tmlo,
		public array $annotation,
		public array $declared,
	) {
	}//end __construct()

	/**
	 * The first non-empty string among the named fields, in the order named.
	 *
	 * @param string ...$paths Fields as `<block>.<key>`, block one of retention, tmlo, annotation, declared.
	 *
	 * @return string|null The trimmed value, or null when none of them holds one.
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function firstText(string ...$paths): ?string {
		$blocks = [
			'retention' => $this->retention,
			'tmlo' => $this->tmlo,
			'annotation' => $this->annotation,
			'declared' => $this->declared,
		];

		foreach ($paths as $path) {
			[$block, $key] = explode('.', $path, 2);
			$text = ArchivalText::fromString(value: ($blocks[$block][$key] ?? null));
			if ($text !== null) {
				return $text;
			}
		}

		return null;
	}//end firstText()
}//end class
