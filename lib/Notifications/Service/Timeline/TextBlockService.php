<?php

/**
 * TextBlockService: canned text, administered once and inserted, not retyped.
 *
 * A klantcontactcentrum answers the same twenty questions. The answers are
 * administered, scoped to a register, a schema or a group, and inserted into
 * an entry with the same `{{ placeholder }}` substitution the message templates
 * use.
 *
 * SUBSTITUTION IS NOT RENDERING. A placeholder nothing supplies is left
 * STANDING, not silently emptied: a handler who sees `{{ zaaknummer }}` in the
 * text they are about to send knows something is missing, where a blank space
 * reads as a finished sentence and gets sent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Timeline
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Timeline;

use DateTime;
use OCA\OpenRegister\Notifications\Db\TextBlock;
use OCA\OpenRegister\Notifications\Db\TextBlockMapper;
use OCP\IGroupManager;
use OCP\IUserSession;
use Symfony\Component\Uid\Uuid;

/**
 * Administered canned text blocks.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Timeline
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */
class TextBlockService {
	use OptionalPayloadString;

	/**
	 * The placeholder form, matching the message templates.
	 *
	 * @var string
	 */
	private const PLACEHOLDER = '/\{\{\s*([\p{L}\p{N}._-]+)\s*\}\}/u';

	/**
	 * Constructor.
	 *
	 * @param TextBlockMapper $blocks       The administered blocks.
	 * @param IUserSession    $userSession  The caller, whose groups scope the list.
	 * @param IGroupManager   $groupManager Resolves the caller's groups.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TextBlockMapper $blocks,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * The blocks the caller may insert here.
	 *
	 * @param string|null $register The register being written on.
	 * @param string|null $schema   The schema being written on.
	 *
	 * @return array<int, TextBlock> The blocks.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function listBlocks(?string $register = null, ?string $schema = null): array {
		return $this->blocks->findInScope(
			register: $register,
			schema: $schema,
			groups: $this->callerGroups()
		);
	}//end listBlocks()

	/**
	 * Administer a block, or rewrite the one that carries the name.
	 *
	 * @param array<string,mixed> $data slug, title, body, register, schema, groupId.
	 *
	 * @return TextBlock The stored block.
	 *
	 * @throws TimelineValidationException When the slug or the body is missing.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function declareBlock(array $data): TextBlock {
		$slug = $this->requiredSlug(data: $data);
		$this->requireBody(data: $data);

		$block = $this->blocks->findBySlug(slug: $slug);
		if ($block === null) {
			return $this->blocks->insert($this->fill(block: $this->blank(slug: $slug), data: $data));
		}

		return $this->blocks->update($this->fill(block: $block, data: $data));
	}//end declareBlock()

	/**
	 * The normalised slug of a declaration.
	 *
	 * @param array<string,mixed> $data The payload.
	 *
	 * @return string The slug.
	 *
	 * @throws TimelineValidationException When the slug is missing.
	 */
	private function requiredSlug(array $data): string {
		$slug = strtolower(trim((string)($data['slug'] ?? '')));
		if ($slug === '') {
			throw new TimelineValidationException(['slug' => 'A text block needs a slug']);
		}

		return $slug;
	}//end requiredSlug()

	/**
	 * Refuse a declaration without a body.
	 *
	 * @param array<string,mixed> $data The payload.
	 *
	 * @return void
	 *
	 * @throws TimelineValidationException When the body is missing.
	 */
	private function requireBody(array $data): void {
		if (trim((string)($data['body'] ?? '')) === '') {
			throw new TimelineValidationException(['body' => 'A text block needs a body']);
		}
	}//end requireBody()

	/**
	 * A block nobody has administered yet, with its stable id minted.
	 *
	 * @param string $slug The block name.
	 *
	 * @return TextBlock The new block.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4() is the standard utility pattern in this app
	 */
	private function blank(string $slug): TextBlock {
		$block = new TextBlock();
		$block->setUuid((string)Uuid::v4());
		$block->setSlug($slug);
		$block->setCreated(new DateTime());

		return $block;
	}//end blank()

	/**
	 * Write a payload onto a block, new or existing.
	 *
	 * @param TextBlock           $block The block.
	 * @param array<string,mixed> $data  The payload.
	 *
	 * @return TextBlock The filled block, not yet written.
	 */
	private function fill(TextBlock $block, array $data): TextBlock {
		$block->setTitle($this->stringOrNull(data: $data, key: 'title'));
		$block->setBody((string)$data['body']);
		$block->setRegister($this->stringOrNull(data: $data, key: 'register'));
		$block->setSchema($this->stringOrNull(data: $data, key: 'schema'));
		$block->setGroupId($this->stringOrNull(data: $data, key: 'groupId'));
		$block->setUpdated(new DateTime());

		return $block;
	}//end fill()

	/**
	 * Withdraw a block.
	 *
	 * @param string $slug The block name.
	 *
	 * @return boolean True when a block was removed.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function withdrawBlock(string $slug): bool {
		$block = $this->blocks->findBySlug(slug: $slug);
		if ($block === null) {
			return false;
		}

		$this->blocks->delete($block);

		return true;
	}//end withdrawBlock()

	/**
	 * The text of one block, with its variables filled in.
	 *
	 * @param string              $slug      The block name.
	 * @param array<string,mixed> $variables The values to substitute.
	 *
	 * @return string The text.
	 *
	 * @throws TimelineValidationException When no such block is administered.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function insert(string $slug, array $variables = []): string {
		$block = $this->blocks->findBySlug(slug: strtolower(trim($slug)));
		if ($block === null) {
			throw new TimelineValidationException(['textBlock' => 'No text block named '.$slug.' is administered']);
		}

		return $this->substitute(text: (string)$block->getBody(), variables: $variables);
	}//end insert()

	/**
	 * Fill in `{{ placeholders }}`, leaving the ones nothing supplies standing.
	 *
	 * @param string              $text      The text.
	 * @param array<string,mixed> $variables The values.
	 *
	 * @return string The substituted text.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function substitute(string $text, array $variables): string {
		$result = preg_replace_callback(
			self::PLACEHOLDER,
			fn (array $placeholder): string => $this->valueFor(placeholder: $placeholder, variables: $variables),
			$text
		);

		if (is_string($result) === false) {
			return $text;
		}

		return $result;
	}//end substitute()

	/**
	 * The value of one placeholder, or the placeholder itself when nothing scalar supplies it.
	 *
	 * @param array<int,string>   $placeholder The regex match: the whole placeholder, then its name.
	 * @param array<string,mixed> $variables   The values.
	 *
	 * @return string The text to put in its place.
	 */
	private function valueFor(array $placeholder, array $variables): string {
		$value = ($variables[$placeholder[1]] ?? null);
		if (is_scalar($value) === false) {
			return $placeholder[0];
		}

		return (string)$value;
	}//end valueFor()

	/**
	 * The caller's groups.
	 *
	 * @return array<int,string> The group ids, empty for an anonymous caller.
	 */
	private function callerGroups(): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return [];
		}

		return $this->groupManager->getUserGroupIds($user);
	}//end callerGroups()
}//end class
