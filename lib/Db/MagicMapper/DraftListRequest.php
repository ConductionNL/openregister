<?php

/**
 * DraftListRequest
 *
 * What decides the draft predicate of one list.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Db\Schema;

/**
 * What the draft lens of one list is decided from: the request query (its
 * `_drafts` parameter and status filters), the schema listed (its draft list
 * default) and the caller as a ready SQL literal or bound parameter, whose own
 * drafts stay in view (ADR-011 Rule 2, value object).
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */
final class DraftListRequest
{

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $query  The request query.
	 * @param Schema|null          $schema The schema listed; null for no schema default.
	 * @param string|null          $owner  The caller, quoted or bound; null when anonymous.
	 */
	public function __construct(
		public readonly array $query,
		public readonly ?Schema $schema,
		public readonly ?string $owner,
	) {
	}//end __construct()
}//end class
