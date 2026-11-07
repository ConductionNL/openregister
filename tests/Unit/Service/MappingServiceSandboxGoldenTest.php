<?php

/**
 * Golden test for the MappingService Twig environment and its cache plumbing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapperInterface;
use OCA\OpenRegister\Service\MappingService;
use OCA\OpenRegister\Service\RegisterMappingFunctionsEvent;
use OCA\OpenRegister\Twig\MappingExtension;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use RuntimeException;
use Twig\Environment;
use Twig\Extension\EscaperExtension;
use Twig\Extension\SandboxExtension;
use Twig\Sandbox\SecurityPolicy;
use Twig\TwigFunction;

/**
 * Pins what the mapping engine allows and refuses, and how it caches.
 *
 * @covers \OCA\OpenRegister\Service\MappingService
 */
class MappingServiceSandboxGoldenTest extends TestCase {

	private const TAGS = ['apply', 'for', 'if', 'set'];

	private const FILTERS = [
		'abs', 'b64dec', 'b64enc', 'date', 'date_modify', 'default', 'escape', 'first', 'format', 'join',
		'json_decode', 'json_encode', 'keys', 'last', 'length', 'lower', 'merge', 'number_format', 'raw',
		'replace', 'round', 'slice', 'split', 'trim', 'upper', 'zgw_enum', 'zgw_enum_reverse', 'zgw_extract_uuid',
	];

	private const FUNCTIONS = [
		'b64dec', 'b64enc', 'createSlug', 'executeMapping', 'generateUuid', 'getFileContents', 'getFiles',
		'jsonDecode', 'json_decode', 'max', 'min', 'range', 'zgwEnum', 'zgwEnumReverse', 'zgwExtractUuid',
	];

	private function service(?IEventDispatcher $events = null, ?ICacheFactory $factory = null, ?LoggerInterface $logger = null): MappingService {
		if ($factory === null) {
			$factory = $this->createMock(ICacheFactory::class);
			$factory->method('createDistributed')->willReturn($this->createMock(ICache::class));
		}

		return new MappingService(
			$this->createMock(MappingMapperInterface::class),
			$factory,
			$logger ?? $this->createMock(LoggerInterface::class),
			$events
		);
	}

	private function twig(MappingService $service): Environment {
		$prop = new ReflectionProperty(MappingService::class, 'twig');
		return $prop->getValue($service);
	}

	private function policyList(SecurityPolicy $policy, string $name): array {
		$prop = new ReflectionProperty(SecurityPolicy::class, $name);
		$list = $prop->getValue($policy);
		sort($list);
		return $list;
	}

	private function render(MappingService $service, string $template, array $input = []): string {
		$mapping = new Mapping();
		$mapping->setMapping(['out' => $template]);
		$mapping->setUnset([]);
		$mapping->setCast([]);
		$mapping->setPassThrough(false);
		return (string)($service->executeMapping($mapping, $input)['out'] ?? '');
	}

	public function testSandboxPolicyIsPinned(): void {
		$twig    = $this->twig($this->service());
		$sandbox = $twig->getExtension(SandboxExtension::class);
		$policy  = $sandbox->getSecurityPolicy();

		$this->assertTrue($sandbox->isSandboxed());
		$this->assertSame(self::TAGS, $this->policyList($policy, 'allowedTags'));
		$this->assertSame(self::FILTERS, $this->policyList($policy, 'allowedFilters'));
		$this->assertSame(self::FUNCTIONS, $this->policyList($policy, 'allowedFunctions'));
		$this->assertSame([], $this->policyList($policy, 'allowedMethods'));
		$this->assertSame([], $this->policyList($policy, 'allowedProperties'));
		$this->assertFalse($twig->getExtension(EscaperExtension::class)->getDefaultStrategy('m.twig'));
		$this->assertTrue($twig->hasExtension(MappingExtension::class));
	}

	public function testAllowedFilterRendersAndRefusedFilterFails(): void {
		$service = $this->service();
		$this->assertSame('ABC', $this->render($service, '{{ v|upper }}', ['v' => 'abc']));
		$this->assertSame('{"a":1}', $this->render($service, '{{ v|json_encode }}', ['v' => ['a' => 1]]));

		try {
			$this->render($service, '{{ v|capitalize }}', ['v' => 'abc']);
			$this->fail('capitalize must be refused');
		} catch (\Exception $e) {
			$this->assertStringContainsString('Filter "capitalize" is not allowed', $e->getMessage());
		}
	}

