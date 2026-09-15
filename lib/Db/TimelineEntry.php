<?php

/**
 * TimelineEntry entity: one timeline entry, as a record.
 *
 * A note is still a Nextcloud comment and the comment still carries the text.
 * This row is what makes the entry a RECORD rather than a line of prose: it
 * holds the stable uuid a link can point at, the object the entry hangs on,
 * the kind it was written as and the fields that kind declares, the author,
 * the internal or public flag `timeline-entry-visibility` introduced, the pin,
 * the follow-up state, the raw inbound source and the language it arrived in.
 *
 * The message is copied onto the row on purpose. The entry is its own indexed
 * item (D-1), so a search can say which entry on which case, and the
 * visibility filter can be a query condition instead of a filter applied to a
 * page that was already built. The comment stays the authority for the text;
 * every write that changes the comment rewrites this copy in the same call.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * TimelineEntry.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getObjectUuid()
 * @method void setObjectUuid(?string $objectUuid)
 * @method string|null getRegister()
 * @method void setRegister(?string $register)
 * @method string|null getSchema()
 * @method void setSchema(?string $schema)
 * @method integer|null getCommentId()
 * @method void setCommentId(?int $commentId)
 * @method string|null getKind()
 * @method void setKind(?string $kind)
 * @method string|null getAuthor()
 * @method void setAuthor(?string $author)
 * @method string getVisibility()
 * @method void setVisibility(string $visibility)
 * @method string|null getMessage()
 * @method void setMessage(?string $message)
 * @method array|null getFields()
 * @method void setFields(?array $fields)
 * @method string|null getFollowUp()
 * @method void setFollowUp(?string $followUp)
 * @method string|null getClosedBy()
 * @method void setClosedBy(?string $closedBy)
 * @method DateTime|null getClosedAt()
 * @method void setClosedAt(?DateTime $closedAt)
 * @method boolean|null getPinned()
 * @method void setPinned(?bool $pinned)
 * @method string|null getPinnedBy()
 * @method void setPinnedBy(?string $pinnedBy)
 * @method DateTime|null getPinnedAt()
 * @method void setPinnedAt(?DateTime $pinnedAt)
 * @method string|null getLanguage()
 * @method void setLanguage(?string $language)
 * @method string|null getRawSource()
 * @method void setRawSource(?string $rawSource)
 * @method array|null getRawHeaders()
 * @method void setRawHeaders(?array $rawHeaders)
 * @method array|null getSiblings()
 * @method void setSiblings(?array $siblings)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */
class TimelineEntry extends Entity implements JsonSerializable {

	/**
	 * A follow-up that nobody has answered yet.
	 *
	 * @var string
	 */
	public const FOLLOW_UP_OPEN = 'open';

	/**
	 * A follow-up somebody closed, with their name and the time on the row.
	 *
	 * @var string
	 */
	public const FOLLOW_UP_DONE = 'done';

	/**
	 * The stable id of the entry, which is what a link points at.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * The object the entry hangs on.
	 *
	 * @var string|null
	 */
	protected ?string $objectUuid = null;

	/**
	 * The register, as the caller addressed it.
	 *
	 * @var string|null
	 */
	protected ?string $register = null;

	/**
	 * The schema, as the caller addressed it.
	 *
	 * @var string|null
	 */
	protected ?string $schema = null;

	/**
	 * The Nextcloud comment carrying the text, when a note backs the entry.
	 *
	 * @var integer|null
	 */
	protected ?int $commentId = null;

	/**
	 * The declared kind, or null for a plain note.
	 *
	 * @var string|null
	 */
	protected ?string $kind = null;

	/**
	 * The uid that wrote the entry.
	 *
	 * @var string|null
	 */
	protected ?string $author = null;

	/**
	 * `internal` or `public`, never absent: a missing flag reads as internal.
	 *
	 * @var string
	 */
	protected string $visibility = 'internal';

	/**
	 * The entry text, copied for the index.
	 *
	 * @var string|null
	 */
	protected ?string $message = null;

	/**
	 * The values the kind's declared properties carry.
	 *
	 * @var array<string,mixed>|null
	 */
	protected ?array $fields = null;

