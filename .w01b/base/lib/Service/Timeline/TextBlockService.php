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

namespace OCA\OpenRegister\Service\Timeline;

use DateTime;
use OCA\OpenRegister\Db\TextBlock;
use OCA\OpenRegister\Db\TextBlockMapper;
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
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4() is the standard utility pattern in this app
	 */
	public function declareBlock(array $data): TextBlock {
		$slug = strtolower(trim((string)($data['slug'] ?? '')));
		if ($slug === '') {
			throw new TimelineValidationException(['slug' => 'A text block needs a slug']);
		}

		$body = (string)($data['body'] ?? '');
		if (trim($body) === '') {
			throw new TimelineValidationException(['body' => 'A text block needs a body']);
		}

		$block = $this->blocks->findBySlug(slug: $slug);
		$isNew = ($block === null);
		if ($isNew === true) {
			$block = new TextBlock();
			$block->setUuid((string)Uuid::v4());
			$block->setSlug($slug);
			$block->setCreated(new DateTime());
		}

		$block->setTitle($this->stringOrNull(data: $data, key: 'title'));
		$block->setBody($body);
		$block->setRegister($this->stringOrNull(data: $data, key: 'register'));
		$block->setSchema($this->stringOrNull(data: $data, key: 'schema'));
		$block->setGroupId($this->stringOrNull(data: $data, key: 'groupId'));
		$block->setUpdated(new DateTime());

		if ($isNew === true) {
			return $this->blocks->insert($block);
		}

		return $this->blocks->update($block);
	}//end declareBlock()

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
			static function (array $match) use ($variables): string {
				$name = $match[1];
				if (array_key_exists($name, $variables) === false) {
					return $match[0];
				}

				$value = $variables[$name];
				if (is_scalar($value) === false) {
					return $match[0];
				}

				return (string)$value;
			},
			$text
		);

		if (is_string($result) === false) {
			return $text;
		}

		return $result;
	}//end substitute()

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

	/**
	 * Read one optional string off a payload.
	 *
	 * @param array<string,mixed> $data The payload.
	 * @param string              $key  The key.
	 *
	 * @return string|null The value, or null when absent, empty or not a string.
	 */
	private function stringOrNull(array $data, string $key): ?string {
		if (isset($data[$key]) === false || is_string($data[$key]) === false) {
			return null;
		}

		$value = trim($data[$key]);
		if ($value === '') {
			return null;
		}

		return $value;
	}//end stringOrNull()
}//end class