	public function testRefusedTagFails(): void {
		$this->expectException(\Exception::class);
		$this->expectExceptionMessageMatches('/Tag "macro" is not allowed/');
		$this->render($this->service(), '{% macro m() %}x{% endmacro %}');
	}

	public function testContributedFunctionIsRegisteredAndAllowed(): void {
		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			static function (RegisterMappingFunctionsEvent $event): void {
				$event->registerFunction(new TwigFunction('contributed', static fn (): string => 'from-app'));
			}
		);

		$service = $this->service($events);
		$policy  = $this->twig($service)->getExtension(SandboxExtension::class)->getSecurityPolicy();

		$this->assertSame('from-app', $this->render($service, '{{ contributed() }}'));
		$this->assertContains('contributed', $this->policyList($policy, 'allowedFunctions'));
	}

	public function testThrowingContributorIsLoggedAndIgnored(): void {
		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willThrowException(new RuntimeException('boom'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			$this->stringContains('Could not collect contributed Twig functions: boom'),
			$this->anything()
		);

		$service = $this->service($events, null, $logger);
		$policy  = $this->twig($service)->getExtension(SandboxExtension::class)->getSecurityPolicy();
		$this->assertSame(self::FUNCTIONS, $this->policyList($policy, 'allowedFunctions'));
	}

	public function testCacheFactoryFailureFallsBackToDatabase(): void {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willThrowException(new \Exception('no cache'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'[MappingService] Failed to initialize distributed cache, falling back to DB-only',
			['error' => 'no cache']
		);
		$mapper  = $this->createMock(MappingMapperInterface::class);
		$mapping = new Mapping();
		$mapper->expects($this->exactly(2))->method('find')->with('m1')->willReturn($mapping);

		$service = new MappingService($mapper, $factory, $logger);
		$this->assertSame($mapping, $service->getMapping('m1'));
		$this->assertSame($mapping, $service->getMapping('m1'));
	}

	public function testCacheHitHydratesWithIdAndMissStoresSerialized(): void {
		$cache   = $this->createMock(ICache::class);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->with('openregister_mapping_')->willReturn($cache);
		$cache->method('get')->willReturnMap([['hit', ['id' => 7, 'name' => 'cached']], ['miss', null]]);
		$mapper = $this->createMock(MappingMapperInterface::class);
		$found  = new Mapping();
		$found->setName('db');
		$mapper->expects($this->once())->method('find')->with('miss')->willReturn($found);
		$cache->expects($this->once())->method('set')->with('miss', $found->jsonSerialize(), 300);

		$service = new MappingService($mapper, $factory, $this->createMock(LoggerInterface::class));
		$hit     = $service->getMapping('hit');
		$this->assertSame(7, $hit->getId());
		$this->assertSame('cached', $hit->getName());
		$this->assertSame($found, $service->getMapping('miss'));
	}

	public function testCoordinateStringToArrayEdgeCases(): void {
		$service = $this->service();
		$this->assertSame([''], $service->coordinateStringToArray(''));
		$this->assertSame(['1'], $service->coordinateStringToArray('1'));
		$this->assertSame(['1', '2'], $service->coordinateStringToArray('1 2'));
		$this->assertSame([['1', '2'], ['3']], $service->coordinateStringToArray('1 2 3'));
		$this->assertSame([['1', '2'], ['3', '4'], ['5', '6']], $service->coordinateStringToArray('1 2 3 4 5 6'));
	}

	public function testEncodeArrayKeysKeepsEmptyArraysAndOrder(): void {
		$service = $this->service();
		$this->assertSame(
			['a&b' => [], 'c' => ['d&e' => 1, 'f' => ['g&h' => null]], 'i' => 'j'],
			$service->encodeArrayKeys(['a.b' => [], 'c' => ['d.e' => 1, 'f' => ['g.h' => null]], 'i' => 'j'], '.', '&')
		);
	}
}
