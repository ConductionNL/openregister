<?php

/**
 * What a token grant would let its token do on one object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use DateTimeInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Object\VerbListing;

/**
 * The preview behind the grant editor: per row verb, whether the holder may
 * use it on the chosen object and whether the token would.
 *
 * Both answers come from the permission handler that enforces a request, so
 * the preview cannot disagree with enforcement: the holder's answer with no
 * grant bound, the token's answer with the candidate grant bound. The grant
 * in force for this request, if any, is restored afterwards, and the
 * handler's verdict cache is cleared around each pass so neither answer is
 * served from the other.
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-or-consumer-may-carry-a-grant-narrower-than-its-user
 */
class TokenGrantPreview {

	/**
	 * Constructor.
	 *
	 * @param TokenGrantSource           $source      The grant in force for this request.
	 * @param PermissionHandlerInterface $permissions The verdict enforcement uses.
	 */
	public function __construct(
		private readonly TokenGrantSource $source,
		private readonly PermissionHandlerInterface $permissions,
	) {
	}//end __construct()

	/**
	 * The preview of a grant on one object.
	 *
	 * @param TokenGrant        $grant  The candidate grant.
	 * @param Schema            $schema The object's schema.
	 * @param ObjectEntity      $object The chosen object.
	 * @param DateTimeInterface $now    The moment to judge the end date against.
	 *
	 * @return array{verbs: array<int, array{verb: string, holder: bool, token: bool}>, expired: bool, empty: bool}
	 *         Per verb the two answers; `expired` when the token would be refused at
	 *         use whatever the verbs say; `empty` when the grant permits nothing.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-or-consumer-may-carry-a-grant-narrower-than-its-user
	 */
	public function preview(TokenGrant $grant, Schema $schema, ObjectEntity $object, DateTimeInterface $now): array {
		$listing = new VerbListing(schema: $schema, object: $object);

		$holder = $this->source->runWithoutGrant(fn (): array => $this->verbsFor(listing: $listing));
		$token  = $this->source->runWithoutGrant(
			function () use ($grant, $listing): array {
				$this->source->bind(grant: $grant);

				return $this->verbsFor(listing: $listing);
			}
		);
		$this->permissions->clearPermissionCache();

		$expired = $grant->isExpired(now: $now);
		$verbs   = [];
		foreach ($holder as $verb) {
			$verbs[] = [
				'verb'   => $verb,
				'holder' => true,
				'token'  => ($expired === false && in_array($verb, $token, true) === true),
			];
		}

		return ['verbs' => $verbs, 'expired' => $expired, 'empty' => $grant->isEmpty()];
	}//end preview()

	/**
	 * The row verbs the permission handler answers yes to, from a clean cache.
	 *
	 * @param VerbListing $listing The schema, the object and the session user.
	 *
	 * @return array<int, string> The verbs.
	 */
	private function verbsFor(VerbListing $listing): array {
		$this->permissions->clearPermissionCache();

		return $this->permissions->permittedActionsFor(listing: $listing);
	}//end verbsFor()
}//end class
