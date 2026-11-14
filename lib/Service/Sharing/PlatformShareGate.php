<?php

/**
 * PlatformShareGate — what a platform share of an object must pass before it becomes an object grant.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sharing
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
 * @spec openspec/changes/platform-share-provider/specs/platform-share-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Sharing;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Rbac\GrantRequest;
use OCA\OpenRegister\Service\Rbac\ObjectSharingServiceInterface;

/**
 * The schema's opt-in and the permission mapping, in front of the one object share model.
 *
 * A face on one model, never a second store (design D-1): a share accepted
 * here is created by {@see ObjectSharingServiceInterface::grantWithTerms()},
 * the same owner-checked path the object's own share panel uses, so the
 * object's share list and the platform see the same share, once.
 *
 * @spec openspec/changes/platform-share-provider/specs/platform-share-provider/spec.md
 */
final class PlatformShareGate {

	/**
	 * The schema configuration key that opts a schema's objects into platform sharing.
	 *
	 * @var string
	 */
	public const CONFIGURATION_KEY = 'platformSharing';

	/**
	 * The permission mapping.
	 *
	 * @var PlatformSharePermissions
	 */
	private readonly PlatformSharePermissions $permissions;

	/**
	 * Constructor.
	 *
	 * @param ObjectSharingServiceInterface $sharing     The owner-checked object share writer.
	 * @param PlatformSharePermissions|null $permissions The permission mapping; the default one when null.
	 */
	public function __construct(
		private readonly ObjectSharingServiceInterface $sharing,
		?PlatformSharePermissions $permissions = null,
	) {
		$this->permissions = ($permissions ?? new PlatformSharePermissions());
	}//end __construct()

	/**
	 * Whether a schema offers its objects for platform sharing.
	 *
	 * Out unless the schema says `true` (design D-3): sharing on reference data
	 * and on rows nobody should hand out is never offered by default.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return bool True only when the schema declares `platformSharing: true`.
	 *
	 * @spec openspec/changes/platform-share-provider/specs/platform-share-provider/spec.md
	 */
	public function isOffered(Schema $schema): bool {
		return (($schema->getConfiguration() ?? [])[self::CONFIGURATION_KEY] ?? false) === true;
	}//end isOffered()

	/**
	 * Share one object through the platform, as an ordinary object grant.
	 *
	 * Checked in this order: the schema's opt-in, then every permission bit;
	 * only then is the grant written, by the object share model itself (which
	 * applies its own owner-or-manager check).
	 *
	 * @param ObjectEntity $object  The object to share.
	 * @param Schema       $schema  The object's schema.
	 * @param GrantRequest $request The principal type, the principal and the terms (permissions, expiry, note).
	 *
	 * @throws InvalidArgumentException When the schema does not offer platform sharing, or a bit maps to no verb.
	 *
	 * @return array<string, mixed> The created grant, with the verbs its bits granted and its note.
	 *
	 * @spec openspec/changes/platform-share-provider/specs/platform-share-provider/spec.md
	 */
	public function share(ObjectEntity $object, Schema $schema, GrantRequest $request): array {
		if ($this->isOffered(schema: $schema) === false) {
			throw new InvalidArgumentException(
				sprintf('Schema "%s" does not offer its objects for platform sharing', (string)($schema->getSlug() ?? $schema->getId()))
			);
		}

		$verbs = $this->permissions->verbsFor(permissions: $request->terms->permissions);

		$grant = $this->sharing->grantWithTerms(object: $object, request: $request);

		return $grant + ['grantedVerbs' => $verbs, 'note' => $request->terms->note];
	}//end share()
}//end class
