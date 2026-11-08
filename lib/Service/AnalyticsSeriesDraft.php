<?php
/**
 * OpenRegister Analytics Series Draft
 *
 * A page-level chart series that is about to be registered.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/integration-leaf-foundation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * The key, title, data, presentation and scope of a series to register.
 *
 * Each with*() / in*() call returns a copy; a draft is never changed in place.
 *
 * @spec openspec/specs/integration-leaf-foundation/spec.md
 */
final class AnalyticsSeriesDraft {

	/**
	 * X-axis labels as given.
	 *
	 * @var array<int|string, mixed>
	 */
	private array $labels = [];

	/**
	 * Datasets as given.
	 *
	 * @var array<int|string, mixed>
	 */
	private array $datasets = [];

	/**
	 * Chart type hint.
	 *
	 * @var string
	 */
	private string $chartType = 'line';

	/**
	 * 'private', 'group' or 'public'.
	 *
	 * @var string
	 */
	private string $visibility = 'private';

	/**
	 * Register scope, or null.
	 *
	 * @var integer|null
	 */
	private ?int $registerId = null;

	/**
	 * Schema scope, or null.
	 *
	 * @var integer|null
	 */
	private ?int $schemaId = null;

	/**
	 * Constructor.
	 *
	 * @param string      $seriesKey Stable series key.
	 * @param string|null $title     Chart title.
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	public function __construct(
		public readonly string $seriesKey,
		public readonly ?string $title,
	) {
	}//end __construct()

	/**
	 * A copy with the given labels and datasets.
	 *
	 * @param array<int|string, mixed> $labels   X-axis labels.
	 * @param array<int|string, mixed> $datasets Named datasets.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	public function withData(array $labels, array $datasets): self {
		$copy           = clone $this;
		$copy->labels   = $labels;
		$copy->datasets = $datasets;
		return $copy;
	}//end withData()

	/**
	 * A copy with the given chart type and visibility.
	 *
	 * @param string $chartType  Chart type hint.
	 * @param string $visibility 'private', 'group' or 'public'.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	public function shownAs(string $chartType, string $visibility): self {
		$copy             = clone $this;
		$copy->chartType  = $chartType;
		$copy->visibility = $visibility;
		return $copy;
	}//end shownAs()

	/**
	 * A copy scoped to the given register and schema.
	 *
	 * @param int|null $registerId Register scope, or null.
	 * @param int|null $schemaId   Schema scope, or null.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	public function inScope(?int $registerId, ?int $schemaId): self {
		$copy             = clone $this;
		$copy->registerId = $registerId;
		$copy->schemaId   = $schemaId;
		return $copy;
	}//end inScope()

	/**
	 * X-axis labels as given.
	 *
	 * @return array<int|string, mixed>
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	public function labels(): array {
		return $this->labels;
	}//end labels()

	/**
	 * Datasets as given.
	 *
	 * @return array<int|string, mixed>
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	public function datasets(): array {
		return $this->datasets;
	}//end datasets()

	/**
	 * Chart type hint.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	public function chartType(): string {
		return $this->chartType;
	}//end chartType()

	/**
	 * 'private', 'group' or 'public'.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	public function visibility(): string {
		return $this->visibility;
	}//end visibility()

	/**
	 * Register scope, or null.
	 *
	 * @return integer|null
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	public function registerId(): ?int {
		return $this->registerId;
	}//end registerId()

	/**
	 * Schema scope, or null.
	 *
	 * @return integer|null
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	public function schemaId(): ?int {
		return $this->schemaId;
	}//end schemaId()
}//end class
