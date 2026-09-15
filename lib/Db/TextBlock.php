<?php

/**
 * TextBlock entity: an administered piece of canned text.
 *
 * A klantcontactcentrum answers the same twenty questions. The answers are
 * administered once, scoped to a register, a schema or a group, and inserted
 * with the same variable substitution message templates use.
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
 * TextBlock.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string getSlug()
 * @method void setSlug(string $slug)
 * @method string|null getTitle()
 * @method void setTitle(?string $title)
 * @method string|null getBody()
 * @method void setBody(?string $body)
 * @method string|null getRegister()
 * @method void setRegister(?string $register)
 * @method string|null getSchema()
 * @method void setSchema(?string $schema)
 * @method string|null getGroupId()
 * @method void setGroupId(?string $groupId)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */
class TextBlock extends Entity implements JsonSerializable {

	/**
	 * The stable id of the block.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * The name the block is administered under.
	 *
	 * @var string|null
	 */
	protected ?string $slug = null;

	/**
	 * The label a handler picks from a list.
	 *
	 * @var string|null
	 */
	protected ?string $title = null;

	/**
	 * The text, with `{{ placeholders }}` the substitution fills in.
	 *
	 * @var string|null
	 */
	protected ?string $body = null;

	/**
	 * The register the block is scoped to, or null for every register.
	 *
	 * @var string|null
	 */
	protected ?string $register = null;

	/**
	 * The schema the block is scoped to, or null for every schema.
	 *
	 * @var string|null
	 */
	protected ?string $schema = null;

	/**
	 * The group the block is scoped to, or null for everybody.
	 *
	 * @var string|null
	 */
	protected ?string $groupId = null;

	/**
	 * When the block was administered.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * When it last changed.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $updated = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->addType(fieldName: 'uuid', type: 'string');
		$this->addType(fieldName: 'slug', type: 'string');
		$this->addType(fieldName: 'title', type: 'string');
		$this->addType(fieldName: 'body', type: 'string');
		$this->addType(fieldName: 'register', type: 'string');
		$this->addType(fieldName: 'schema', type: 'string');
		$this->addType(fieldName: 'groupId', type: 'string');
		$this->addType(fieldName: 'created', type: 'datetime');
		$this->addType(fieldName: 'updated', type: 'datetime');

	}//end __construct()

	/**
	 * The block as the API returns it.
	 *
	 * @return array<string, mixed> The declaration.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->uuid,
			'slug' => $this->slug,
			'title' => $this->title,
			'body' => $this->body,
			'register' => $this->register,
			'schema' => $this->schema,
			'groupId' => $this->groupId,
			'created' => $this->created?->format(DateTime::ATOM),
			'updated' => $this->updated?->format(DateTime::ATOM),
		];

	}//end jsonSerialize()
}//end class
