<?php

/**
 * OpenRegister DeckController services
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
use OCA\OpenRegister\Service\DeckCardServiceInterface;

/**
 * The collaborators of DeckController, wired by DI autowiring.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @spec openspec/specs/deck-integration/spec.md#requirement-a-linked-deck-card-carries-its-board-and-list
 */
final class DeckControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param DeckCardServiceInterface $deckCardService Deck card service.
	 * @param ObjectOperationsInterface $objectService OR object resolver.
	 *
	 * @spec openspec/specs/deck-integration/spec.md#requirement-a-linked-deck-card-carries-its-board-and-list
	 */
	public function __construct(
		public readonly DeckCardServiceInterface $deckCardService,
		public readonly ObjectOperationsInterface $objectService,
	) {
	}//end __construct()
}//end class
