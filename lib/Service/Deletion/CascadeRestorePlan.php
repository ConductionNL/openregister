<?php

/**
 * What a cascade restore would bring back, put back, and leave, and why.
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

/**
 * The classified plan of one cascade restore, written by nothing.
 *
 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md
 */
final class CascadeRestorePlan {
	/**
	 * Constructor.
	 *
	 * @param string                   $root          The uuid of the object being restored.
	 * @param bool                     $cascadeKnown  Whether the delete left indexed cascade evidence at all.
	 * @param list<CascadeRestoreItem> $items         Every classified entry.
	 */
	public function __construct(
		public readonly string $root,
		public readonly bool $cascadeKnown,
		public readonly array $items,
	) {
	}//end __construct()

	/**
	 * The items of one class.
	 *
	 * @param string $class One of {@see CascadeRestoreService::CLASSES}.
	 *
	 * @return list<CascadeRestoreItem> The items in that class, in plan order.
	 *
	 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md
	 */
	public function ofClass(string $class): array {
		return array_values(
			array_filter($this->items, static fn (CascadeRestoreItem $item): bool => $item->class === $class)
		);
	}//end ofClass()

	/**
	 * Whether any item refuses the whole act.
	 *
	 * @return bool True when the caller lacks `update` on at least one object the restore would write.
	 *
	 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md
	 */
	public function isForbidden(): bool {
		return $this->ofClass(class: CascadeRestoreService::CLASS_FORBIDDEN) !== [];
	}//end isForbidden()

	/**
	 * The forbidden items as a count per schema, never naming the objects.
	 *
	 * @return array{count: int, schemas: array<string, int>} How many, and in which schemas.
	 *
	 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md
	 */
	public function forbiddenSummary(): array {
		$schemas = [];
		$forbidden = $this->ofClass(class: CascadeRestoreService::CLASS_FORBIDDEN);
		foreach ($forbidden as $item) {
			$key = ($item->schema ?? 'unknown');
			$schemas[$key] = (($schemas[$key] ?? 0) + 1);
		}

		return ['count' => count($forbidden), 'schemas' => $schemas];
	}//end forbiddenSummary()

	/**
	 * The items left alone, per class, in their public shape.
	 *
	 * @return array<string, list<array<string, mixed>>> changed, gone and already.
	 *
	 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md
	 */
	public function skipped(): array {
		$skipped = [];
		foreach (CascadeRestoreService::SKIPPED_CLASSES as $class) {
			$skipped[$class] = $this->publicItems(class: $class);
		}

		return $skipped;
	}//end skipped()

	/**
	 * The items of one class in their public shape.
	 *
	 * @param string $class One of {@see CascadeRestoreService::CLASSES}.
	 *
	 * @return list<array<string, mixed>> The items as the responses show them.
	 *
	 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md
	 */
	public function publicItems(string $class): array {
		return array_map(static fn (CascadeRestoreItem $item): array => $item->toArray(), $this->ofClass(class: $class));
	}//end publicItems()

	/**
	 * The plan as the preview endpoint returns it.
	 *
	 * @return array<string, mixed> The root, whether the cascade is known, every class, and the forbidden summary.
	 *
	 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md
	 */
	public function toArray(): array {
		$plan = [
			'root'         => $this->root,
			'cascadeKnown' => $this->cascadeKnown,
			'restore'      => $this->publicItems(class: CascadeRestoreService::CLASS_RESTORE),
			'relink'       => $this->publicItems(class: CascadeRestoreService::CLASS_RELINK),
		];

		return $plan + $this->skipped() + ['forbidden' => $this->forbiddenSummary()];
	}//end toArray()
}//end class