	/**
	 * `open`, `done`, or null when the kind declares no follow-up.
	 *
	 * @var string|null
	 */
	protected ?string $followUp = null;

	/**
	 * Who closed the follow-up.
	 *
	 * @var string|null
	 */
	protected ?string $closedBy = null;

	/**
	 * When the follow-up was closed.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $closedAt = null;

	/**
	 * Whether the entry sorts first on the timeline.
	 *
	 * @var boolean|null
	 */
	protected ?bool $pinned = false;

	/**
	 * Who pinned it. The pin is on the record, so the reader sees a name (D-4).
	 *
	 * @var string|null
	 */
	protected ?string $pinnedBy = null;

	/**
	 * When it was pinned.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $pinnedAt = null;

	/**
	 * The language the entry was detected to be in.
	 *
	 * @var string|null
	 */
	protected ?string $language = null;

	/**
	 * The raw inbound message, exactly as it arrived (D-5).
	 *
	 * @var string|null
	 */
	protected ?string $rawSource = null;

	/**
	 * The headers of the raw inbound message.
	 *
	 * @var array<string,mixed>|null
	 */
	protected ?array $rawHeaders = null;

	/**
	 * The entries a multi-object note wrote beside this one.
	 *
	 * @var array<int,mixed>|null
	 */
	protected ?array $siblings = null;

	/**
	 * When the entry was written.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * When the record last changed.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $updated = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->addType(fieldName: 'uuid', type: 'string');
		$this->addType(fieldName: 'objectUuid', type: 'string');
		$this->addType(fieldName: 'register', type: 'string');
		$this->addType(fieldName: 'schema', type: 'string');
		$this->addType(fieldName: 'commentId', type: 'integer');
		$this->addType(fieldName: 'kind', type: 'string');
		$this->addType(fieldName: 'author', type: 'string');
		$this->addType(fieldName: 'visibility', type: 'string');
		$this->addType(fieldName: 'message', type: 'string');
		$this->addType(fieldName: 'fields', type: 'json');
		$this->addType(fieldName: 'followUp', type: 'string');
		$this->addType(fieldName: 'closedBy', type: 'string');
		$this->addType(fieldName: 'closedAt', type: 'datetime');
		$this->addType(fieldName: 'pinned', type: 'boolean');
		$this->addType(fieldName: 'pinnedBy', type: 'string');
		$this->addType(fieldName: 'pinnedAt', type: 'datetime');
		$this->addType(fieldName: 'language', type: 'string');
		$this->addType(fieldName: 'rawSource', type: 'string');
		$this->addType(fieldName: 'rawHeaders', type: 'json');
		$this->addType(fieldName: 'siblings', type: 'json');
		$this->addType(fieldName: 'created', type: 'datetime');
		$this->addType(fieldName: 'updated', type: 'datetime');

	}//end __construct()

	/**
	 * The entry as the API returns it.
	 *
	 * The raw source is NOT here. It is large, it is asked for by the one
	 * reader who is settling a dispute, and putting it in every timeline page
	 * would send a mailbox down the wire to draw a list. It has its own
	 * endpoint, under the same access as the entry (D-5).
	 *
	 * @return array<string, mixed> The row as the API returns it.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->uuid,
			'rowId' => $this->id,
			'objectUuid' => $this->objectUuid,
			'register' => $this->register,
			'schema' => $this->schema,
			'commentId' => $this->commentId,
			'kind' => $this->kind,
			'author' => $this->author,
			'visibility' => $this->visibility,
			'message' => $this->message,
			'fields' => ($this->fields ?? []),
			'followUp' => $this->followUp,
			'closedBy' => $this->closedBy,
			'closedAt' => $this->closedAt?->format(DateTime::ATOM),
			'pinned' => ($this->pinned === true),
			'pinnedBy' => $this->pinnedBy,
			'pinnedAt' => $this->pinnedAt?->format(DateTime::ATOM),
			'language' => $this->language,
			'hasSource' => ($this->rawSource !== null && $this->rawSource !== ''),
			'siblings' => ($this->siblings ?? []),
			'created' => $this->created?->format(DateTime::ATOM),
			'updated' => $this->updated?->format(DateTime::ATOM),
		];

	}//end jsonSerialize()
}//end class
