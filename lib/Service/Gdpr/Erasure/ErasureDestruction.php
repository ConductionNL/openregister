<?php

/**
 * ErasureDestruction — one object an approved erasure destroys.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Erasure
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr\Erasure;

use OCA\OpenRegister\Db\ErasurePreview;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * The object, its resolved schema, and the approved preview that destroys it.
 *
 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
 */
final class ErasureDestruction {
	/**
	 * Hold the destruction.
	 *
	 * @param ObjectEntity   $object The object to destroy.
	 * @param Schema|null    $schema Its schema, when it resolves.
	 * @param ErasurePreview $record The approved preview being run.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly ?Schema $schema,
		public readonly ErasurePreview $record,
	) {
	}//end __construct()

	/**
	 * The object's uuid, as the run report names it.
	 *
	 * @return string The uuid, empty when the object carries none.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function uuid(): string {
		return (string)($this->object->getUuid() ?? '');
	}//end uuid()
}//end class
