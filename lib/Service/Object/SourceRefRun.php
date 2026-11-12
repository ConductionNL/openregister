<?php

/**
 * OpenRegister SourceRefRun
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
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceProvider;

/**
 * One RenderObject::extendObjectSourceRefs() pass, and the object-source target of the property it is on.
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */
final class SourceRefRun {

	/**
	 * The entity being rendered.
	 *
	 * @var ObjectEntity|null
	 */
	public ?ObjectEntity $entity = null;

	/**
	 * The extends still to handle; resolved keys leave it.
	 *
	 * @var array
	 */
	public array $extend = [];

	/**
	 * The object data, with resolved references in place.
	 *
	 * @var array
	 */
	public array $objectData = [];

	/**
	 * The current render depth.
	 *
	 * @var integer
	 */
	public int $depth = 0;

	/**
	 * The circular-reference guard.
	 *
	 * @var array
	 */
	public array $visitedIds = [];

	/**
	 * The entity's register, looked up once.
	 *
	 * @var Register|null
	 */
	public ?Register $register = null;

	/**
	 * The object-source schema the current property references.
	 *
	 * @var Schema|null
	 */
	public ?Schema $target = null;

	/**
	 * The provider that serves the current target.
	 *
	 * @var ObjectSourceProvider|null
	 */
	public ?ObjectSourceProvider $provider = null;

	/**
	 * The object-source config of the current target.
	 *
	 * @var array
	 */
	public array $config = [];
}//end class
