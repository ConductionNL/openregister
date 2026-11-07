<?php

/**
 * OpenRegister SavedObjectScope
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
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use Throwable;

/**
 * A saved object with the register and schema it was saved under.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class SavedObjectScope {

	/**
	 * Hold the saved object and its scope.
	 *
	 * @param ObjectEntity $object     The saved object.
	 * @param int|null     $registerId The id of the register it was saved under.
	 * @param int|null     $schemaId   The id of the schema it was saved under.
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly ?int $registerId,
		public readonly ?int $schemaId
	) {
	}//end __construct()

	/**
	 * The log context of a failure that concerns this object.
	 *
	 * @param Throwable $failure The failure.
	 *
	 * @return array<string, mixed> The exception message with the object's identity.
	 */
	public function logContext(Throwable $failure): array {
		return [
			'exception' => $failure->getMessage(),
			'uuid'      => $this->object->getUuid(),
			'register'  => $this->registerId,
			'schema'    => $this->schemaId,
		];
	}//end logContext()
}//end class
