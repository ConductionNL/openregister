<?php

/**
 * OpenRegister scopes controller services
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

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * The register and schema lookups the scopes endpoint lists from.
 *
 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-5
 */
final readonly class ScopesControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param RegisterMapperInterface $registerMapper Register lookup.
	 * @param SchemaMapperInterface   $schemaMapper   Schema lookup.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-5
	 */
	public function __construct(
		public RegisterMapperInterface $registerMapper,
		public SchemaMapperInterface $schemaMapper,
	) {
	}//end __construct()
}//end class
