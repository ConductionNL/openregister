<?php

/**
 * The object a new file is attached to.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The object (or its id) and, when the object does not name it, its register.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class FileTarget {
	/**
	 * Constructor.
	 *
	 * @param ObjectEntity|string $object     The object entity, or its id.
	 * @param int|string|null     $registerId The register of the object, when the object does not name it.
	 */
	public function __construct(
		public readonly ObjectEntity|string $object,
		public readonly int|string|null $registerId=null,
	) {
	}//end __construct()
}//end class
