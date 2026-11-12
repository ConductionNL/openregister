<?php

/**
 * TranslationHandlerInterface: what callers outside the Service component ask of TranslationHandler.
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

use OCA\OpenRegister\Db\Schema;

/**
 * Which properties of a schema are translatable, as the translation projection
 * listener asks it. Resolving and normalising translations stays in the class.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
interface TranslationHandlerInterface {

	/**
	 * Get the list of translatable property names from a schema.
	 *
	 * @param Schema $schema The schema to inspect
	 *
	 * @return string[] Array of translatable property names
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getTranslatableProperties(Schema $schema): array;
}//end interface
