<?php

/**
 * OpenRegister CalculationPayload
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/computed-fields/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation;

use DateTimeInterface;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The `@self` values a calculation expression may refer to.
 *
 * @spec openspec/specs/computed-fields/spec.md
 */
final class CalculationPayload {

	/**
	 * The values that say which object this is.
	 *
	 * @param ObjectEntity $entity The object.
	 *
	 * @return array<string, mixed> The identity values.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function identity(ObjectEntity $entity): array {
		return [
			'id' => $entity->getUuid(),
			'uuid' => $entity->getUuid(),
			'register' => $entity->getRegister(),
			'schema' => $entity->getSchema(),
			'owner' => $entity->getOwner(),
		];
	}//end identity()

	/**
	 * The data with the object's identity and timestamps under `@self`.
	 *
	 * @param array<string, mixed> $data   The object data.
	 * @param ObjectEntity         $entity The object.
	 *
	 * @return array<string, mixed> The payload.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function withSelf(array $data, ObjectEntity $entity): array {
		$data['@self'] = $this->identity(entity: $entity) + [
			'created' => $entity->getCreated()?->format(DateTimeInterface::ATOM),
			'updated' => $entity->getUpdated()?->format(DateTimeInterface::ATOM),
		];

		return $data;
	}//end withSelf()
}//end class
