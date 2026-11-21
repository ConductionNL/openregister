<?php

/**
 * ObjectFindOptions: where to look for one object and what to return with it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Value
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

use OCA\OpenRegister\Kernel\Contracts\RegisterInterface;
use OCA\OpenRegister\Kernel\Contracts\SchemaInterface;

/**
 * The optional part of a single-object read: find() and findSilent().
 *
 * Without a register and schema the read searches every table. `render` and
 * `audit` apply to find() only; findSilent() never renders or audits.
 *
 * @spec exclude ADR-011 parameter object of the object read, not a capability of its own
 */
final class ObjectFindOptions {

	/**
	 * Constructor
	 *
	 * @param RegisterInterface|string|int|null $register The register, or its id, uuid or slug.
	 * @param SchemaInterface|string|int|null   $schema   The schema, or its id, uuid or slug.
	 * @param array|null                        $extend   Properties to extend the object with.
	 * @param bool                              $files    Include file information.
	 * @param bool                              $render   Render the entity before returning it.
	 * @param bool                              $audit    Write an audit-trail row for this read.
	 */
	public function __construct(
		public readonly RegisterInterface|string|int|null $register=null,
		public readonly SchemaInterface|string|int|null $schema=null,
		public readonly ?array $extend=[],
		public readonly bool $files=false,
		public readonly bool $render=true,
		public readonly bool $audit=true,
	) {
	}//end __construct()
}//end class
