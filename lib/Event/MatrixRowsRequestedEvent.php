<?php

/**
 * OpenRegister MatrixRowsRequestedEvent
 *
 * Dispatched while a schema's department matrix is compiled, when the matrix
 * declares `rowsFrom`. A listener that answers to that provider id adds rows in
 * the matrix row shape, naming its app; they are compiled beside the declared
 * rows. Only the app that owns the provider id (`<appId>.<name>`) is heard: rows
 * another app adds, or rows added without an app id, are dropped.
 *
 * 🔴 It fires INSIDE an authorization decision. A listener must read without
 * RBAC and must not ask the permission handler anything, or it re-enters the
 * decision it is part of. A listener that throws contributes no rows: a matrix
 * only adds ways in, so that is the closed direction.
 *
 * Fired at most once per request per schema and provider; the rows are kept for
 * the rest of the request.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rbac-matrix-app-rows-and-file-verbs/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\Schema;
use OCP\EventDispatcher\Event;

/**
 * Collects matrix rows an app contributes for one schema.
 */
class MatrixRowsRequestedEvent extends Event {

	/**
	 * The rows listeners added.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * The app that added each row, by the row's index.
	 *
	 * @var array<int, string>
	 */
	private array $answeredBy = [];

	/**
	 * Constructor.
	 *
	 * @param Schema $schema   The schema whose matrix is compiled.
	 * @param string $provider The provider id the matrix names in `rowsFrom`.
	 */
	public function __construct(
		private readonly Schema $schema,
		private readonly string $provider,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The schema whose matrix is compiled.
	 *
	 * @return Schema The schema.
	 *
	 * @spec openspec/changes/rbac-matrix-app-rows-and-file-verbs/specs/rbac-scopes/spec.md
	 */
	public function getSchema(): Schema {
		return $this->schema;
	}//end getSchema()

	/**
	 * The provider id the matrix names; a listener answers only to its own.
	 *
	 * @return string The provider id.
	 *
	 * @spec openspec/changes/rbac-matrix-app-rows-and-file-verbs/specs/rbac-scopes/spec.md
	 */
	public function getProvider(): string {
		return $this->provider;
	}//end getProvider()

	/**
	 * Add rows in the matrix row shape (`group`, `value`/`values`, `actions`, `where`, `until`).
	 *
	 * @param array<int, mixed> $rows  The rows.
	 * @param string            $appId The app adding them; only the app that owns the provider id is heard.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-matrix-app-rows-and-file-verbs/specs/rbac-scopes/spec.md
	 */
	public function addRows(array $rows, string $appId = ''): void {
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$this->rows[]       = $row;
				$this->answeredBy[] = trim($appId);
			}
		}
	}//end addRows()

	/**
	 * The app that added the row at this index of getRows(), '' when none was named.
	 *
	 * @param int $index The row's index.
	 *
	 * @return string The app id.
	 *
	 * @spec openspec/changes/rbac-matrix-app-rows-and-file-verbs/specs/rbac-scopes/spec.md
	 */
	public function answeredBy(int $index): string {
		return ($this->answeredBy[$index] ?? '');
	}//end answeredBy()

	/**
	 * The rows listeners added.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/changes/rbac-matrix-app-rows-and-file-verbs/specs/rbac-scopes/spec.md
	 */
	public function getRows(): array {
		return $this->rows;
	}//end getRows()
}//end class
