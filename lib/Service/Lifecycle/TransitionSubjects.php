<?php

/**
 * The objects a transition reads and writes, with their schemas and registers, wired by DI autowiring.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;

/**
 * The objects a transition reads and writes, with their schemas and registers, wired by DI autowiring.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class TransitionSubjects {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectOperationsInterface   Reads and saves the object.
	 * @param SchemaMapperInterface        Resolves the object's schema and its lifecycle.
	 * @param RegisterMapperInterface    Resolves the object's register.
	 */
	public function __construct(
		public readonly ObjectOperationsInterface $objectService,
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly RegisterMapperInterface $registerMapper,
	) {
	}//end __construct()
}//end class
