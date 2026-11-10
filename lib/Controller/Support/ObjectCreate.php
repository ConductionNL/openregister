<?php

/**
 * OpenRegister ObjectCreate
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


use Closure;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCA\OpenRegister\Service\Object\ObjectFindTarget;
use OCA\OpenRegister\Service\Object\SavePolicy;

/**
 * One create: the submitted object, where it goes and how it was asked for.
 *
 * @spec openspec/specs/objects-crud/spec.md
 */
final class ObjectCreate {
	/**
	 * Hold the create.
	 *
	 * @param array<string, mixed>      $object        What may be saved.
	 * @param array<string, mixed>      $resolved      The resolved ids and entities.
	 * @param ObjectOperationsInterface $objectService The request's object service.
	 * @param ObjectCreateControls      $controls      The controls of the request.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function __construct(
		public readonly array $object,
		public readonly array $resolved,
		public readonly ObjectOperationsInterface $objectService,
		public readonly ObjectCreateControls $controls,
	) {
	}//end __construct()

	/**
	 * The save of this body, taking the uuid to save under.
	 *
	 * @param array<string, mixed> $body The body to save.
	 *
	 * @return Closure(?string): ObjectEntity The save.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function saver(array $body): Closure {
		return fn (?string $uuid): ObjectEntity => $this->objectService->saveObject(
			object: $body,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: $this->resolved['register'], schema: $this->resolved['schema']),
				uuid: $uuid,
				uploadedFiles: $this->controls->caller->uploads(),
				policy: new SavePolicy(failIfExists: $this->controls->failIfExists, dedupOverride: $this->controls->dedupOverride)
			),
			_rbac: $this->controls->caller->scoped,
			_multitenancy: true
		);
	}//end saver()
}//end class
