<?php

/**
 * OpenRegister Card Draft
 *
 * A Deck card that is about to be created for an object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author	Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link	  https://www.OpenRegister.app
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use Throwable;

/**
 * The board, list, title, description and due date of a card to create.
 */
final class CardDraft {

	/**
	 * Board id as given by the caller.
	 *
	 * @var integer
	 */
	private int $boardId = 0;

	/**
	 * Description; null for none.
	 *
	 * @var string|null
	 */
	private ?string $description = null;

	/**
	 * Due date as given; null for none.
	 *
	 * @var string|null
	 */
	private ?string $duedate = null;

	/**
	 * Constructor.
	 *
	 * @param string $title   Card title.
	 * @param int    $stackId Deck list (stack) id.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function __construct(
		public readonly string $title,
		public readonly int $stackId,
	) {
	}//end __construct()

	/**
	 * A copy on the given board.
	 *
	 * @param int $boardId The board id.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function onBoard(int $boardId): self {
		$copy          = clone $this;
		$copy->boardId = $boardId;
		return $copy;
	}//end onBoard()

	/**
	 * A copy with the given description and due date.
	 *
	 * @param string|null $description Description; null for none.
	 * @param string|null $duedate     Due date; null for none.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function withDetails(?string $description, ?string $duedate): self {
		$copy              = clone $this;
		$copy->description = $description;
		$copy->duedate     = $duedate;
		return $copy;
	}//end withDetails()

	/**
	 * The board id as given by the caller.
	 *
	 * @return int The board id.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function boardId(): int {
		return $this->boardId;
	}//end boardId()

	/**
	 * The due date as given.
	 *
	 * @return string|null The due date.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function duedate(): ?string {
		return $this->duedate;
	}//end duedate()

	/**
	 * The description followed by a markdown link back to the object.
	 *
	 * @param string $objectUuid The object's uuid.
	 *
	 * @return string The card description.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function descriptionFor(string $objectUuid): string {
		$full = (string)$this->description;
		if ($full !== '') {
			$full .= "\n\n";
		}

		return $full . '[Object: ' . $objectUuid . '](/apps/openregister/objects/' . $objectUuid . ')';
	}//end descriptionFor()

	/**
	 * The due date as a date, or null when none or unparseable.
	 *
	 * @return DateTime|null The due date.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function dueDateTime(): ?DateTime {
		if ($this->duedate === null || $this->duedate === '') {
			return null;
		}

		try {
			return new DateTime($this->duedate);
		} catch (Throwable $e) {
			return null;
		}
	}//end dueDateTime()
}//end class
