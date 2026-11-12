<?php

/**
 * EmlAttachmentPart — a MIME attachment part and where it sits in the message being parsed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TextExtraction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/text-extraction-eml/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\TextExtraction;

use ZBateson\MailMimeParser\Message\IMessagePart;

/**
 * Value object: an attachment part, its 1-indexed position and the recursion depth of its message.
 *
 * @spec openspec/specs/text-extraction-eml/spec.md
 */
final class EmlAttachmentPart {
	/**
	 * Constructor.
	 *
	 * @param IMessagePart $part     The MIME part.
	 * @param int          $position 1-indexed position in multipart order.
	 * @param int          $depth    Recursion depth of the message that holds the part (root = 0).
	 */
	public function __construct(
		public readonly IMessagePart $part,
		public readonly int $position,
		public readonly int $depth,
	) {
	}//end __construct()
}//end class
