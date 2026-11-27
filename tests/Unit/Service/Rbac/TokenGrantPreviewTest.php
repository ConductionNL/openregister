<?php

/**
 * The grant editor's preview: the holder's verbs and the token's, both from
 * the permission handler, with the request's own grant restored afterwards.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
 */

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Object\VerbListing;
use OCA\OpenRegister\Service\Rbac\TokenGrant;
use OCA\OpenRegister\Service\Rbac\TokenGrantPreview;
use OCA\OpenRegister\Service\Rbac\TokenGrantSource;
use OCA\OpenRegister\Service\Rbac\TokenGrantTerms;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(TokenGrantPreview::class)]
#[UsesClass(TokenGrant::class)]
#[UsesClass(TokenGrantSource::class)]
#[UsesClass(TokenGrantTerms::class)]
#[UsesClass(VerbListing::class)]
final class TokenGrantPreviewTest extends TestCase {

	private TokenGrantSource $source;

	private PermissionHandlerInterface&MockObject $permissions;

	/**
	 * The grant bound at each permission question, in order.
	 *
	 * @var array<int, TokenGrant|null>
	 */
	private array $boundAtAsk = [];

	protected function setUp(): void {
		$this->source      = new TokenGrantSource();
		$this->permissions = $this->createMock(PermissionHandlerInterface::class);
		$this->permissions->method('permittedActionsFor')->willReturnCallback(
			function (VerbListing $listing): array {
				$grant              = $this->source->current();
				$this->boundAtAsk[] = $grant;

				// The handler's own answer: the holder may read, update and delete;
				// a bound grant narrows it to the verbs the grant names.
				$holder = ['read', 'update', 'delete'];

				return $grant === null ? $holder : array_values(array_filter($holder, fn (string $verb): bool => $grant->permits($verb)));
			}
		);
	}

	private static function grant(array $verbs, string $expiresAt): TokenGrant {
		return new TokenGrant(verbs: $verbs, terms: new TokenGrantTerms(expiresAt: new DateTimeImmutable($expiresAt)), tokenId: 'app-password:42');
	}

	public function testEachHolderVerbSaysWhetherTheTokenKeepsIt(): void {
		$preview = (new TokenGrantPreview($this->source, $this->permissions))->preview(
			grant: self::grant(['read'], '2999-01-01'),
			schema: new Schema(),
			object: new ObjectEntity(),
			now: new DateTimeImmutable('2026-11-17')
		);

		$this->assertSame(
			[
				['verb' => 'read', 'holder' => true, 'token' => true],
				['verb' => 'update', 'holder' => true, 'token' => false],
				['verb' => 'delete', 'holder' => true, 'token' => false],
			],
			$preview['verbs']
		);
		$this->assertFalse($preview['expired']);
		$this->assertFalse($preview['empty']);
	}

	public function testTheHolderIsAskedWithNoGrantAndTheTokenWithTheCandidate(): void {
		$candidate = self::grant(['read'], '2999-01-01');

		(new TokenGrantPreview($this->source, $this->permissions))->preview(
			grant: $candidate,
			schema: new Schema(),
			object: new ObjectEntity(),
			now: new DateTimeImmutable('2026-11-17')
		);

		$this->assertSame([null, $candidate], $this->boundAtAsk);
	}

	public function testTheRequestsOwnGrantIsRestoredAndTheCacheCleared(): void {
		$own = self::grant(['update'], '2999-01-01');
		$this->source->bind(grant: $own);
		$this->permissions->expects($this->atLeast(3))->method('clearPermissionCache');

		(new TokenGrantPreview($this->source, $this->permissions))->preview(
			grant: self::grant(['read'], '2999-01-01'),
			schema: new Schema(),
			object: new ObjectEntity(),
			now: new DateTimeImmutable('2026-11-17')
		);

		$this->assertSame($own, $this->source->current());
		$this->assertTrue($this->source->isBound());
	}

	public function testAnExpiredGrantKeepsNoVerb(): void {
		$preview = (new TokenGrantPreview($this->source, $this->permissions))->preview(
			grant: self::grant(['read', 'update'], '2026-01-01'),
			schema: new Schema(),
			object: new ObjectEntity(),
			now: new DateTimeImmutable('2026-11-17')
		);

		$this->assertTrue($preview['expired']);
		$this->assertSame([false, false, false], array_column($preview['verbs'], 'token'));
	}

	public function testTheObjectAndSchemaReachTheHandler(): void {
		$schema = new Schema();
		$object = new ObjectEntity();
		$this->permissions = $this->createMock(PermissionHandlerInterface::class);
		$this->permissions->expects($this->exactly(2))->method('permittedActionsFor')
			->with($this->callback(fn (VerbListing $listing): bool => $listing->schema === $schema && $listing->object === $object && $listing->userId === null))
			->willReturn([]);

		$preview = (new TokenGrantPreview($this->source, $this->permissions))->preview(
			grant: self::grant(['read'], '2999-01-01'),
			schema: $schema,
			object: $object,
			now: new DateTimeImmutable('2026-11-17')
		);

		$this->assertSame([], $preview['verbs']);
	}
}
