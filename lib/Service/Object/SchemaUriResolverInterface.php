<?php

/**
 * SchemaUriResolverInterface: resolves a schema from its URI.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use GuzzleHttp\Exception\GuzzleException;
use Opis\Uri\Uri;

/**
 * The schema resolution of ValidateObject.
 *
 * Callers that need only this role depend on it, not on the whole class.
 */
interface SchemaUriResolverInterface {

	/**
	 * Resolves a schema from a given URI.
	 *
	 * @param Uri $uri The URI pointing to the schema.
	 *
	 * @return string The schema content in JSON format.
	 *
	 * @throws GuzzleException If there is an error during schema fetching.
	 *
	 * @psalm-suppress PossiblyUnusedReturnValue
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function resolveSchema(Uri $uri): string;
}//end interface
