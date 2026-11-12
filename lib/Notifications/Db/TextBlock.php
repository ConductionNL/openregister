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

namespace OCA\OpenRegister\Notifications\Db;

use DateTime;

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
class TextBlock extends AdministeredDeclaration {

	/**
	 * The text, with `{{ placeholders }}` the substitution fills in.
	 *
	 * @var string|null
	 */
	protected ?string $body = null;

	/**
	 * The group the block is scoped to, or null for everybody.
	 *
	 * @var string|null
	 */
	protected ?string $groupId = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->addNameTypes();
		$this->addType(fieldName: 'body', type: 'string');
		$this->addScopeTypes();
		$this->addType(fieldName: 'groupId', type: 'string');
		$this->addTimestampTypes();

	}//end __construct()

	/**
	 * The block as the API returns it.
	 *
	 * @return array<string, mixed> The declaration.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function jsonSerialize(): array {
		return array_merge(
			$this->nameFields(),
			[
				'body' => $this->body,
			],
			$this->scopeFields(),
			[
				'groupId' => $this->groupId,
			],
			$this->timestampFields()
		);

	}//end jsonSerialize()
}//end class
