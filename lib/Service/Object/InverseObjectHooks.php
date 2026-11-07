<?php

/**
 * OpenRegister InverseObjectHooks
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
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * What inverse relation rendering does with the objects that point back.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class InverseObjectHooks {

	/**
	 * Constructor.
	 *
	 * @param InverseRelationCache           $cache          The objects found to point back.
	 * @param Closure(ObjectEntity): array   $render         Renders a related object to its full data.
	 * @param Closure(ObjectEntity[]): void  $rememberBehind Keeps objects in the renderer's cache without replacing any.
	 * @param Closure(ObjectEntity[]): array $readable       Keeps the objects the caller may read.
	 */
	public function __construct(
		public readonly InverseRelationCache $cache,
		public readonly Closure $render,
		public readonly Closure $rememberBehind,
		public readonly Closure $readable,
	) {
	}//end __construct()
}//end class
