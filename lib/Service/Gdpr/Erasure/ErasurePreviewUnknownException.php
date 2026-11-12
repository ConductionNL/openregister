<?php

/**
 * ErasurePreviewUnknownException — the erasure preview asked for does not exist, or is not the caller's.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Gdpr\Erasure
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr\Erasure;

use Throwable;

/**
 * The one erasure refusal that answers 404 rather than 409.
 *
 * A preview that does not exist and a preview the caller may not reach are
 * the same answer, so a caller cannot probe for other people's previews.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Erasure
 *
 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
 */
class ErasurePreviewUnknownException extends ErasureRefusedException {
	/**
	 * The status an unknown preview answers with.
	 *
	 * @var int
	 */
	private const STATUS_NOT_FOUND = 404;

	/**
	 * Build the refusal.
	 *
	 * @param Throwable|null $previous Why the lookup failed, when it did.
	 *
	 * @return void
	 */
	public function __construct(?Throwable $previous = null) {
		parent::__construct(
			rule: 'erasure-preview-unknown',
			reason: 'No erasure preview with that identifier exists.',
			previous: $previous
		);
		$this->code = self::STATUS_NOT_FOUND;
	}//end __construct()
}//end class
