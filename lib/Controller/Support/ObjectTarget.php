<?php

/**
 * OpenRegister ObjectTarget
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


use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;

/**
 * The object a write aims at: the id in the URL and the scope it resolved to.
 *
 * @spec openspec/specs/objects-crud/spec.md
 */
final class ObjectTarget {
	/**
	 * Hold the target.
	 *
	 * @param string                    $id            The identifier in the URL.
	 * @param array<string, mixed>      $resolved      The resolved ids and entities.
	 * @param ObjectOperationsInterface $objectService The request's object service.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function __construct(
		public readonly string $id,
		public readonly array $resolved,
		public readonly ObjectOperationsInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Whether an object belongs to the register and schema the service resolved.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return bool True when both match.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function holds(ObjectEntity $object): bool {
		return (int)$object->getRegister() === $this->objectService->getRegister()
			&& (int)$object->getSchema() === $this->objectService->getSchema();
	}//end holds()
}//end class
