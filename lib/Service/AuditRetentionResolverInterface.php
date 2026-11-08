<?php

/**
 * AuditRetentionResolverInterface: what callers outside the component use of AuditRetentionResolver.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Declares only what callers outside the component use of AuditRetentionResolver; the rest
 * of the class stays its own.
 */
interface AuditRetentionResolverInterface {

	/**
	 * Resolve the audit-row expiry for an object.
	 *
	 * @param ObjectEntity $object The object the audit row describes.
	 * @param DateTimeInterface $createdAt The audit row's creation instant.
	 *
	 * @return array{expires: \DateTimeImmutable|null, source: string} The
	 *                                                                 resolved expiry (null meaning "retain indefinitely") and the
	 *                                                                 machine-readable token explaining which branch produced it.
	 *
	 */
	public function resolve(ObjectEntity $object, DateTimeInterface $createdAt): array;
}//end interface
