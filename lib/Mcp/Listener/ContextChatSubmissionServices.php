<?php

/**
 * The services the Context Chat submission listener resolves content, schemas and users through.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Mcp\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/context-chat-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\Listener;

use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralInterface;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A dependency bundle of {@see ContextChatSubmissionListener}.
 *
 * @spec openspec/specs/context-chat-provider/spec.md
 */
final class ContextChatSubmissionServices {
	/**
	 * Bundle the services.
	 *
	 * @param ContainerInterface    $container    Container, for the lazy IContentManager resolve.
	 * @param SchemaMapperInterface $schemaMapper Schema lookup mapper.
	 * @param IUserManager          $userManager  User manager, for the broadcast-audience fallback.
	 * @param LoggerInterface       $logger       PSR logger.
	 * @param ListenerDeferralInterface|null $deferral Moves the event work to a job; null runs it inline.
	 *
	 * @spec openspec/specs/context-chat-provider/spec.md
	 * @spec openspec/changes/object-event-sync-async-split/specs/event-driven-architecture/spec.md#requirement-post-event-object-listeners-must-defer-heavy-work-through-the-existing-deferral-contract
	 */
	public function __construct(
		public readonly ContainerInterface $container,
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly IUserManager $userManager,
		public readonly LoggerInterface $logger,
		public readonly ?ListenerDeferralInterface $deferral = null,
	) {
	}//end __construct()
}//end class
