<?php

/**
 * MasterDataAnnotationValidators: builds the master-data annotation validators for callers outside lib/Service.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Quality
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/archive/2026-06-23-mdm-foundation/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Quality;

use OCA\OpenRegister\Service\Merge\MergeAnnotationValidator;
use OCA\OpenRegister\Service\Merge\MergeAnnotationValidatorInterface;

/**
 * Builds the quality, dedup and merge annotation validators.
 *
 * The schema save path in lib/Db builds a fresh validator per check; it gets
 * them here, typed as their interfaces, so the classes stay inside lib/Service.
 *
 * @spec openspec/changes/archive/2026-06-23-mdm-foundation/tasks.md
 */
final class MasterDataAnnotationValidators {
	/**
	 * The `x-openregister-quality` validator.
	 *
	 * @return QualityAnnotationValidatorInterface A new validator.
	 *
	 * @spec openspec/changes/archive/2026-06-23-mdm-foundation/tasks.md#task-3
	 */
	public static function quality(): QualityAnnotationValidatorInterface {
		return new QualityAnnotationValidator();
	}//end quality()

	/**
	 * The `x-openregister-dedup` validator.
	 *
	 * @return DedupAnnotationValidatorInterface A new validator.
	 *
	 * @spec openspec/changes/archive/2026-06-23-mdm-foundation/tasks.md#task-5
	 */
	public static function dedup(): DedupAnnotationValidatorInterface {
		return new DedupAnnotationValidator();
	}//end dedup()

	/**
	 * The `x-openregister-merge` validator.
	 *
	 * @return MergeAnnotationValidatorInterface A new validator.
	 *
	 * @spec openspec/changes/archive/2026-07-03-mdm-merge-engine/tasks.md#1.2
	 */
	public static function merge(): MergeAnnotationValidatorInterface {
		return new MergeAnnotationValidator();
	}//end merge()
}//end class
