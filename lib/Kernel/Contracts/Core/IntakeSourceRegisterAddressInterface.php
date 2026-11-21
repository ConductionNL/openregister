<?php

/**
 * IntakeSourceRegisterAddressInterface: the register and schema the intake sources are objects of.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
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

namespace OCA\OpenRegister\Kernel\Contracts\Core;

/**
 * Where the intake sources live: the register and schema slugs Core reads them from.
 *
 * The intake-source registry (Core) reads its sources at this address. The
 * repair step that seeds the register (through IntakeSourceRegisterSlugsInterface,
 * which extends this) creates it there, so both name one address.
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */
interface IntakeSourceRegisterAddressInterface {

	/**
	 * The register slug the intake sources are objects of.
	 *
	 * @var string
	 */
	public const REGISTER_SLUG = 'intake-sources';

	/**
	 * The schema slug an intake source is an object of.
	 *
	 * @var string
	 */
	public const SCHEMA_SLUG = 'intake-source';
}//end interface
