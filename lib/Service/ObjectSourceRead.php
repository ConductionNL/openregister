<?php

/**
 * OpenRegister Object Source Read
 *
 * One read of an object-source schema: the provider, the schema, the query and the source config.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author	Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link	  https://www.OpenRegister.app
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceProvider;

/**
 * What ObjectService passes between the steps of an object-source page read.
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 *
 * @psalm-immutable
 */
final class ObjectSourceRead {

	/**
	 * Constructor.
	 *
	 * @param ObjectSourceProvider|null $provider The resolved provider, null when it is not registered.
	 * @param Schema                    $schema   The sourced schema.
	 * @param array                     $query    The search query in the provider contract shape.
	 * @param array                     $config   The object-source config block.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function __construct(
		public readonly ?ObjectSourceProvider $provider,
		public readonly Schema $schema,
		public readonly array $query,
		public readonly array $config,
	) {
	}//end __construct()
}//end class
