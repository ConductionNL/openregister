<?php

/**
 * Unit tests for naming the granted token behind a write as `actorVia`.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Audit
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-the-effective-grant-is-visible-and-writes-name-the-token
 */

declare(strict_types=1);

namespace Unit\Service\Audit;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Service\Audit\AuditRowAttribution;
use OCA\OpenRegister\Service\Audit\TokenAttribution;
use OCA\OpenRegister\Service\Rbac\TokenGrant;
use OCA\OpenRegister\Service\Rbac\TokenGrantSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

#[CoversClass(TokenAttribution::class)]
#[UsesClass(AuditRowAttribution::class)]
#[UsesClass(TokenGrant::class)]
#[UsesClass(TokenGrantSource::class)]
#[UsesClass(AuditTrail::class)]
final class TokenAttributionActorViaTest extends TestCase {
	private function attribution(?TokenGrantSource $source): TokenAttribution {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $class) use ($source): object {
				if ($class === TokenGrantSource::class && $source !== null) {
					return $source;
				}

				throw new \RuntimeException('not registered: ' . $class);
			}
		);

		return new TokenAttribution($container);
	}//end attribution()

	private function bound(?TokenGrant $grant): TokenGrantSource {
		$source = new TokenGrantSource();
		$source->bind($grant);

		return $source;
	}//end bound()

	public function testAWriteThroughAGrantedTokenNamesItAsActorVia(): void {
		$entry = new AuditTrail();
		$entry->setUser('leverancier');

		$this->attribution($this->bound(new TokenGrant(verbs: ['read', 'update'], tokenId: 'consumer-42')))->apply($entry);

		self::assertSame('consumer-42', ($entry->getResultSummary() ?? [])['actorVia'] ?? null);
		self::assertSame('leverancier', $entry->getUser(), 'the user stays the actor');
	}//end testAWriteThroughAGrantedTokenNamesItAsActorVia()

	public function testActorViaKeepsTheRestOfTheSummary(): void {
		$entry = new AuditTrail();
		$entry->setResultSummary(['purpose' => 'toezicht']);

		$this->attribution($this->bound(new TokenGrant(verbs: ['read'], tokenId: 'token-7')))->apply($entry);

		self::assertSame(['purpose' => 'toezicht', 'actorVia' => 'token-7'], $entry->getResultSummary());
	}//end testActorViaKeepsTheRestOfTheSummary()

	public function testAnUngrantedRequestCarriesNoActorVia(): void {
		$entry = new AuditTrail();

		$this->attribution($this->bound(null))->apply($entry);

		self::assertArrayNotHasKey('actorVia', ($entry->getResultSummary() ?? []));
	}//end testAnUngrantedRequestCarriesNoActorVia()

	public function testAGrantWithoutAnIdCarriesNoActorVia(): void {
		$entry = new AuditTrail();

		$this->attribution($this->bound(new TokenGrant(verbs: ['read'])))->apply($entry);

		self::assertArrayNotHasKey('actorVia', ($entry->getResultSummary() ?? []));
	}//end testAGrantWithoutAnIdCarriesNoActorVia()

	public function testAnUnresolvableGrantSourceWritesNothing(): void {
		$entry = new AuditTrail();

		$this->attribution(null)->apply($entry);

		self::assertNull($entry->getResultSummary());
		self::assertNull($entry->getConsumer());
	}//end testAnUnresolvableGrantSourceWritesNothing()
}//end class
