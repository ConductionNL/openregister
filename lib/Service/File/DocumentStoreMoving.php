<?php

/**
 * A write into a register whose documents are being moved.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use RuntimeException;

/**
 * Thrown before anything is written; answered with 503 `document-store-move`.
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */
final class DocumentStoreMoving extends RuntimeException {

	/**
	 * The error the API answers with.
	 */
	public const ERROR = 'document-store-move';

	/**
	 * The HTTP status the API answers with.
	 */
	public const STATUS = 503;

	/**
	 * Constructor.
	 *
	 * @param int $registerId The register being moved.
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function __construct(public readonly int $registerId) {
		parent::__construct(message: self::ERROR, code: self::STATUS);
	}//end __construct()
}//end class
