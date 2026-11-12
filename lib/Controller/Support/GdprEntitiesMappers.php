<?php

/**
 * OpenRegister gdpr entities mappers
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

use OCA\OpenRegister\Files\Db\EntityRelationMapperInterface;
use OCA\OpenRegister\Db\GdprEntityMapperInterface;

/**
 * The mappers and repositories of the gdpr entities API.
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */
final class GdprEntitiesMappers {
	/**
	 * Bundle the services.
	 *
	 * @param GdprEntityMapperInterface     $entityMapper         GDPR entity mapper
	 * @param EntityRelationMapperInterface $entityRelationMapper Entity relation mapper
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	public function __construct(
		public readonly GdprEntityMapperInterface $entityMapper,
		public readonly EntityRelationMapperInterface $entityRelationMapper,
	) {
	}//end __construct()
}//end class
