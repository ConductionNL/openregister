<?php

/**
 * What a cascade restore did.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Deletion
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Deletion;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The outcome of one committed cascade restore.
 *
 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md
 */
final class CascadeRestoreResult {
	/**
	 * Constructor.
	 *
	 * @param list<ObjectEntity>  $dependants The dependants brought back with the root, as they were before the restore.
	 * @param int                 $relinked   How many references were put back on surviving objects.
	 * @param CascadeRestorePlan|null $plan   The plan the restore ran, or null when the cascade was not asked for.
	 */
	public function __construct(
		public readonly array $dependants,
		public readonly int $relinked,
		public readonly ?CascadeRestorePlan $plan=null,
	) {
	}//end __construct()

	/**
	 * The counts the restore response adds to `success` and `message`.
	 *
	 * `restored` counts the root as well as its dependants.
	 *
	 * @return array<string, mixed> restored, relinked, skipped, and cascadeKnown.
	 *
	 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md
	 */
	public function toArray(): array {
		$counts = [
			'restored' => (count($this->dependants) + 1),
			'relinked' => $this->relinked,
		];
		if ($this->plan === null) {
			return $counts + ['cascade' => false];
		}

		return $counts + [
			'skipped'      => $this->plan->skipped(),
			'cascadeKnown' => $this->plan->cascadeKnown,
		];
	}//end toArray()
}//end class
