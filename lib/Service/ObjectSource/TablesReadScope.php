<?php

/**
 * What one read of the Nextcloud Tables app is about, and for whom.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/tables-virtual-register/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

/**
 * The bound table, an optional View of it, the acting user and an optional page.
 *
 * @spec openspec/specs/tables-virtual-register/spec.md
 */
final class TablesReadScope {

	/**
	 * The bound View id, 0 when the read goes to the table itself.
	 *
	 * @var int
	 */
	private int $viewId = 0;

	/**
	 * Native row limit.
	 *
	 * @var int|null
	 */
	private ?int $limit = null;

	/**
	 * Native row offset.
	 *
	 * @var int|null
	 */
	private ?int $offset = null;

	/**
	 * Constructor.
	 *
	 * @param int $tableId The bound table id.
	 * @param string $userId The acting user id.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly int $tableId,
		public readonly string $userId,
	) {
	}//end __construct()

	/**
	 * This scope, bound to a View.
	 *
	 * @param int $viewId The View id, 0 for none.
	 *
	 * @return self The bound scope.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	public function withView(int $viewId): self {
		$scope = clone $this;
		$scope->viewId = $viewId;

		return $scope;
	}//end withView()

	/**
	 * This scope, limited to a page of rows.
	 *
	 * @param int $limit The native row limit.
	 * @param int $offset The native row offset.
	 *
	 * @return self The paged scope.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	public function withPage(int $limit, int $offset): self {
		$scope = clone $this;
		$scope->limit = $limit;
		$scope->offset = $offset;

		return $scope;
	}//end withPage()

	/**
	 * Whether the read goes through a View.
	 *
	 * @return bool True when a View is bound.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	public function isView(): bool {
		return $this->viewId !== 0;
	}//end isView()

	/**
	 * The id Tables reads from: the View when one is bound, else the table.
	 *
	 * @return int The View or table id.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	public function sourceId(): int {
		if ($this->isView() === true) {
			return $this->viewId;
		}

		return $this->tableId;
	}//end sourceId()

	/**
	 * The native row limit.
	 *
	 * @return int|null The limit, null for none.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	public function limit(): ?int {
		return $this->limit;
	}//end limit()

	/**
	 * The native row offset.
	 *
	 * @return int|null The offset, null for none.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	public function offset(): ?int {
		return $this->offset;
	}//end offset()
}//end class
