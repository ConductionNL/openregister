<?php

/**
 * A federated principal is ONE MORE PRINCIPAL, not a second decision path.
 *
 * Design D5 asks that a remote principal be resolved by the same evaluator as a
 * local one. That is satisfied structurally rather than by extra code: the two
 * remote share types sit in the SAME lists as the user and group types, so a
 * remote grant flows through `grantedObjectUuidsFor()`, into the same SQL
 * disjunct, and into the same PHP verdict. There is no federated branch to keep
 * in step with the local one — which is the property worth protecting.
 *
 * This test pins the two lists. It exists because the property is invisible: it
 * is true only as long as nobody edits those constants, and nothing else in the
 * suite would notice if they did.
 *
 * WHAT IT DELIBERATELY DOES NOT CLAIM. It does not prove that an inbound
 * federated grant from a real peer admits a real remote user. That needs a
 * SECOND Nextcloud instance and an OCM handshake, and no amount of single-
 * instance testing substitutes for it — see tasks 7.2/7.3.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/share-links-and-email-invites/spec.md#requirement-a-remote-principal-is-one-more-principal
 */

declare(strict_types=1);

namespace Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\ObjectGrantResolver;
use OCA\OpenRegister\Service\Rbac\ObjectSharingService;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins the remote types into the principal vocabulary on both sides.
 */
class FederatedPrincipalVocabularyTest extends TestCase {

	/**
	 * Read a private class constant.
	 *
	 * Reflection is the point here: these lists are private BECAUSE nothing
	 * should depend on them at runtime, and this test exists precisely to depend
	 * on them at build time.
	 *
	 * @param string $class The class.
	 * @param string $name The constant name.
	 *
	 * @return array<mixed> The constant value.
	 */
	private function constant(string $class, string $name): array {
		return (array)(new ReflectionClass($class))->getConstant($name);
	}//end constant()

	/**
	 * The RESOLVER treats both remote types as principals.
	 *
	 * If a remote type were dropped here, a federated grant would stop being
	 * seen by the RBAC filter and the object would silently vanish for the
	 * remote user — an over-filtering failure, which presents as an empty page.
	 */
	public function testTheResolverTreatsRemoteTypesAsPrincipals(): void {
		$types = $this->constant(ObjectGrantResolver::class, 'PRINCIPAL_SHARE_TYPES');

		$this->assertContains(IShare::TYPE_REMOTE, $types);
		$this->assertContains(IShare::TYPE_REMOTE_GROUP, $types);

		// And the local ones, so this reads as one list rather than a carve-out.
		$this->assertContains(IShare::TYPE_USER, $types);
		$this->assertContains(IShare::TYPE_GROUP, $types);
	}//end testTheResolverTreatsRemoteTypesAsPrincipals()

	/**
	 * The WRITE surface can grant to both remote types.
	 */
	public function testTheWriteSurfaceCanGrantToRemotePrincipals(): void {
		$types = $this->constant(ObjectSharingService::class, 'GRANTABLE_TYPES');

		$this->assertSame(IShare::TYPE_REMOTE, ($types['remote'] ?? null));
		$this->assertSame(IShare::TYPE_REMOTE_GROUP, ($types['remote_group'] ?? null));
	}//end testTheWriteSurfaceCanGrantToRemotePrincipals()

	/**
	 * The BEARER types are absent from the principal list, on both sides.
	 *
	 * A link or email share admits whoever holds the token and is decided on the
	 * public endpoint. If either leaked into the principal list it would ALSO be
	 * resolved by the RBAC filter, and a link would start behaving like a grant
	 * to every logged-in user — per-object publication by accident.
	 */
	public function testBearerTypesAreNotPrincipals(): void {
		$resolverTypes = $this->constant(ObjectGrantResolver::class, 'PRINCIPAL_SHARE_TYPES');
		$grantableById = array_values($this->constant(ObjectSharingService::class, 'GRANTABLE_TYPES'));

		foreach ([IShare::TYPE_LINK, IShare::TYPE_EMAIL] as $bearer) {
			$this->assertNotContains($bearer, $resolverTypes, 'a bearer type must not be resolved as a principal');
			$this->assertNotContains($bearer, $grantableById, 'a bearer type must not be creatable as a grant');
		}
	}//end testBearerTypesAreNotPrincipals()

	/**
	 * The bearer types ARE listed, even though they are not grantable.
	 *
	 * The complement of the test above, and the two must both hold. While
	 * listGrants() iterated GRANTABLE_TYPES, links and email invitations were
	 * WRITE-ONLY: createLink() minted a working public link that never appeared
	 * in the panel, so the revoke control for it did not exist and the only way
	 * to withdraw it was raw SQL or core's Files UI.
	 *
	 * A capability you cannot see is a capability you cannot revoke, which is
	 * the worse half of an access-control surface to get wrong.
	 */
	public function testBearerTypesAreListedSoTheyCanBeRevoked(): void {
		$listable = $this->constant(ObjectSharingService::class, 'LISTABLE_TYPES');

		$this->assertSame(
			IShare::TYPE_LINK,
			($listable['link'] ?? null),
			'a public link must be listed, or there is no way to revoke it through the UI'
		);
		$this->assertSame(
			IShare::TYPE_EMAIL,
			($listable['email'] ?? null),
			'an email invitation must be listed, or there is no way to revoke it through the UI'
		);
	}//end testBearerTypesAreListedSoTheyCanBeRevoked()

	/**
	 * Listable is a strict SUPERSET of grantable.
	 *
	 * Two separate constants can drift, and drift in either direction is a
	 * defect: a principal type missing from the listable set becomes an
	 * invisible grant, and a bearer type leaking into the grantable set lets
	 * `type=link` posted at the grant endpoint bypass the link surface's own
	 * rules. This pins the relationship rather than the two lists separately.
	 */
	public function testEveryGrantableTypeIsAlsoListable(): void {
		$grantable = $this->constant(ObjectSharingService::class, 'GRANTABLE_TYPES');
		$listable = $this->constant(ObjectSharingService::class, 'LISTABLE_TYPES');

		foreach ($grantable as $label => $shareType) {
			$this->assertSame(
				$shareType,
				($listable[$label] ?? null),
				"grantable type '$label' is not listable — a grant that cannot be seen cannot be revoked"
			);
		}

		$this->assertGreaterThan(
			count($grantable),
			count($listable),
			'listable must be a STRICT superset — the bearer types belong to it and not to grantable'
		);
	}//end testEveryGrantableTypeIsAlsoListable()

}//end class
