<?php

/**
 * Tests for how the aggregation cache listener turns a stored reference into a slug.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Listener\AggregationCacheInvalidationListener;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationCacheInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * A numeric reference becomes the entity's slug; anything else, or any failure, keeps the reference.
 *
 * @covers \OCA\OpenRegister\Listener\AggregationCacheInvalidationListener
 */
class AggregationCacheSlugOfTest extends TestCase {

	/**
	 * The slug the listener resolves for a reference when the mapper answers $answer.
	 *
	 * @param string $reference The stored reference.
	 * @param mixed  $answer    The slug the entity holds, or a Throwable the lookup throws.
	 * @param bool   $container Whether the listener has a container.
	 *
	 * @return string
	 */
	private function slugOf(string $reference, mixed $answer, bool $container = true): string {
		$mapper = new class ($answer) {
			/**
			 * Hold the answer.
			 *
			 * @param mixed $answer What find() leads to.
			 */
			public function __construct(private mixed $answer) {
			}

			/**
			 * Look up an entity.
			 *
			 * @param int  $id            The id.
			 * @param bool $_rbac         Ignored.
			 * @param bool $_multitenancy Ignored.
			 *
			 * @return object
			 */
			public function find(int $id, bool $_rbac = true, bool $_multitenancy = true): object {
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				$slug = $this->answer;
				return new class ($slug) {
					/**
					 * Hold the slug.
					 *
					 * @param mixed $slug The slug.
					 */
					public function __construct(private mixed $slug) {
					}

					/**
					 * The slug.
					 *
					 * @return mixed
					 */
					public function getSlug(): mixed {
						return $this->slug;
					}
				};
			}
		};
		$services = $this->createMock(ContainerInterface::class);
		$services->method('get')->willReturn($mapper);
		$listener = new AggregationCacheInvalidationListener(
			$this->createMock(AggregationCacheInterface::class),
			$container ? $services : null
		);
		return (new \ReflectionMethod($listener, 'slugOf'))->invoke($listener, $reference, 'AnyMapper');
	}

	/**
	 * Every way a reference resolves, or does not.
	 *
	 * @return void
	 */
	public function testSlugResolution(): void {
		$this->assertSame('pipelinq', $this->slugOf(reference: '20', answer: 'pipelinq'));
		$this->assertSame('pipelinq', $this->slugOf(reference: 'pipelinq', answer: 'other'));
		$this->assertSame('20', $this->slugOf(reference: '20', answer: 'pipelinq', container: false));
		$this->assertSame('20', $this->slugOf(reference: '20', answer: ''));
		$this->assertSame('20', $this->slugOf(reference: '20', answer: null));
		$this->assertSame('20', $this->slugOf(reference: '20', answer: 7));
		$this->assertSame('20', $this->slugOf(reference: '20', answer: new RuntimeException('gone')));
	}
}
