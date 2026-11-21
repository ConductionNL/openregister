<?php

/**
 * What the document store move did with one register.
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

/**
 * One register's counts and refusals.
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */
final class DocumentMoveOutcome {

	/**
	 * The register was switched to its copies.
	 */
	public const SWITCHED = 'switched';

	/**
	 * The register was switched by an earlier run.
	 */
	public const ALREADY_SWITCHED = 'already switched';

	/**
	 * The register was not switched; its references stay on the old ids.
	 */
	public const REFUSED = 'refused';

	/**
	 * A dry run: nothing changed.
	 */
	public const DRY_RUN = 'dry run';

	/**
	 * Files copied (or that a dry run would copy).
	 *
	 * @var integer
	 */
	public int $files = 0;

	/**
	 * Folders copied.
	 *
	 * @var integer
	 */
	public int $folders = 0;

	/**
	 * Earlier versions carried.
	 *
	 * @var integer
	 */
	public int $versions = 0;

	/**
	 * Shares recreated.
	 *
	 * @var integer
	 */
	public int $shares = 0;

	/**
	 * Objects whose references were rewritten.
	 *
	 * @var integer
	 */
	public int $objects = 0;

	/**
	 * Why the register was or would be refused, one line per file or reason.
	 *
	 * @var list<string>
	 */
	public array $refusals = [];

	/**
	 * What was recorded without refusing: files moved without their earlier versions.
	 *
	 * @var list<string>
	 */
	public array $notes = [];

	/**
	 * Constructor.
	 *
	 * @param int    $registerId The register.
	 * @param string $name       Its title, for the report.
	 * @param string $state      One of the constants.
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly int $registerId,
		public readonly string $name,
		public string $state = self::DRY_RUN,
	) {
	}//end __construct()

	/**
	 * Record a refusal; a refused register is never switched.
	 *
	 * @param string $reason What stops the register, naming the file.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function refuse(string $reason): void {
		$this->refusals[] = $reason;
		if ($this->state !== self::DRY_RUN) {
			$this->state = self::REFUSED;
		}
	}//end refuse()

	/**
	 * Whether anything stops the register.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function isRefused(): bool {
		return $this->refusals !== [];
	}//end isRefused()
}//end class
