<?php

/**
 * OpenRegister content provider sources
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category ContextChat
 * @package  OCA\OpenRegister\ContextChat\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\ContextChat\Support;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * The schema, register and magic-table lookups the Context Chat provider reads objects through.
 *
 * @spec openspec/specs/context-chat-provider/spec.md
 */
final readonly class ContentProviderSources {
	/**
	 * Bundle the services.
	 *
	 * @param SchemaMapperInterface   $schemaMapper   Schema lookup.
	 * @param RegisterMapperInterface $registerMapper Register lookup.
	 * @param MagicMapperInterface    $magicMapper    Magic-table reads.
	 *
	 * @spec openspec/specs/context-chat-provider/spec.md
	 */
	public function __construct(
		public SchemaMapperInterface $schemaMapper,
		public RegisterMapperInterface $registerMapper,
		public MagicMapperInterface $magicMapper,
	) {
	}//end __construct()
}//end class
