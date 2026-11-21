<?php
/**
 * ShareLinkUrlInterface: the public link of a share token, as the file service formats it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

/**
 * The public link of a share, which the file service (Core) puts on a file it formats.
 *
 * The share link builder (Files) answers it and binds the answer in its own registrar.
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-a-file-link-points-at-the-host-the-visitor-used
 */
interface ShareLinkUrlInterface {

	/**
	 * The public link of a share token.
	 *
	 * @param string $token The share token.
	 *
	 * @return string The absolute share link, without a trailing `/download`.
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-a-file-link-points-at-the-host-the-visitor-used
	 */
	public function shareLink(string $token): string;
}//end interface
