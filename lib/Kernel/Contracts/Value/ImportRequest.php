<?php

/**
 * OpenRegister ImportRequest
 *
 * One file import: the file, where its rows go, how they are saved and how they are mapped.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Value
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

namespace OCA\OpenRegister\Kernel\Contracts\Value;

/**
 * One file import (ADR-011 Rule 2 operation object). The guard flags `_rbac`
 * and `_multitenancy` are never part of it: they stay named parameters.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ImportRequest {
	/**
	 * Build the request.
	 *
	 * @param string        $filePath The path to the uploaded file.
	 * @param ImportScope   $scope    The register and schema to import into, and the importing user.
	 * @param ImportOptions $options  How rows are saved.
	 * @param ImportMapping $mapping  How rows are mapped, and whether they are saved at all.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly string $filePath,
		public readonly ImportScope $scope = new ImportScope(),
		public readonly ImportOptions $options = new ImportOptions(),
		public readonly ImportMapping $mapping = new ImportMapping(),
	) {
	}//end __construct()
}//end class
