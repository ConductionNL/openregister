<?php

/**
 * Unit tests for RedactionPolicyCacheListener: the policy index follows its policy objects.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#REQ-RPD-001
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Listener;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Listener\RedactionPolicyCacheListener;
use OCA\OpenRegister\Service\RedactionPolicy\PolicyIndexCache;
use OCA\OpenRegister\Service\RedactionPolicy\TermRuleSourceInterface;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Listener\RedactionPolicyCacheListener
 * @uses   \OCA\OpenRegister\Service\RedactionPolicy\PolicyIndexCache
 * @uses   \OCA\OpenRegister\Service\RedactionPolicy\PolicyIndex
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Event\ObjectCreatedEvent
 * @uses   \OCA\OpenRegister\Event\ObjectUpdatedEvent
 * @uses   \OCA\OpenRegister\Event\ObjectDeletedEvent
 */
final class RedactionPolicyCacheListenerTest extends TestCase {

	/**
	 * The schema slugs the mocked mapper resolves ids to; a missing id throws.
	 *
	 * @var array<int, string>
	 */
	private array $slugs = [7 => 'termRule', 8 => 'detectionPattern', 9 => 'redactionProfile', 20 => 'person'];

	/**
	 * How many schema lookups ran.
	 *
	 * @var int
	 */
	private int $lookups = 0;

	private function cache(bool $built): PolicyIndexCache {
		$source = $this->createMock(TermRuleSourceInterface::class);
		$source->method('termRuleObjects')->willReturn([]);
		$clock = $this->createMock(ClockInterface::class);
		$clock->method('now')->willReturn(new DateTimeImmutable('2026-11-13T12:00:00Z'));
		$cache = new PolicyIndexCache($source, $clock);
		if ($built === true) {
			$cache->index();
		}

		return $cache;
	}

	private function listener(PolicyIndexCache $cache): RedactionPolicyCacheListener {
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(
			function (string|int $id, ?array $_extend=[], bool $_rbac=true, bool $_multitenancy=true): Schema {
				$this->lookups++;
				$this->assertFalse($_rbac);
				$this->assertFalse($_multitenancy);
				if (isset($this->slugs[(int) $id]) === false) {
					throw new RuntimeException('no schema');
				}

				$schema = new Schema();
				$schema->setSlug($this->slugs[(int) $id]);
				return $schema;
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(SchemaMapperInterface::class)->willReturn($schemas);

		return new RedactionPolicyCacheListener($cache, $container);
	}

	private static function object(string $schema): ObjectEntity {
		$object = new ObjectEntity();
		$object->setSchema($schema);
		return $object;
	}

	public function testAWriteToEachPolicySchemaDropsTheIndex(): void {
		foreach (['7', '8', '9', 'termRule'] as $schema) {
			foreach ([new ObjectCreatedEvent(self::object($schema)), new ObjectUpdatedEvent(self::object($schema), null), new ObjectDeletedEvent(self::object($schema))] as $event) {
				$cache = $this->cache(built: true);
				$this->listener($cache)->handle($event);
				$this->assertFalse($cache->isBuilt(), $schema . ' ' . $event::class);
			}
		}
	}

	public function testAWriteToAnotherSchemaKeepsTheIndex(): void {
		foreach (['20', 'person'] as $schema) {
			$cache = $this->cache(built: true);
			$this->listener($cache)->handle(new ObjectUpdatedEvent(self::object($schema), null));
			$this->assertTrue($cache->isBuilt(), $schema);
		}
	}

	public function testWithoutAnIndexNothingIsLookedUp(): void {
		$cache = $this->cache(built: false);
		$this->listener($cache)->handle(new ObjectCreatedEvent(self::object('7')));

		$this->assertSame(0, $this->lookups);
		$this->assertFalse($cache->isBuilt());
	}

	public function testAnUnresolvableSchemaDropsTheIndex(): void {
		$cache = $this->cache(built: true);
		$this->listener($cache)->handle(new ObjectUpdatedEvent(self::object('404'), null));

		$this->assertSame(1, $this->lookups);
		$this->assertFalse($cache->isBuilt());
	}

	public function testAnEventWithoutAnObjectKeepsTheIndex(): void {
		$cache = $this->cache(built: true);
		$this->listener($cache)->handle(new Event());

		$this->assertTrue($cache->isBuilt());
		$this->assertSame(0, $this->lookups);
	}
}//end class
