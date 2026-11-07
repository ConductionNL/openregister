<?php

/**
 * OpenRegister InverseRenderSupport
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\MagicMapperInterface;
use Psr\Log\LoggerInterface;

/**
 * What inverse relation rendering needs from the renderer that owns it.
 *
 * The lookups stay with the renderer because it owns the request-scoped caches.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class InverseRenderSupport {

	/**
	 * Constructor.
	 *
	 * @param MagicMapperInterface $mapper  The object mapper.
	 * @param LoggerInterface      $logger  The logger.
	 * @param InverseSchemaLookups $schemas How schemas are found.
	 * @param InverseObjectHooks   $objects What happens to the objects found.
	 */
	public function __construct(
		public readonly MagicMapperInterface $mapper,
		public readonly LoggerInterface $logger,
		public readonly InverseSchemaLookups $schemas,
		public readonly InverseObjectHooks $objects,
	) {
	}//end __construct()
}//end class
