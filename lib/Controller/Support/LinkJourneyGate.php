<?php

/**
 * Admission and use records for the `edit` link endpoints.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-form-link-may-open-an-existing-record-for-change-req-fjr-008
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Db\StoredEntity;
use OCA\OpenRegister\Kernel\Contracts\AccessLinkReaderInterface;
use OCA\OpenRegister\Service\Sharing\AccessLinkServiceInterface;
use OCA\OpenRegister\Service\Sharing\AccessLinkUse;
use OCA\OpenRegister\Service\Sharing\LinkAct;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Lets a link in only when it is live, its password is given and it declares `edit`.
 *
 * The answers follow AccessLinkController: one counted 404 for an unknown,
 * revoked, switched-off or expired link and for a subject that is gone, a
 * counted 401 for a missing or wrong password, and 403 naming the link's own
 * capabilities when it does not declare `edit`.
 *
 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-form-link-may-open-an-existing-record-for-change-req-fjr-008
 */
class LinkJourneyGate {

	/**
	 * Counts rejected anchors and passwords against the access link throttle.
	 *
	 * @var ObjectShareLinkThrottle
	 */
	private readonly ObjectShareLinkThrottle $rejections;

	/**
	 * Constructor.
	 *
	 * @param AccessLinkServiceInterface $links    Resolves the link, checks its password and records each use.
	 * @param AccessLinkReaderInterface  $reader   Resolves the link's subject object.
	 * @param AccessLinkPlatform         $platform The throttler and the logger.
	 */
	public function __construct(
		private readonly AccessLinkServiceInterface $links,
		private readonly AccessLinkReaderInterface $reader,
		AccessLinkPlatform $platform,
	) {
		$this->rejections = new ObjectShareLinkThrottle(
			throttler: $platform->throttler,
			logger: $platform->logger,
			action: AccessLinkServiceInterface::THROTTLE_ACTION,
			label: 'LinkJourneyGate'
		);
	}//end __construct()

	/**
	 * The admitted `edit` link and its subject object, or the refusal.
	 *
	 * @param string   $anchor  The anchor from the URL.
	 * @param IRequest $request The request: the password header or parameter, and the address.
	 *
	 * @return LinkAct|JSONResponse The link and its object, or the refusal.
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/tasks.md#task-6-3
	 */
	public function admit(string $anchor, IRequest $request): LinkAct | JSONResponse {
		$link = $this->links->resolve(anchor: $anchor);
		if ($link === null) {
			return $this->rejected(request: $request);
		}

		if ($this->links->passwordAccepted(link: $link, password: self::password(request: $request)) === false) {
			$this->rejections->countRejected(address: $request->getRemoteAddress());

			return new JSONResponse(['message' => 'This link is closed with a password.', 'passwordRequired' => true], Http::STATUS_UNAUTHORIZED);
		}

		if ($link->allows(capability: AccessLink::CAP_EDIT) === false) {
			return new JSONResponse(
				['message' => 'This link does not allow that.', 'capabilities' => $link->declaredCapabilities()],
				Http::STATUS_FORBIDDEN
			);
		}

		$object = StoredEntity::object(object: $this->reader->subjectObject(link: $link));
		if ($object === null) {
			return $this->rejected(request: $request);
		}

		return new LinkAct(link: $link, object: $object);
	}//end admit()

	/**
	 * Count a rejected request and answer the uniform 404.
	 *
	 * @param IRequest $request The request, for the address.
	 *
	 * @return JSONResponse The 404.
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/tasks.md#task-6-3
	 */
	public function rejected(IRequest $request): JSONResponse {
		$this->rejections->countRejected(address: $request->getRemoteAddress());

		return new JSONResponse(['message' => 'Not Found'], Http::STATUS_NOT_FOUND);
	}//end rejected()

	/**
	 * Record one use of the link on the object's audit trail, with the link as the actor.
	 *
	 * @param LinkAct              $act     The link and the object acted on, as it now stands.
	 * @param string               $action  The audit action.
	 * @param array<string, mixed> $context What else to record, and the caller's `ipAddress`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/tasks.md#task-6-3
	 */
	public function record(LinkAct $act, string $action, array $context): void {
		$address = $context['ipAddress'] ?? null;
		unset($context['ipAddress']);

		$this->links->recordUse(
			link: $act->link,
			use: new AccessLinkUse(act: $action, object: $act->object, ipAddress: $address, context: $context)
		);
	}//end record()

	/**
	 * The password the holder presented, from the header first, then the body.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return string|null The password, or null.
	 */
	private static function password(IRequest $request): ?string {
		foreach ([(string)$request->getHeader('X-OpenRegister-Link-Password'), (string)$request->getParam('password', '')] as $candidate) {
			if (trim($candidate) !== '') {
				return trim($candidate);
			}
		}

		return null;
	}//end password()
}//end class
