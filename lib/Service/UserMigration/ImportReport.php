<?php

/**
 * The report of a user import: what was restored and what was not, and why.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\UserMigration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/platform-user-migrator/specs/platform-user-migration/spec.md#requirement-objects-are-not-exported-and-both-ends-say-so-req-pum-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\UserMigration;

/**
 * Collects the outcome of each item an import met.
 *
 * @spec openspec/changes/platform-user-migrator/specs/platform-user-migration/spec.md#requirement-objects-are-not-exported-and-both-ends-say-so-req-pum-002
 */
final class ImportReport {

	/**
	 * What was restored.
	 *
	 * @var list<string>
	 */
	private array $restored = [];

	/**
	 * What was not, with the reason.
	 *
	 * @var list<string>
	 */
	private array $skipped = [];

	/**
	 * Record an item restored.
	 *
	 * @param string $what The item.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-user-migrator/specs/platform-user-migration/spec.md#requirement-objects-are-not-exported-and-both-ends-say-so-req-pum-002
	 */
	public function restored(string $what): void {
		$this->restored[] = $what;
	}//end restored()

	/**
	 * Record an item not restored.
	 *
	 * @param string $what   The item.
	 * @param string $reason Why not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-user-migrator/specs/platform-user-migration/spec.md#requirement-objects-are-not-exported-and-both-ends-say-so-req-pum-002
	 */
	public function skipped(string $what, string $reason): void {
		$this->skipped[] = $what . ': ' . $reason;
	}//end skipped()

	/**
	 * The items not restored, each with its reason.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/platform-user-migrator/specs/platform-user-migration/spec.md#requirement-objects-are-not-exported-and-both-ends-say-so-req-pum-002
	 */
	public function notRestored(): array {
		return $this->skipped;
	}//end notRestored()

	/**
	 * The report as lines for the command's output.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/platform-user-migrator/specs/platform-user-migration/spec.md#requirement-objects-are-not-exported-and-both-ends-say-so-req-pum-002
	 */
	public function lines(): array {
		$lines = ['OpenRegister: restored ' . count($this->restored) . ', not restored ' . count($this->skipped) . '.'];
		foreach ($this->skipped as $line) {
			$lines[] = 'Not restored: ' . $line;
		}

		return $lines;
	}//end lines()
}//end class
