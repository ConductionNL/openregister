<?php

/**
 * OpenRegister SchemaTeardownOverride
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-a-schema-teardown-refuses-kept-rows-unless-forced-on-the-command-line
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * What an operator lifted for one schema teardown.
 *
 * Only `occ openregister:schemas:prune-retired` builds one with a lift: shell access
 * is an authorization boundary an HTTP request cannot cross.
 *
 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-a-schema-teardown-refuses-kept-rows-unless-forced-on-the-command-line
 */
final class SchemaTeardownOverride {

	/**
	 * @param bool $archivalSchema Destroy the objects of a schema declaring `x-openregister-archival`.
	 * @param bool $keptRows       Destroy frozen, archived and locked rows.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Each lift is the operator's own flag.
	 */
	public function __construct(
		public readonly bool $archivalSchema = false,
		public readonly bool $keptRows = false,
	) {
	}//end __construct()
}//end class
