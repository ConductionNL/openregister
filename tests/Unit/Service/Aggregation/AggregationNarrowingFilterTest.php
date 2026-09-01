<?php

/**
 * Unit tests for caller-supplied NARROWING of a declared aggregation filter.
 *
 * A declared aggregation's filter is its contract. A caller may ADD a
 * constraint; it may never relax, replace or remove one. That asymmetry is the
 * whole security property: a declared `administrationId` is a scoping filter,
 * and a request able to overwrite it would turn tenancy into a caller-chosen
 * parameter.
 *
 * These tests exist to make that one-directional. The interesting cases are
 * not "does narrowing work" but "does every attempt to WIDEN get refused",
 * which is why most of what follows asserts a declared value survives.
 *
 * NOTE ON COVERAGE METADATA: deliberately none — under
 * `beStrictAboutCoverageMetadata="true"` a test touching any un-named
 * collaborator has its coverage discarded wholesale. See issue #2847.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Aggregation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/aggregation-api/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Aggregation;

use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The narrowing-only merge of caller filters into a declared filter.
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */
final class AggregationNarrowingFilterTest extends TestCase {

	/**
	 * Invoke the private merge without constructing the whole runner.
	 *
	 * The method is pure — declared array in, merged array out, with one
	 * optional logger call — so reflection is cheaper and more honest here
	 * than mocking a dozen collaborators the logic never touches.
	 *
	 * @param array<string, mixed> $declared The declared filter.
	 * @param array<string, mixed> $extra    Caller-supplied constraints.
	 *
	 * @return array<string, mixed> The merged filter.
	 */
	private function merge(array $declared, array $extra): array {
		$reflection = new ReflectionClass(AggregationRunner::class);
		$runner = $reflection->newInstanceWithoutConstructor();

		// `$logger` is a promoted constructor parameter (`?LoggerInterface
		// $logger = null`), so in production it is ALWAYS initialised — to null
		// when nothing was injected. newInstanceWithoutConstructor() leaves it
		// merely declared, and reading an uninitialised typed property throws
		// regardless of `?->`, which guards null and not uninitialised. Setting
		// it explicitly models the real "no logger injected" state rather than
		// a state the class can never actually be in.
		$loggerProperty = $reflection->getProperty('logger');
		$loggerProperty->setAccessible(true);
		$loggerProperty->setValue($runner, null);

		$method = $reflection->getMethod('mergeNarrowingFilter');
		$method->setAccessible(true);

		return $method->invoke($runner, $declared, $extra, 'testAggregation');

	}//end merge()

	/**
	 * A new key is added — the narrowing case that motivated this.
	 *
	 * @return void
	 */
	public function testANewConstraintIsAdded(): void {
		self::assertSame(
			['afgesloten' => false, 'administrationId' => 'adm-demo'],
			$this->merge(['afgesloten' => false], ['administrationId' => 'adm-demo'])
		);

	}//end testANewConstraintIsAdded()

	/**
	 * A declared key is NOT overwritten. This is the security property.
	 *
	 * If this ever passes with the caller's value winning, a declared scoping
	 * filter has become a request parameter.
	 *
	 * @return void
	 */
	public function testADeclaredKeyCannotBeOverwritten(): void {
		$merged = $this->merge(
			['administrationId' => 'adm-owned'],
			['administrationId' => 'adm-someone-elses']
		);

		self::assertSame(['administrationId' => 'adm-owned'], $merged);

	}//end testADeclaredKeyCannotBeOverwritten()

	/**
	 * A declared key cannot be relaxed by sending an empty value either.
	 *
	 * The obvious bypass attempt: if '' were accepted as "no constraint",
	 * a caller could blank the scoping filter.
	 *
	 * @return void
	 */
	public function testADeclaredKeyCannotBeBlanked(): void {
		self::assertSame(
			['administrationId' => 'adm-owned'],
			$this->merge(['administrationId' => 'adm-owned'], ['administrationId' => ''])
		);

	}//end testADeclaredKeyCannotBeBlanked()

	/**
	 * An operator filter is refused — it can widen rather than narrow.
	 *
	 * `['ne' => 'x']` or `['gt' => 0]` express "everything except" and
	 * "more than", neither of which is a narrowing of an unconstrained set.
	 *
	 * @return void
	 */
	public function testOperatorFiltersAreRefused(): void {
		self::assertSame(
			['afgesloten' => false],
			$this->merge(['afgesloten' => false], ['amount' => ['gt' => 0]])
		);
		self::assertSame(
			['afgesloten' => false],
			$this->merge(['afgesloten' => false], ['status' => ['ne' => 'closed']])
		);

	}//end testOperatorFiltersAreRefused()

	/**
	 * An empty caller value on a NEW key is dropped.
	 *
	 * A caller omitting a query parameter must not silently become a filter
	 * on the empty string, which would match nothing and look like "no data".
	 *
	 * @return void
	 */
	public function testEmptyValueOnANewKeyIsDropped(): void {
		self::assertSame(
			['afgesloten' => false],
			$this->merge(['afgesloten' => false], ['administrationId' => ''])
		);

	}//end testEmptyValueOnANewKeyIsDropped()

	/**
	 * A non-string key is ignored.
	 *
	 * `?filter[]=x` arrives as a list, and a numeric key is not a property.
	 *
	 * @return void
	 */
	public function testNonStringKeysAreIgnored(): void {
		self::assertSame(
			['afgesloten' => false],
			$this->merge(['afgesloten' => false], ['bare-value'])
		);

	}//end testNonStringKeysAreIgnored()

	/**
	 * No caller filter leaves the declared filter byte-identical.
	 *
	 * The overwhelmingly common path must not be perturbed.
	 *
	 * @return void
	 */
	public function testNoCallerFilterIsAPassthrough(): void {
		$declared = ['afgesloten' => false, 'administrationId' => 'adm-demo'];

		self::assertSame($declared, $this->merge($declared, []));

	}//end testNoCallerFilterIsAPassthrough()

	/**
	 * Several constraints narrow together, and a refused one does not block
	 * the accepted ones.
	 *
	 * A caller sending one bad key alongside good ones should still get the
	 * good ones — silently dropping the whole request would be its own
	 * confusing failure.
	 *
	 * @return void
	 */
	public function testAcceptedAndRefusedKeysAreHandledIndependently(): void {
		self::assertSame(
			[
				'afgesloten' => false,
				'administrationId' => 'adm-demo',
				'financialYear' => 2026,
			],
			$this->merge(
				['afgesloten' => false],
				[
					'administrationId' => 'adm-demo',
					'afgesloten' => true,
					'financialYear' => 2026,
				]
			)
		);

	}//end testAcceptedAndRefusedKeysAreHandledIndependently()

	/**
	 * The merge does not require a logger.
	 *
	 * The runner takes `?LoggerInterface $logger = null`, and the refusal
	 * branch logs. A bare `->debug()` would fatal whenever no logger was
	 * injected — on the path that ENFORCES the scoping rule, which is the
	 * worst place for a fatal. The helper sets the property to null
	 * explicitly, so removing the null-safe call turns this red.
	 *
	 * @return void
	 */
	public function testRefusalDoesNotRequireALogger(): void {
		$merged = $this->merge(['administrationId' => 'a'], ['administrationId' => 'b']);

		self::assertSame(['administrationId' => 'a'], $merged);

	}//end testRefusalDoesNotRequireALogger()
}//end class
