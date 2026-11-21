<?php

/**
 * OpenRegister QueryPlatform
 *
 * What an object query reads and renders with: the object mapper, the render handler and the app container.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCP\AppFramework\IAppContainer;

/**
 * What an object query reads and renders with: the object mapper, the render handler and the app container.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final readonly class QueryPlatform {
	/**
	 * Bundle the collaborators.
	 *
	 * @param MagicMapperInterface $objectMapper  Unified mapper for objects.
	 * @param RenderObject         $renderHandler Render handler.
	 * @param IAppContainer        $container     App container.
	 */
	public function __construct(
		public MagicMapperInterface $objectMapper,
		public RenderObject $renderHandler,
		public IAppContainer $container,
	) {
	}//end __construct()
}//end class
