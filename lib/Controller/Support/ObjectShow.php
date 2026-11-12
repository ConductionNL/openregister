<?php

/**
 * OpenRegister ObjectShow
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;



/**
 * One object read through show(): what was asked and what it resolved to.
 *
 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
 */
final class ObjectShow {
	/**
	 * Hold the read.
	 *
	 * @param string               $id            The object identifier.
	 * @param array{0: string, 1: string} $path  The register and schema in the URL.
	 * @param array<string, mixed> $resolved      The resolved ids and entities.
	 * @param ShowServices         $services      What is asked of the request's object service.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function __construct(
		public readonly string $id,
		public readonly array $path,
		public readonly array $resolved,
		public readonly ShowServices $services,
	) {
	}//end __construct()

	/**
	 * Whether the register and the schema both resolved to entities.
	 *
	 * @return bool True when both are there.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function hasEntities(): bool {
		return $this->resolved['registerEntity'] !== null && $this->resolved['schemaEntity'] !== null;
	}//end hasEntities()
}//end class
