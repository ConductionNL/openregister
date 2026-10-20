<?php

/**
 * OpenRegister PackSlugExistsException
 *
 * Raised when a create or an update would store a migration pack under a slug
 * (the pack document's own `id`) that another pack already has.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
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

namespace OCA\OpenRegister\Exception;

/**
 * A definition refused because its slug is taken by another pack.
 *
 * Extends InvalidPackDefinitionException (and so InvalidArgumentException),
 * so callers that already treat a refused definition as a 422 keep doing so;
 * the error responder tells it apart to answer `pack-slug-exists`.
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md#the-system-must-validate-migration-pack-definitions-structurally-before-storing-them
 */
class PackSlugExistsException extends InvalidPackDefinitionException {
	/**
	 * Constructor.
	 *
	 * @param string $packSlug The slug that is taken.
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md#the-system-must-validate-migration-pack-definitions-structurally-before-storing-them
	 */
	public function __construct(string $packSlug) {
		parent::__construct(errors: ['A migration pack with id "' . $packSlug . '" already exists']);
	}//end __construct()
}//end class
