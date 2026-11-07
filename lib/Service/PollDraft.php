<?php

/**
 * OpenRegister Poll Draft
 *
 * The content of a Polls poll that is about to be created.
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
use DateTimeInterface;
use Throwable;

/**
 * Title, description, type, options and deadline of a new poll, plus its owner and
 * creation time once those are known. Immutable: each with* method returns a copy.
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */
final class PollDraft {

	/**
	 * The poll type as given, before normalisation.
	 *
	 * @var string
	 */
	private string $type = 'textPoll';

	/**
	 * Option labels.
	 *
	 * @var array<int, string>
	 */
	private array $options = [];

	/**
	 * Optional deadline.
	 *
	 * @var DateTimeInterface|null
	 */
	private ?DateTimeInterface $deadline = null;

	/**
	 * Owner user id; '' until ownedBy() is called.
	 *
	 * @var string
	 */
	private string $owner = '';

	/**
	 * Creation timestamp; 0 until ownedBy() is called.
	 *
	 * @var integer
	 */
	private int $created = 0;

	/**
	 * Constructor.
	 *
	 * @param string $title       Poll title.
	 * @param string $description Poll description.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function __construct(
		public readonly string $title,
		public readonly string $description = '',
	) {
	}//end __construct()

	/**
	 * A copy with the given poll type (`textPoll`, `datePoll` or a short form).
	 *
	 * @param string $type The poll type.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function withType(string $type): self {
		$copy       = clone $this;
		$copy->type = $type;
		return $copy;
	}//end withType()

	/**
	 * A copy with the given options and deadline.
	 *
	 * @param array<int, string>     $options  Option labels.
	 * @param DateTimeInterface|null $deadline Optional deadline.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function withOptions(array $options, ?DateTimeInterface $deadline): self {
		$copy           = clone $this;
		$copy->options  = $options;
		$copy->deadline = $deadline;
		return $copy;
	}//end withOptions()

	/**
	 * A copy owned by the given user and created at the given time.
	 *
	 * @param string $owner   Owner user id.
	 * @param int    $created Creation timestamp.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function ownedBy(string $owner, int $created): self {
		$copy          = clone $this;
		$copy->owner   = $owner;
		$copy->created = $created;
		return $copy;
	}//end ownedBy()

	/**
	 * The poll type in Polls' canonical form: `text`/`textPoll` and an empty type give
	 * `textPoll`, `date`/`datePoll` give `datePoll` (case-insensitive), anything else is kept.
	 *
	 * @return string The canonical type.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function pollType(): string {
		$lower = strtolower(trim($this->type));
		if (in_array($lower, ['text', 'textpoll'], true) === true || $this->type === '') {
			return 'textPoll';
		}

		if (in_array($lower, ['date', 'datepoll'], true) === true) {
			return 'datePoll';
		}

		return $this->type;
	}//end pollType()

	/**
	 * The title cut to the 128 characters Polls stores.
	 *
	 * @return string The stored title.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function storedTitle(): string {
		return mb_substr($this->title, 0, 128);
	}//end storedTitle()

	/**
	 * Option labels.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function options(): array {
		return $this->options;
	}//end options()

	/**
	 * The deadline as a Unix timestamp, 0 without one.
	 *
	 * @return int The expire timestamp.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function expire(): int {
		if ($this->deadline === null) {
			return 0;
		}

		return $this->deadline->getTimestamp();
	}//end expire()

	/**
	 * The deadline as a DateTime, null without one.
	 *
	 * @return DateTime|null The deadline.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function deadlineDateTime(): ?DateTime {
		if ($this->deadline === null) {
			return null;
		}

		try {
			return new DateTime('@' . $this->deadline->getTimestamp());
		} catch (Throwable $e) {
			return null;
		}
	}//end deadlineDateTime()

	/**
	 * Owner user id.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function owner(): string {
		return $this->owner;
	}//end owner()

	/**
	 * Creation timestamp.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function created(): int {
		return $this->created;
	}//end created()
}//end class
