<?php

/**
 * Gives a system tag a colour, but only when it has none.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-tag-object-step/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

use Exception;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use Psr\Log\LoggerInterface;

/**
 * Colours a tag that has no colour.
 *
 * `ISystemTagManager::createTag()` takes no colour, so a new tag is coloured
 * through `updateTag()` (Nextcloud 31 added its colour argument). An existing
 * tag keeps a colour someone already chose: a flow step never recolours a tag
 * an administrator coloured. Best effort: a refused update is logged and the
 * tag is still used.
 *
 * @spec openspec/changes/flow-tag-object-step/specs/flow-engine/spec.md
 */
final class TagColour {
	/**
	 * Constructor.
	 *
	 * @param ISystemTagManager $systemTagManager Updates the tag.
	 * @param LoggerInterface   $logger           Logs a refused update.
	 */
	public function __construct(
		private readonly ISystemTagManager $systemTagManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Give the tag this colour when it has none.
	 *
	 * @param ISystemTag  $tag   The tag.
	 * @param string|null $color Six hex digits, or null for no colour.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-tag-object-step/specs/flow-engine/spec.md
	 */
	public function applyIfUncoloured(ISystemTag $tag, ?string $color): void {
		if ($color === null || $color === '' || (string)$tag->getColor() !== '') {
			return;
		}

		try {
			$this->systemTagManager->updateTag(
				tagId: $tag->getId(),
				newName: $tag->getName(),
				userVisible: $tag->isUserVisible(),
				userAssignable: $tag->isUserAssignable(),
				color: $color
			);
		} catch (Exception $e) {
			$this->logger->warning(
				message: '[TaggingHandler] Could not colour tag ' . $tag->getName() . ': ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}
	}//end applyIfUncoloured()
}//end class
