<?php

/**
 * The tasks one CalDAV VTODO read selects, and the register and schema they bind to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-source-providers/tasks.md#task-5.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

/**
 * A binding plus the scope that decides which tasks belong to it.
 *
 * @spec openspec/changes/object-source-providers/tasks.md#task-5.1
 */
final class VtodoSelection {
	/**
	 * Constructor.
	 *
	 * @param SourcedObjectBinding $binding The register and schema the tasks bind to.
	 * @param VtodoScope           $scope   Which tasks belong to that binding.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly SourcedObjectBinding $binding,
		public readonly VtodoScope $scope,
	) {
	}//end __construct()

	/**
	 * The selection a schema's object-source config describes.
	 *
	 * @param SourcedObjectBinding $binding The register and schema the tasks bind to.
	 * @param array<string, mixed> $config  The object-source config block.
	 *
	 * @return self
	 */
	public static function fromConfig(SourcedObjectBinding $binding, array $config): self {
		return new self(binding: $binding, scope: new VtodoScope(binding: $binding, config: $config));
	}//end fromConfig()
}//end class
