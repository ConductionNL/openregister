<?php

/**
 * Tests for HeldBackAuthorization: which shipped authorization blocks an upgrade holds back.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ShippedBaseline
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
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ShippedBaseline;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\ShippedBaseline\DescriptorParts;
use OCA\OpenRegister\Service\ShippedBaseline\DivergenceComparator;
use OCA\OpenRegister\Service\ShippedBaseline\HeldBackAuthorization;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedBaselineStore;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins when a shipped authorization block counts as held back.
 */
final class HeldBackAuthorizationTest extends TestCase {

	/**
	 * The read rule the app now ships.
	 *
	 * @var array<string, mixed>
	 */
	private const SHIPPED = [
		'slug' => 'dashboard',
		'properties' => ['title' => ['type' => 'string']],
		'authorization' => ['read' => ['launchpad-audience']],
	];

	public function testABlockBothSidesChangedIsHeldBack(): void {
		$held = $this->heldBack(
			baseline: ['properties' => ['title' => ['type' => 'string']], 'authorization' => null],
			liveAuthorization: ['read' => ['staff']]
		);

		$this->assertSame(['authorization'], $held->roots(slug: 'dashboard', shipped: self::SHIPPED));
	}

	public function testTheEntityDefaultAgainstANullBaselineHoldsNothingBack(): void {
		$held = $this->heldBack(
			baseline: ['properties' => ['title' => ['type' => 'string']], 'authorization' => null],
			liveAuthorization: []
		);

		$this->assertSame([], $held->roots(slug: 'dashboard', shipped: self::SHIPPED));
	}

	public function testABlockOnlyTheInstanceChangedIsNotHeldBack(): void {
		$held = $this->heldBack(
			baseline: ['properties' => ['title' => ['type' => 'string']], 'authorization' => ['read' => ['launchpad-audience']]],
			liveAuthorization: ['read' => ['staff']]
		);

		$this->assertSame([], $held->roots(slug: 'dashboard', shipped: self::SHIPPED));
	}

	public function testAPropertyBlockBothSidesChangedIsHeldBack(): void {
		$shipped = [
			'properties' => ['salary' => ['type' => 'number', 'authorization' => ['read' => ['hr']]]],
		];
		$held = $this->heldBack(
			baseline: ['properties' => ['salary' => ['type' => 'number']]],
			liveAuthorization: [],
			liveProperties: ['salary' => ['type' => 'number', 'authorization' => ['read' => ['finance']]]]
		);

		$this->assertSame(['properties.salary.authorization'], $held->roots(slug: 'dashboard', shipped: $shipped));
	}

	public function testWithoutABaselineNothingIsHeldBack(): void {
		$held = $this->heldBack(baseline: null, liveAuthorization: ['read' => ['staff']]);

		$this->assertSame([], $held->roots(slug: 'dashboard', shipped: self::SHIPPED));
	}

	public function testWithoutTheSchemaNothingIsHeldBack(): void {
		$held = $this->heldBack(
			baseline: ['authorization' => null],
			liveAuthorization: [],
			missing: new DoesNotExistException('gone')
		);

		$this->assertSame([], $held->roots(slug: 'dashboard', shipped: self::SHIPPED));
	}

	public function testAnUnreadableSchemaIsLoggedAndHoldsNothingBack(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('dashboard'));
		$held = $this->heldBack(
			baseline: ['authorization' => null],
			liveAuthorization: [],
			missing: new RuntimeException('database down'),
			logger: $logger
		);

		$this->assertSame([], $held->roots(slug: 'dashboard', shipped: self::SHIPPED));
	}

	public function testAnEmptySlugIsNotLookedUp(): void {
		$held = $this->heldBack(baseline: ['authorization' => null], liveAuthorization: ['read' => ['staff']]);

		$this->assertSame([], $held->roots(slug: '', shipped: self::SHIPPED));
	}

	/**
	 * The service over a stored baseline and a live `dashboard` schema.
	 *
	 * @param array<string, mixed>|null $baseline          The stored baseline definition, or null for none.
	 * @param array<string, mixed>      $liveAuthorization The live schema-level authorization.
	 * @param array<string, mixed>      $liveProperties    The live properties.
	 * @param \Throwable|null           $missing           What finding the schema throws, or null when it exists.
	 * @param LoggerInterface|null      $logger            The logger.
	 *
	 * @return HeldBackAuthorization
	 */
	private function heldBack(
		?array $baseline,
		array $liveAuthorization,
		array $liveProperties = ['title' => ['type' => 'string']],
		?\Throwable $missing = null,
		?LoggerInterface $logger = null,
	): HeldBackAuthorization {
		$store = $this->createMock(ShippedBaselineStore::class);
		$store->method('schemaSubject')->willReturnCallback(static fn (string $slug): string => 'schema:' . $slug);
		$stored = null;
		if ($baseline !== null) {
			$stored = ['definition' => $baseline, 'app' => 'launchpad', 'appVersion' => '1.0.0', 'recordedAt' => ''];
		}

		$store->method('read')->willReturnCallback(
			static fn (string $subject): ?array => ($subject === 'schema:dashboard' ? $stored : null)
		);

		$schema = new Schema();
		$schema->setSlug('dashboard');
		$schema->setProperties($liveProperties);
		$schema->setAuthorization($liveAuthorization);

		$schemas = $this->createMock(SchemaMapperInterface::class);
		if ($missing !== null) {
			$schemas->method('find')->willThrowException($missing);
		} else {
			$schemas->method('find')->willReturn($schema);
		}

		$parts = new DescriptorParts();

		return new HeldBackAuthorization(
			baselines: $store,
			schemas: $schemas,
			comparator: new DivergenceComparator(parts: $parts),
			parts: $parts,
			logger: ($logger ?? $this->createMock(LoggerInterface::class))
		);
	}
}
