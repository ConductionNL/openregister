<?php

/**
 * OpenRegister entity relations mappers
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\EmailLinkMapperInterface;

/**
 * The mappers and repositories of the entity relations API.
 *
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 */
final class EntityRelationsLinks {
	/**
	 * Bundle the services.
	 *
	 * @param EmailLinkMapperInterface $emailLinkMapper EmailLink loader (for email-bound authz indirection).
	 *
	 * @spec openspec/specs/entity-relation-grondslagen/spec.md
	 */
	public function __construct(
		public readonly EmailLinkMapperInterface $emailLinkMapper,
	) {
	}//end __construct()
}//end class
