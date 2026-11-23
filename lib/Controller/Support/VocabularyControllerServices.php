<?php

/**
 * OpenRegister VocabularyController services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Vocabulary\CodedOptionsBuilderInterface;
use OCA\OpenRegister\Service\Vocabulary\VocabularyDeclarationResolverInterface;

/**
 * The collaborators of VocabularyController, wired by DI autowiring.
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */
final class VocabularyControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectOperationsInterface              $objectService       OR object read path (findAll, real API only).
	 * @param CodedOptionsBuilderInterface           $options             Builds a coded property option list or option tree.
	 * @param VocabularyDeclarationResolverInterface $declarationResolver Resolves the coded declaration a read is about.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function __construct(
		public readonly ObjectOperationsInterface $objectService,
		public readonly CodedOptionsBuilderInterface $options,
		public readonly VocabularyDeclarationResolverInterface $declarationResolver,
	) {
	}//end __construct()
}//end class
