<?php

/**
 * OpenRegister render read extensions
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
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Service\FieldEncryptionHandler;
use OCA\OpenRegister\Service\Hinge\LensResolver;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry;

/**
 * The optional read-time extensions of a render: object sources, field encryption and lenses.
 *
 * Each is nullable with a default, as on the renderer before: a null one leaves
 * the data exactly as it was.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class RenderReadExtensions {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectSourceRegistry|null    $objectSourceRegistry   Resolves object-source providers for `$ref` extends into virtual schemas.
	 * @param FieldEncryptionHandler|null  $fieldEncryptionHandler Field-level encryption handler (x-openregister-encrypted).
	 * @param LensResolver|null            $lensResolver           Resolves a schema's declared lenses at read time.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		public readonly ?ObjectSourceRegistry $objectSourceRegistry = null,
		public readonly ?FieldEncryptionHandler $fieldEncryptionHandler = null,
		public readonly ?LensResolver $lensResolver = null,
	) {
	}//end __construct()
}//end class
