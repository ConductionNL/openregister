<?php

/**
 * RenderRun
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * One entity going through RenderObject's render phases.
 *
 * The phases read the entity, the render options and the schema from here and
 * rewrite `data` as they go. It never leaves RenderObject.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class RenderRun {

	/**
	 * The object data as the phases have shaped it so far.
	 *
	 * @var array
	 */
	public array $data = [];

	/**
	 * The schema the current phase reads, null when it cannot be loaded.
	 *
	 * @var Schema|null
	 */
	public ?Schema $schema = null;

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity  $entity  The entity being rendered
	 * @param RenderContext $context The render options; the extend list is normalised mid-run
	 */
	public function __construct(
		public readonly ObjectEntity $entity,
		public RenderContext $context,
	) {
	}//end __construct()
}//end class
