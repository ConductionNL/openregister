<?php

/**
 * Characterisation tests for how an API key is matched to an OpenRegister consumer.
 *
 * Pins allowed AND refused outcomes of `ConsumerMapperSource::findByApiKey()`:
 * an empty key is refused without a lookup; only `apiKey`-type consumers
 * (any letter case) are considered; a missing, empty or non-string stored key
 * never matches; a different key is refused; the first matching consumer in
 * list order is returned.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Consumer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Consumer;

use OCA\OpenRegister\Db\Consumer;
use OCA\OpenRegister\Db\ConsumerMapperInterface;
use OCA\OpenRegister\Service\Consumer\ConsumerMapperSource;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests for findByApiKey().
 */
class ConsumerMapperSourceApiKeyGoldenTest extends TestCase {

	/**
	 * A consumer with this name, type and configuration.
	 *
	 * @param string       $name          The name.
	 * @param string|null  $type          The authorization type.
	 * @param array<mixed> $configuration The authorization configuration.
	 *
	 * @return Consumer
	 */
	private function consumer(string $name, ?string $type, array $configuration): Consumer {
		$consumer = new Consumer();
		$consumer->setName($name);
		$consumer->setAuthorizationType($type);
		$consumer->setAuthorizationConfiguration($configuration);

		return $consumer;
	}//end consumer()

	/**
	 * A source over these consumers.
	 *
	 * @param array<int, Consumer> $consumers The consumers.
	 *
	 * @return ConsumerMapperSource
	 */
	private function source(array $consumers): ConsumerMapperSource {
		$mapper = $this->createMock(ConsumerMapperInterface::class);
		$mapper->method('findAll')->willReturn($consumers);

		return new ConsumerMapperSource($mapper);
	}//end source()

	/**
	 * Every candidate shape is matched or refused as before.
	 *
	 * @return void
	 */
	public function testApiKeyMatching(): void {
		$source = $this->source(
			[
				$this->consumer('jwt', 'jwt', ['apiKey' => 'k-1']),
				$this->consumer('none', null, ['apiKey' => 'k-1']),
				$this->consumer('missing', 'apiKey', []),
				$this->consumer('empty', 'apiKey', ['apiKey' => '']),
				$this->consumer('number', 'apiKey', ['apiKey' => 12]),
				$this->consumer('first', 'APIKEY', ['apiKey' => 'k-1']),
				$this->consumer('second', 'apikey', ['apiKey' => 'k-1']),
				$this->consumer('other', 'apikey', ['apiKey' => 'k-2']),
			]
		);

		$this->assertSame('first', $source->findByApiKey('k-1')?->name);
		$this->assertSame('other', $source->findByApiKey('k-2')?->name);
		$this->assertNull($source->findByApiKey('k-3'), 'an unknown key');
		$this->assertNull($source->findByApiKey('12'), 'a non-string stored key never matches');
		$this->assertNull($source->findByApiKey('k-1 '), 'keys are compared exactly');
	}//end testApiKeyMatching()

	/**
	 * An empty key is refused without looking anything up.
	 *
	 * @return void
	 */
	public function testAnEmptyKeyIsRefusedWithoutALookup(): void {
		$mapper = $this->createMock(ConsumerMapperInterface::class);
		$mapper->expects($this->never())->method('findAll');

		$this->assertNull((new ConsumerMapperSource($mapper))->findByApiKey(''));
	}//end testAnEmptyKeyIsRefusedWithoutALookup()
}//end class
