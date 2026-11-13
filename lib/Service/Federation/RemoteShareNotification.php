<?php

/**
 * OpenRegister Remote Share Notification
 *
 * Applies a status change the other instance reports for a federated share:
 * the recipient accepted or declined what was sent from here, or the sender
 * withdrew what was received here.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Federation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/platform-cloud-federation-provider/specs/federation/spec.md#requirement-an-object-is-shared-with-a-principal-at-another-instance-with-a-two-sided-lifecycle-req-pcf-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Federation;

use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\FederatedShareMapperInterface;
use OCP\Federation\Exceptions\ActionNotSupportedException;
use OCP\Federation\Exceptions\AuthenticationFailedException;
use OCP\Federation\Exceptions\BadRequestException;
use Throwable;

/**
 * A far-side status change, reflected on the share here.
 *
 * The notification comes from another instance, not from a signed-in user,
 * so the share's secret is the only credential: the share is found by it and
 * must also be the one the provider id names. A share that was declined or
 * revoked stays so.
 *
 * @spec openspec/changes/platform-cloud-federation-provider/specs/federation/spec.md#requirement-an-object-is-shared-with-a-principal-at-another-instance-with-a-two-sided-lifecycle-req-pcf-001
 */
class RemoteShareNotification {

	/**
	 * The OCM notification types this applies, with the direction of the
	 * share they concern and the status they set.
	 *
	 * @var array<string, array{direction: string, status: string}>
	 */
	public const TRANSITIONS = [
		'SHARE_ACCEPTED' => ['direction' => 'outgoing', 'status' => 'accepted'],
		'SHARE_DECLINED' => ['direction' => 'outgoing', 'status' => 'declined'],
		'SHARE_UNSHARED' => ['direction' => 'incoming', 'status' => 'revoked'],
	];

	/**
	 * Statuses a share does not leave.
	 *
	 * @var string[]
	 */
	public const FINAL_STATUSES = ['declined', 'revoked'];

	/**
	 * Constructor.
	 *
	 * @param FederatedShareMapperInterface $shareMapper Federated-share persistence.
	 */
	public function __construct(
		private readonly FederatedShareMapperInterface $shareMapper,
	) {
	}//end __construct()

	/**
	 * Apply one notification from the other instance.
	 *
	 * @param string               $type         The OCM notification type.
	 * @param string               $providerId   The share id the notification names.
	 * @param array<string, mixed> $notification The payload, carrying `sharedSecret`.
	 *
	 * @return FederatedShare The share as it now stands.
	 *
	 * @throws ActionNotSupportedException When the type is not one this applies.
	 * @throws BadRequestException When the payload carries no secret.
	 * @throws AuthenticationFailedException When no share of that direction matches the secret and id.
	 *
	 * @spec openspec/changes/platform-cloud-federation-provider/specs/federation/spec.md#requirement-an-object-is-shared-with-a-principal-at-another-instance-with-a-two-sided-lifecycle-req-pcf-001
	 */
	public function apply(string $type, string $providerId, array $notification): FederatedShare {
		$transition = (self::TRANSITIONS[$type] ?? null);
		if ($transition === null) {
			throw new ActionNotSupportedException($type);
		}

		$secret = $notification['sharedSecret'] ?? null;
		if (is_string($secret) === false || $secret === '') {
			throw new BadRequestException(['sharedSecret']);
		}

		$share = $this->shareFor(secret: $secret, providerId: $providerId, direction: $transition['direction']);
		if (in_array($share->getStatus(), self::FINAL_STATUSES, true) === true) {
			return $share;
		}

		$share->setStatus($transition['status']);

		return $this->shareMapper->update(entity: $share);
	}//end apply()

	/**
	 * The share the secret opens, when it is the one the id names and runs in that direction.
	 *
	 * An outgoing share is named by its id here; an incoming one by the id the
	 * sender gave it.
	 *
	 * @param string $secret     The share secret.
	 * @param string $providerId The id the notification names.
	 * @param string $direction  The direction the notification concerns.
	 *
	 * @return FederatedShare The share.
	 *
	 * @throws AuthenticationFailedException When no such share exists.
	 */
	private function shareFor(string $secret, string $providerId, string $direction): FederatedShare {
		try {
			$share = $this->shareMapper->findByToken(shareToken: $secret);
		} catch (Throwable $e) {
			throw new AuthenticationFailedException();
		}

		$named = (string)$share->getRemoteProviderId();
		if ($direction === 'outgoing') {
			$named = (string)$share->getId();
		}

		if ($share->getDirection() !== $direction || $providerId === '' || hash_equals($named, $providerId) === false) {
			throw new AuthenticationFailedException();
		}

		return $share;
	}//end shareFor()
}//end class
