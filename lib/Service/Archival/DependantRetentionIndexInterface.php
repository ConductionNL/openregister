<?php

/**
 * DependantRetentionIndexInterface: what callers outside the component use of DependantRetentionIndex.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
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

namespace OCA\OpenRegister\Service\Archival;

use OCA\OpenRegister\Db\Schema;

/**
 * The schemas whose destruction date reads a date off another record.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface DependantRetentionIndexInterface {

	/**
	 * The dependants of a source schema.
	 *
	 * @param Schema $source The schema of the record that changed
	 *
	 * @return list<array{schema: Schema, relation: string, property: string}> Each dependant schema,
	 *         the property on it holding the reference, and the date property it reads on the source
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-the-destruction-date-follows-its-source-date-under-every-method
	 */
	public function dependantsOf(Schema $source): array;

	/**
	 * The source date properties that, when changed on a record of this
	 * schema, move another record's destruction date.
	 *
	 * @param Schema $source The schema of the record that changed
	 *
	 * @return list<string> The property names, without duplicates
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-the-destruction-date-follows-its-source-date-under-every-method
	 */
	public function watchedPropertiesOf(Schema $source): array;
}//end interface
