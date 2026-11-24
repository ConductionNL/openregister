<?php
/**
 * FileTagCatalogueInterface: the system-wide tag list, as the tags API asks it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

/**
 * The tag catalogue behind the tags API's tag list.
 *
 * The tags API (Core) asks for it; file storage keeps the catalogue, implements this
 * through FileServiceInterface and binds it in its own registrar.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
interface FileTagCatalogueInterface {

	/**
	 * Every tag in the system except the object tags, sorted by name.
	 *
	 * @throws \Exception If there's an error retrieving the tags.
	 *
	 * @return string[]
	 *
	 * @psalm-return   list<string>
	 * @phpstan-return array<int, string>
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function getAllTags(): array;
}//end interface
