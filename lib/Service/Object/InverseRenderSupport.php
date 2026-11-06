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

use Closure;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
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
	 * The object mapper.
	 *
	 * @var MagicMapperInterface
	 */
	public MagicMapperInterface $mapper;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	public LoggerInterface $logger;

	/**
	 * The objects found to point back, per entity and property.
	 *
	 * @var InverseRelationCache
	 */
	public InverseRelationCache $cache;

	/**
	 * Finds a schema by id, through the renderer's cache.
	 *
	 * @var Closure(int|string): (Schema|null)
	 */
	public Closure $schemaLookup;

	/**
	 * Resolves a schema reference to a schema id.
	 *
	 * @var Closure(string): string
	 */
	public Closure $schemaIdLookup;

	/**
	 * Renders a related object to its full data.
	 *
	 * @var Closure(ObjectEntity): array
	 */
	public Closure $render;

	/**
	 * Keeps objects in the renderer's objects cache without replacing what is there.
	 *
	 * @var Closure(ObjectEntity[]): void
	 */
	public Closure $rememberBehind;
}//end class
