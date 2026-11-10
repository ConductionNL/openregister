<?php

/**
 * One purge of the upload store.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

use OCP\Files\SimpleFS\ISimpleFolder;

/**
 * The folder and the moment of one purge, and the tokens it already deleted.
 *
 * Built by FormUploadStore::purge() and passed only between its own helpers.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
 */
final class FormUploadPurgeRun {
	/**
	 * Tokens this purge already deleted.
	 *
	 * @var array<string, true>
	 */
	public array $gone = [];

	/**
	 * Constructor.
	 *
	 * @param ISimpleFolder $folder The store folder.
	 * @param int           $now    The moment of the purge.
	 */
	public function __construct(
		public readonly ISimpleFolder $folder,
		public readonly int $now,
	) {
	}//end __construct()
}//end class
