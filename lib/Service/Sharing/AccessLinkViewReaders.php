<?php

/**
 * The readers the mint guard resolves a view, and what it names, through.
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

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Search\Db\ViewMapperInterface;

/**
 * The view, register and schema mappers, each asked under the caller's own rules.
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-publication-link-opens-one-object-view-or-file-as-its-own-principal-req-abl-001
 */
final readonly class AccessLinkViewReaders {

	/**
	 * Bundle the collaborators.
	 *
	 * @param ViewMapperInterface $views Resolves a view under the caller's own rules.
	 * @param RegisterMapperInterface $registers Resolves the registers a view names, same rules.
	 * @param SchemaMapperInterface $schemas Resolves the schemas a view names, same rules.
	 */
	public function __construct(
		public ViewMapperInterface $views,
		public RegisterMapperInterface $registers,
		public SchemaMapperInterface $schemas,
	) {
	}//end __construct()
}//end class
