<?php

/**
 * OpenRegister e-Depot Object Confirmation
 *
 * One object the e-Depot accepted, with its files, its transport result and
 * the delivery it was confirmed in.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The run of recording one accepted object: marked, audited and proven.
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
 */
final class ObjectConfirmation {
	/**
	 * The accepted object.
	 *
	 * @param ObjectEntity         $object  The confirmed object.
	 * @param mixed                $files   The object's file metadata (name + checksum); checked where the proof is built.
	 * @param array<string, mixed> $result  The per-object transport result.
	 * @param TransferContext      $context The delivery the object was confirmed in.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly mixed $files,
		public readonly array $result,
		public readonly TransferContext $context,
	) {
	}//end __construct()

	/**
	 * The e-Depot reference identifier, '' when the transport gave none.
	 *
	 * @return string The reference.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
	 */
	public function reference(): string {
		return ($this->result['reference'] ?? '');
	}//end reference()
}//end class
