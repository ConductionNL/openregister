<?php

/**
 * LinkFile: A file an access-link holder adds to an object.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Sharing;

/**
 * The name and content of one file {@see AccessLinkActs::upload()} stores.
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 */
final class LinkFile {

	/**
	 * Constructor.
	 *
	 * @param string $fileName The file name.
	 * @param string $content  The file content.
	 */
	public function __construct(
		public readonly string $fileName,
		public readonly string $content,
	) {
	}//end __construct()
}//end class
