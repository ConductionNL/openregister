<?php

/**
 * OpenRegister access link reader sources
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\Sharing;

use OCA\OpenRegister\Files\Service\File\FileRestrictionStore;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Sharing\AccessLinkSubjectInterface;

/**
 * What an access link reader reads: the object path, the subject parser and the file restrictions.
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 */
final class AccessLinkReaderSources {
	/**
	 * Bundle the collaborators.
	 *
	 * @param ObjectOperationsInterface  $objects      The object read path.
	 * @param AccessLinkSubjectInterface $subjects     Reads which object a subject names.
	 * @param FileRestrictionStore|null  $restrictions Refuses a restricted file to a link. Null only in legacy fixtures.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
	 */
	public function __construct(
		public readonly ObjectOperationsInterface $objects,
		public readonly AccessLinkSubjectInterface $subjects,
		public readonly ?FileRestrictionStore $restrictions = null,
	) {
	}//end __construct()
}//end class
