<?php

/**
 * The one List-Unsubscribe header helper for the fleet.
 *
 * `OCP\Mail\IMessage` has no header setter. Nextcloud's own message class
 * exposes the underlying Symfony mail object through `getSymfonyEmail()`,
 * which is not part of the public interface, so the helper reaches it only
 * behind `method_exists()` and degrades to "no headers" rather than throwing.
 * The body link is always there; the headers are best effort (RFC 8058).
 *
 * OpenRegister owns this helper so dossiq and pipelinq do not each keep a
 * copy (hydra opt-out-before-send, decision 6, Ruben 2026-10-05). They hold
 * OpenRegister as a hard dependency (ADR-083) and inject it by class.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/opt-out-before-send/specs/external-recipient-opt-out/spec.md#requirement-openregister-owns-one-shared-list-unsubscribe-helper-req-ero-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

use OCP\Mail\IMessage;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sets List-Unsubscribe and List-Unsubscribe-Post on a Nextcloud mail message.
 *
 * Public contract for sibling apps: `apply(IMessage, array): bool`. The array
 * is integriq's unsubscribe material, `{url, oneClickUrl, smsText, headers}`.
 */
class UnsubscribeHeaders {

	public const HEADER_UNSUBSCRIBE = 'List-Unsubscribe';

	public const HEADER_UNSUBSCRIBE_POST = 'List-Unsubscribe-Post';

	/**
	 * The RFC 8058 one-click value.
	 */
	public const ONE_CLICK = 'List-Unsubscribe=One-Click';

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Says, at debug, why the headers could not be set.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Set both headers from integriq's unsubscribe material.
	 *
	 * Never throws. False means the message goes out without the headers, and
	 * the caller still sends it with the body link.
	 *
	 * @param IMessage $message The message to decorate.
	 * @param array<string, mixed> $unsubscribe Integriq's material; `oneClickUrl`, else `url`.
	 *
	 * @return bool True when both headers are set.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/external-recipient-opt-out/spec.md#requirement-openregister-owns-one-shared-list-unsubscribe-helper-req-ero-005
	 */
	public function apply(IMessage $message, array $unsubscribe): bool {
		$url = $this->oneClickUrl(unsubscribe: $unsubscribe);
		if ($url === null) {
			$this->logger->debug('[UnsubscribeHeaders] no usable one-click url in the unsubscribe material; no headers set');
			return false;
		}

		if (method_exists($message, 'getSymfonyEmail') === false) {
			$this->logger->debug('[UnsubscribeHeaders] this mailer does not expose the mail object; sending with the body link only');
			return false;
		}

		try {
			$email   = $message->getSymfonyEmail();
			$headers = $email->getHeaders();
			foreach ([self::HEADER_UNSUBSCRIBE, self::HEADER_UNSUBSCRIBE_POST] as $name) {
				if ($headers->has($name) === true) {
					$headers->remove($name);
				}
			}

			$headers->addTextHeader(self::HEADER_UNSUBSCRIBE, '<' . $url . '>');
			$headers->addTextHeader(self::HEADER_UNSUBSCRIBE_POST, self::ONE_CLICK);
		} catch (Throwable $e) {
			$this->logger->debug(
				'[UnsubscribeHeaders] could not set the headers; sending with the body link only',
				['exception' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end apply()

	/**
	 * The one-click url, or null when the material has none that is safe in a header.
	 *
	 * An http(s) url only, and nothing that could end the header or the angle
	 * brackets around it.
	 *
	 * @param array<string, mixed> $unsubscribe Integriq's material.
	 *
	 * @return string|null The url.
	 */
	private function oneClickUrl(array $unsubscribe): ?string {
		$url = trim((string)($unsubscribe['oneClickUrl'] ?? ''));
		if ($url === '') {
			$url = trim((string)($unsubscribe['url'] ?? ''));
		}

		if ($url === '' || preg_match('#^https?://[^\s<>]+$#i', $url) !== 1) {
			return null;
		}

		return $url;
	}//end oneClickUrl()
}//end class
