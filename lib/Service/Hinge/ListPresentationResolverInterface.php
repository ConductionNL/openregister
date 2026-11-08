<?php

/**
 * ListPresentationResolverInterface: what callers outside lib/Service use of ListPresentationResolver.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hinge
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

namespace OCA\OpenRegister\Service\Hinge;

use OCA\OpenRegister\Db\Schema;

/**
 * The part of ListPresentationResolver that code outside lib/Service uses.
 */
interface ListPresentationResolverInterface {

	/**
	 * Resolve what a list surface should render for one schema.
	 *
	 * `declared` says whether the schema asked for this or whether it is the
	 * default: a surface that already has its own idea of the default columns
	 * can keep it, and only defer to this when the schema actually spoke.
	 *
	 * @param Schema $schema The schema whose list surface is being rendered.
	 *
	 * @return array{declared: bool, columns: array<int, array>, searchFields: array<int, string>}
	 *                                                                                            The list surface.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function resolve(Schema $schema): array;
}//end interface
