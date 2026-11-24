<?php

/**
 * AccessLinkInterface: what the access-link reader needs from a link.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Kernel
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * The subject an access link opens.
 */
interface AccessLinkInterface {

	/**
	 * A link over one object.
	 *
	 * @var string
	 */
	public const SUBJECT_OBJECT = 'object';

	/**
	 * A link over one saved view.
	 *
	 * @var string
	 */
	public const SUBJECT_VIEW = 'view';

	/**
	 * A link over one file the object carries.
	 *
	 * @var string
	 */
	public const SUBJECT_FILE = 'file';

	/**
	 * The kind of subject the link opens.
	 *
	 * @return string|null The subject type.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
	 */
	public function getSubjectType(): ?string;

	/**
	 * The identifier of the subject the link opens.
	 *
	 * @return string|null The subject id.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
	 */
	public function getSubjectId(): ?string;

	/**
	 * The account that minted the link: a view link reads as that account.
	 *
	 * @return string|null The user id, or null when unknown.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
	 */
	public function getCreatedBy(): ?string;
}//end interface
