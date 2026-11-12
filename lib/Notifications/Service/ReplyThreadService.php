<?php

/**
 * Answers which object a reply threads onto, for the caller asking.
 *
 * A link the caller may not read is treated as no link. The answer never says
 * that an unreadable object exists, so a reply that also touches a case the
 * caller cannot see reads as unthreaded or threaded on what they can see.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service;

use OCA\OpenRegister\Db\EmailLinkMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Notifications\Service\Notification\ReplyThreadResolver;
use Throwable;

/**
 * Resolves reply headers against the recorded links, within the caller's reach.
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
 */
class ReplyThreadService {
	/**
	 * Constructor.
	 *
	 * @param EmailLinkMapperInterface           $links    The recorded links.
	 * @param ObjectOperationsInterface $objects  Reads an object under the caller's rights.
	 * @param ReplyThreadResolver       $resolver The header rules.
	 */
	public function __construct(
		private readonly EmailLinkMapperInterface $links,
		private readonly ObjectOperationsInterface $objects,
		private readonly ReplyThreadResolver $resolver,
	) {
	}//end __construct()

	/**
	 * Resolve a reply's headers for the current user.
	 *
	 * @param array<string, mixed> $headers The `In-Reply-To` and `References` headers.
	 *
	 * @return array<string, mixed> The resolver's answer, with `scope: user`.
	 *
	 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
	 */
	public function resolve(array $headers): array {
		$answer = $this->resolver->resolve(
			headers: $headers,
			lookup: fn (string $messageId): ?array => $this->readableLink(messageId: $messageId)
		);

		return array_merge($answer, ['scope' => 'user']);
	}//end resolve()

	/**
	 * The objects a message is linked to that the caller may read.
	 *
	 * @param string $messageId The `Message-ID`.
	 *
	 * @return array{objectUuids: string[]}|null The link, or null when none is left.
	 */
	private function readableLink(string $messageId): ?array {
		$uuids = array_values(
			array_filter(
				$this->links->objectUuidsByRfcMessageId(rfcMessageId: $messageId),
				fn (string $uuid): bool => $this->mayRead(uuid: $uuid)
			)
		);
		if ($uuids === []) {
			return null;
		}

		return ['objectUuids' => $uuids];
	}//end readableLink()

	/**
	 * Whether the caller may read an object. A refusal of any kind is a no.
	 *
	 * @param string $uuid The object.
	 *
	 * @return bool True when the read returns the object.
	 */
	private function mayRead(string $uuid): bool {
		try {
			return $this->objects->find(id: $uuid, options: new ObjectFindOptions(render: false, audit: false), _rbac: true, _multitenancy: true) !== null;
		} catch (Throwable) {
			return false;
		}
	}//end mayRead()
}//end class
