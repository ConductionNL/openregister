<?php
/**
 * OpenRegister Bookmark Draft
 *
 * A Nextcloud Bookmarks bookmark that is about to be created for an object.
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

/**
 * The title, URL, description and tags of a bookmark to create.
 */
final class BookmarkDraft {

	/**
	 * Description; null for none.
	 *
	 * @var string|null
	 */
	private ?string $description = null;

	/**
	 * Tags as given.
	 *
	 * @var array<int, string>
	 */
	private array $tags = [];

	/**
	 * The owning user's id; '' when none was set.
	 *
	 * @var string
	 */
	private string $ownerId = '';

	/**
	 * Constructor.
	 *
	 * @param string $title Bookmark title as given.
	 * @param string $url   Bookmark URL as given.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function __construct(
		public readonly string $title,
		public readonly string $url,
	) {
	}//end __construct()

	/**
	 * A copy with the given description and tags.
	 *
	 * @param string|null        $description Description; null for none.
	 * @param array<int, string> $tags        Tags.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function withDetails(?string $description, array $tags): self {
		$copy              = clone $this;
		$copy->description = $description;
		$copy->tags        = $tags;
		return $copy;
	}//end withDetails()

	/**
	 * The description; null for none.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function description(): ?string {
		return $this->description;
	}//end description()

	/**
	 * The tags as given.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function tags(): array {
		return $this->tags;
	}//end tags()

	/**
	 * A copy owned by the given user.
	 *
	 * @param string $ownerId The owning user's id.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function ownedBy(string $ownerId): self {
		$copy          = clone $this;
		$copy->ownerId = $ownerId;
		return $copy;
	}//end ownedBy()

	/**
	 * The owning user's id; '' when none was set.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function ownerId(): string {
		return $this->ownerId;
	}//end ownerId()
}//end class
