<?php

/**
 * ErasureRunnerInterface: what callers outside the component use of ErasureRunner.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Erasure
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

namespace OCA\OpenRegister\Service\Gdpr\Erasure;

use OCA\OpenRegister\Db\ErasurePreview;

/**
 * The erasure run the erasure endpoints start.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface ErasureRunnerInterface {

	/**
	 * Run an approved preview.
	 *
	 * @param ErasurePreview $record The approved, unspent preview.
	 *
	 * @return array<string, mixed> What the run did, per bucket.
	 *
	 * @throws ErasureRefusedException When the approved preview no longer describes the world.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function run(ErasurePreview $record): array;
}//end interface
