<?php

/**
 * OpenRegister LinkedItemsTrait
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * The linked Nextcloud items an entity carries: mail, contacts, notes, todos,
 * calendar events, Talk conversations and Deck cards.
 *
 * The using entity registers each as a json field.
 *
 * @spec exclude shared entity plumbing, not a capability of its own
 */
trait LinkedItemsTrait {

	/**
	 * Linked mail entity IDs.
	 *
	 * @var array|null Linked mail entity IDs
	 */
	protected ?array $mail = null;

	/**
	 * Linked contact entity IDs.
	 *
	 * @var array|null Linked contact entity IDs
	 */
	protected ?array $contacts = null;

	/**
	 * Linked note entity IDs.
	 *
	 * @var array|null Linked note entity IDs
	 */
	protected ?array $notes = null;

	/**
	 * Linked todo entity IDs.
	 *
	 * @var array|null Linked todo entity IDs
	 */
	protected ?array $todos = null;

	/**
	 * Linked calendar event entity IDs.
	 *
	 * @var array|null Linked calendar event entity IDs
	 */
	protected ?array $calendar = null;

	/**
	 * Linked Talk conversation IDs.
	 *
	 * @var array|null Linked Talk conversation IDs
	 */
	protected ?array $talk = null;

	/**
	 * Linked Deck card IDs.
	 *
	 * @var array|null Linked Deck card IDs
	 */
	protected ?array $deck = null;
}//end trait
