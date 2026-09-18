<?php

/**
 * OpenRegister NoteVersion Entity
 *
 * One text a note used to carry, kept beside the Nextcloud comment that
 * replaced it. The row holds the previous message, the actor it was
 * attributed to, the user who replaced it and the time of the edit.
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
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/note-edit-history/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * NoteVersion entity: a note's text before an edit replaced it.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method int getCommentId()
 * @method void setCommentId(int $commentId)
 * @method string|null getMessage()
 * @method void setMessage(?string $message)
 * @method string|null getAuthor()
 * @method void setAuthor(?string $author)
 * @method string|null getAuthorType()
 * @method void setAuthorType(?string $authorType)
 * @method string|null getEditedBy()
 * @method void setEditedBy(?string $editedBy)
 * @method DateTime|null getEditedAt()
 * @method void setEditedAt(?DateTime $editedAt)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class NoteVersion extends Entity implements JsonSerializable {

	/**
	 * Stable identifier for the version row.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * The Nextcloud comment id the version belongs to.
	 *
	 * @var integer
	 */
	protected int $commentId = 0;

	/**
	 * The text the note carried before the edit.
	 *
	 * @var string|null
	 */
	protected ?string $message = null;

	/**
	 * The actor the replaced text was attributed to.
	 *
	 * @var string|null
	 */
	protected ?string $author = null;

	/**
	 * The actor type of that author, e.g. `users` or `openregister_links`.
	 *
	 * @var string|null
	 */
	protected ?string $authorType = null;

	/**
	 * The user who replaced the text.
	 *
	 * @var string|null
	 */
	protected ?string $editedBy = null;

	/**
	 * When the text was replaced.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $editedAt = null;

	/**
	 * When the row was written.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * Constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->addType(fieldName: 'uuid', type: 'string');
		$this->addType(fieldName: 'commentId', type: 'integer');
		$this->addType(fieldName: 'message', type: 'string');
		$this->addType(fieldName: 'author', type: 'string');
		$this->addType(fieldName: 'authorType', type: 'string');
		$this->addType(fieldName: 'editedBy', type: 'string');
		$this->addType(fieldName: 'editedAt', type: 'datetime');
		$this->addType(fieldName: 'created', type: 'datetime');
	}//end __construct()

	/**
	 * JSON serialize the entity.
	 *
	 * @return array<string, mixed> The version in JSON-friendly format.
	 */
	public function jsonSerialize(): array {
		$editedAt = null;
		if ($this->editedAt !== null) {
			$editedAt = $this->editedAt->format('c');
		}

		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'noteId' => $this->commentId,
			'message' => $this->message,
			'author' => $this->author,
			'authorType' => $this->authorType,
			'editedBy' => $this->editedBy,
			'editedAt' => $editedAt,
		];
	}//end jsonSerialize()
}//end class
