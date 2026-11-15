<?php

/**
 * OpenRegister ExportRequest
 *
 * What one object export covers: the register and schema, the filters and the user it runs for.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Export;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\StoredEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectExportRequest;
use OCP\IUser;

/**
 * What one object export covers: the register and schema, the filters and the user it runs for.
 *
 * A null schema with a register means every schema of that register.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ExportRequest {

	/**
	 * The page to read; null reads every row. Set only through {@see withPage()}.
	 *
	 * @var ExportPage|null
	 */
	private ?ExportPage $page = null;

	/**
	 * Whether the export is written for the public principal. Set only through {@see forThePublic()}.
	 *
	 * @var bool
	 */
	private bool $public = false;

	/**
	 * Describe one export.
	 *
	 * @param Register|null        $register    The register exported, or null for none.
	 * @param Schema|null          $schema      The schema exported, or null for every schema of the register.
	 * @param array<string, mixed> $filters     The request filters (`@self.*` metadata, properties, `_ids`, `_multi`).
	 * @param IUser|null           $currentUser The user the export runs for; drives the visible columns.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly ?Register $register = null,
		public readonly ?Schema $schema = null,
		public readonly array $filters = [],
		public readonly ?IUser $currentUser = null,
	) {
	}//end __construct()

	/**
	 * The page to read, or null for every row.
	 *
	 * @return ExportPage|null
	 *
	 * @spec openspec/changes/export-open-formats-and-public-download/specs/data-import-export/spec.md
	 */
	public function page(): ?ExportPage {
		return $this->page;
	}//end page()

	/**
	 * The same export, read one page at a time.
	 *
	 * @param ExportPage $page The page to read.
	 *
	 * @return self A copy; this request is unchanged.
	 *
	 * @spec openspec/changes/export-open-formats-and-public-download/specs/data-import-export/spec.md
	 */
	public function withPage(ExportPage $page): self {
		$copy       = clone $this;
		$copy->page = $page;

		return $copy;
	}//end withPage()

	/**
	 * The same export, written for the public principal: no administration metadata.
	 *
	 * @return self A copy; this request is unchanged.
	 *
	 * @spec openspec/changes/export-open-formats-and-public-download/specs/data-import-export/spec.md
	 */
	public function forThePublic(): self {
		$copy         = clone $this;
		$copy->public = true;

		return $copy;
	}//end forThePublic()

	/**
	 * Whether the export is written for the public principal.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/export-open-formats-and-public-download/specs/data-import-export/spec.md
	 */
	public function isForThePublic(): bool {
		return $this->public;
	}//end isForThePublic()

	/**
	 * The request over the stored rows behind an exporter-contract request.
	 *
	 * @param ObjectExportRequest $request The request the contract received.
	 *
	 * @return self
	 *
	 * @throws \InvalidArgumentException When the register or schema is not a stored row.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public static function fromContract(ObjectExportRequest $request): self {
		return new self(
			register: StoredEntity::register(register: $request->register),
			schema: StoredEntity::schema(schema: $request->schema),
			filters: $request->filters,
			currentUser: $request->currentUser,
		);
	}//end fromContract()
}//end class
