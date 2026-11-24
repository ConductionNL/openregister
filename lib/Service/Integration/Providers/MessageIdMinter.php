<?php

/**
 * Mint an RFC 5322 Message-ID for an outbound e-mail.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-e-mail-is-a-channel-of-the-dispatch-leaf
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

/**
 * A Message-ID is `<unique@domain>`. The unique part is 128 random bits, so two
 * messages never share one; the domain is this instance's host, so a reply's
 * `In-Reply-To` can be recognised as answering a message this instance sent
 * (the handle `reply-threading-by-headers` brings a reply back to its object with).
 *
 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-e-mail-is-a-channel-of-the-dispatch-leaf
 */
final class MessageIdMinter {

	/**
	 * The header an e-mail carries its Message-ID in.
	 */
	public const HEADER = 'Message-ID';

	/**
	 * The domain used when the host is empty or not a host name.
	 */
	public const FALLBACK_DOMAIN = 'openregister.invalid';

	/**
	 * A new Message-ID for a message sent from the given host.
	 *
	 * @param string $host The instance's host name, as the request names it; a port is dropped.
	 *
	 * @return string The Message-ID, angle brackets included.
	 *
	 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-e-mail-is-a-channel-of-the-dispatch-leaf
	 */
	public function mint(string $host): string {
		return '<' . bin2hex(random_bytes(16)) . '@' . self::domainOf(host: $host) . '>';
	}//end mint()

	/**
	 * The domain part: the host without a port, when it is a host name.
	 *
	 * @param string $host The host as given.
	 *
	 * @return string The domain.
	 */
	private static function domainOf(string $host): string {
		$domain = strtolower(trim(explode(':', trim($host))[0]));
		if ($domain === '' || preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $domain) !== 1) {
			return self::FALLBACK_DOMAIN;
		}

		return $domain;
	}//end domainOf()
}//end class
