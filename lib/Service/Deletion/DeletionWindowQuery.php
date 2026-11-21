<?php

/**
 * DeletionWindowQuery — which object's deletion window to read, and as of when.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Deletion
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Deletion;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * The question "what is the deletion window of this object": the object, its
 * schema when it resolves, and the reference moment (now when omitted).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Deletion
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */
final class DeletionWindowQuery {
	/**
	 * Name the query.
	 *
	 * @param ObjectEntity           $object The object to describe.
	 * @param Schema|null            $schema The object's schema, when it resolves.
	 * @param DateTimeImmutable|null $now    Reference moment, now when omitted.
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly ?Schema $schema=null,
		public readonly ?DateTimeImmutable $now=null,
	) {
	}//end __construct()
}//end class
