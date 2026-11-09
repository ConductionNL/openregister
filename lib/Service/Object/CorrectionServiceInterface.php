<?php

/**
 * CorrectionServiceInterface: what callers outside the component use of CorrectionService.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\CorrectionRefusedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;

/**
 * The correction the corrections endpoint applies.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface CorrectionServiceInterface {

	/**
	 * Correct one or more values on a record.
	 *
	 * @param CorrectionTarget $target The register, schema and object the correction is made on.
	 * @param array $values The properties to correct, mapped to their corrected values.
	 * @param string|null $reason Why the values are wrong. Required.
	 *
	 * @return array{object: ObjectEntity, audit: AuditTrail|null} The corrected record and its entry.
	 *
	 * @throws NotAuthorizedException When nobody is signed in, or the right is not held.
	 * @throws CorrectionRefusedException When there is no reason, or nothing to correct.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function correct(CorrectionTarget $target, array $values, ?string $reason): array;
}//end interface
