<?php

/**
 * DatabaseIntrospectionServiceInterface: what callers outside the component use of DatabaseIntrospectionService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Dbal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Dbal;

use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Service\Schema\SchemaDiffService;

/**
 * Introspect an external database source into a virtual register.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface DatabaseIntrospectionServiceInterface {

	/**
	 * Introspect a source and persist the register + schemas idempotently.
	 *
	 * A second run over the same source updates the existing register and
	 * schemas (matched by source id / table name) and logs structural drift via
	 * {@see SchemaDiffService}; it never creates duplicates.
	 *
	 * @param Source $source The `type: database` source to introspect.
	 *
	 * @return array{register: int, created: array<int, string>, updated: array<int, string>, drift: array<int, array<string, mixed>>}
	 *
	 * @throws DbalConnectionException When the source cannot be connected.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function introspect(Source $source): array;
}//end interface
