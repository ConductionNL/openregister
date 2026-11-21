<?php

/**
 * LinkedObject: the OpenRegister object a Talk room is linked to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TalkLink
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/integration-talk/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\TalkLink;

/**
 * The object uuid, register id and schema id a Talk link row points at.
 *
 * ADR-011 Rule 2: a component-local value object for the cluster that
 * linkRoom() and createAndLinkRoom() share.
 *
 * @spec openspec/specs/integration-talk/spec.md
 */
final class LinkedObject {

	/**
	 * Describe it.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 * @param int    $registerId OR register id.
	 * @param int    $schemaId   OR schema id.
	 */
	public function __construct(
		public readonly string $objectUuid,
		public readonly int $registerId,
		public readonly int $schemaId,
	) {
	}//end __construct()

	/**
	 * The object columns of a link row, as TalkLinkFactory::newLink() reads them.
	 *
	 * @return array{objectUuid: string, registerId: int, schemaId: int} The columns.
	 *
	 * @spec openspec/specs/integration-talk/spec.md
	 */
	public function fields(): array {
		return ['objectUuid' => $this->objectUuid, 'registerId' => $this->registerId, 'schemaId' => $this->schemaId];

	}//end fields()
}//end class
