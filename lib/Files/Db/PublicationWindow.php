<?php

/**
 * The publication window of a file.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Files\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Db;

use DateTime;

/**
 * When a file becomes public and when it stops: one fact, so both bounds travel together.
 *
 * @spec openspec/changes/file-publication-window/specs/file-publication-window/spec.md#requirement-a-file-carries-its-own-publication-window-req-fpw-101
 */
final class PublicationWindow {

	/**
	 * Constructor.
	 *
	 * @param DateTime|null $published   When the file becomes public, or null for never.
	 * @param DateTime|null $depublished When it stops, or null for no end date.
	 *
	 * @spec openspec/changes/file-publication-window/specs/file-publication-window/spec.md#requirement-a-file-carries-its-own-publication-window-req-fpw-101
	 */
	public function __construct(
		public readonly ?DateTime $published,
		public readonly ?DateTime $depublished,
	) {
	}//end __construct()
}//end class
