<?php

/**
 * OpenRegister Deck Card Run
 *
 * One creation of a Deck card that is linked to an object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * The object, the card, Deck's card service and the card's owner for one card creation.
 *
 * Built by DeckLinkService::createAndLinkCard once the user and Deck are
 * known; it never leaves that class.
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */
final class DeckCardRun {

	/**
	 * Deck's CardService, set once Deck is known to be available.
	 *
	 * @var object
	 */
	public object $cardService;

	/**
	 * The UID of the user who creates and owns the card.
	 *
	 * @var string
	 */
	public string $ownerUid = '';

	/**
	 * Constructor.
	 *
	 * @param LinkTarget $target The object the card is linked to.
	 * @param CardDraft  $card   The card to create.
	 */
	public function __construct(
		public readonly LinkTarget $target,
		public readonly CardDraft $card,
	) {
	}//end __construct()
}//end class
