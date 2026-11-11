<?php

/**
 * ErasurePreviewStoreInterface: what callers outside the component use of ErasurePreviewStore.
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
 * The stored erasure previews the erasure endpoints record, load and approve.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface ErasurePreviewStoreInterface {

	/**
	 * Approve a preview, so an erasure may run from it.
	 *
	 * @param string $uuid The preview uuid.
	 *
	 * @return ErasurePreview The approved preview.
	 *
	 * @throws ErasureRefusedException When it is already spent, or not the caller's.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function approve(string $uuid): ErasurePreview;

	/**
	 * Load a preview the caller may see, or refuse naming the rule.
	 *
	 * @param string $uuid The preview uuid.
	 *
	 * @return ErasurePreview The preview.
	 *
	 * @throws ErasureRefusedException When it does not exist or is not the caller's.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function load(string $uuid): ErasurePreview;

	/**
	 * Keep a computed preview, so it can be approved and run from.
	 *
	 * @param array<string, mixed> $preview   The preview from ErasurePreviewService.
	 * @param string|null          $requestId The data subject request it answers, when there is one.
	 *
	 * @return ErasurePreview The recorded preview.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function record(array $preview, ?string $requestId = null): ErasurePreview;

	/**
	 * Load a preview an erasure may actually run from, or refuse.
	 *
	 * @param string $uuid The preview uuid.
	 *
	 * @return ErasurePreview The runnable preview.
	 *
	 * @throws ErasureRefusedException When it is unapproved or already spent.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function requireRunnable(string $uuid): ErasurePreview;
}//end interface
