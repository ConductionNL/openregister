<?php

/**
 * OpenRegister ZaaktypeSkeletonDraft
 *
 * The draft case skeleton a zaaktype mapping builds up: the plan settings,
 * the stages, the report, and the behandelaar and terms every human item gets.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

/**
 * What one {@see ZaaktypeCaseSkeletonMapper::map()} call has produced so far.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
 */
final class ZaaktypeSkeletonDraft {

	/**
	 * The stages produced so far, in sequence.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $items = [];

	/**
	 * The behandelaar role name, once a role declares it.
	 *
	 * @var string|null
	 */
	public ?string $behandelaar = null;

	/**
	 * The doorlooptijd and servicenorm carried onto every human item.
	 *
	 * @var array<string, string|null>
	 */
	public array $terms = ['doorlooptijd' => null, 'servicenorm' => null];

	/**
	 * The report lines, in the order they were noted.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $report = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $settings The plan settings to build on.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	public function __construct(
		public array $settings,
	) {
	}//end __construct()

	/**
	 * Add one report line.
	 *
	 * @param string                           $element What the line is about.
	 * @param array{0: string, 1: string, 2: string} $line    The status, the reason, and what the author should do.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	public function note(string $element, array $line): void {
		$this->report[] = ['element' => $element, 'status' => $line[0], 'reason' => $line[1], 'action' => $line[2]];
	}//end note()

	/**
	 * The stage added last.
	 *
	 * @return array<string, mixed> The stage node.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	public function lastStage(): array {
		return $this->items[array_key_last($this->items)];
	}//end lastStage()

	/**
	 * The milestone key of the stage added last, or null before the first stage.
	 *
	 * @return string|null The milestone key.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	public function lastMilestoneKey(): ?string {
		if ($this->items === []) {
			return null;
		}

		return $this->lastStage()['children'][1]['key'];
	}//end lastMilestoneKey()

	/**
	 * The mapping result: the draft definition and the report.
	 *
	 * @return array{draft: bool, definition: array<string, mixed>, report: array<int, array<string, string>>} The result.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	public function result(): array {
		return [
			'draft' => true,
			'definition' => ['settings' => $this->settings, 'items' => $this->items],
			'report' => $this->report,
		];
	}//end result()
}//end class
