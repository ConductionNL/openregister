<?php

/**
 * Unit tests for OrganisationContext.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Service\OrganisationContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The organisation context is a stack: innermost wins, restored on every exit.
 *
 * @covers \OCA\OpenRegister\Service\OrganisationContext
 */
class OrganisationContextTest extends TestCase {

	public function testNoContextIsActiveOutsideRun(): void {
		$this->assertFalse(OrganisationContext::isActive());
		$this->assertNull(OrganisationContext::current());
	}//end testNoContextIsActiveOutsideRun()

	public function testTheContextNamesItsOrganisationInsideRun(): void {
		$seen = OrganisationContext::run('org-a', static fn (): array => [OrganisationContext::isActive(), OrganisationContext::current()]);

		$this->assertSame([true, 'org-a'], $seen);
		$this->assertFalse(OrganisationContext::isActive());
	}//end testTheContextNamesItsOrganisationInsideRun()

	public function testTheInnermostContextWinsAndTheOuterComesBack(): void {
		$seen = [];
		OrganisationContext::run(
			'org-a',
			static function () use (&$seen): void {
				OrganisationContext::run(
					'org-b',
					static function () use (&$seen): void {
						$seen[] = OrganisationContext::current();
					}
				);
				$seen[] = OrganisationContext::current();
			}
		);

		$this->assertSame(['org-b', 'org-a'], $seen);
		$this->assertNull(OrganisationContext::current());
	}//end testTheInnermostContextWinsAndTheOuterComesBack()

	public function testAThrowRestoresTheOuterContext(): void {
		$after = 'unset';
		OrganisationContext::run(
			'org-a',
			static function () use (&$after): void {
				try {
					OrganisationContext::run(
						'org-b',
						static function (): void {
							throw new RuntimeException('read failed');
						}
					);
				} catch (RuntimeException $e) {
					$after = OrganisationContext::current();
				}
			}
		);

		$this->assertSame('org-a', $after);
		$this->assertFalse(OrganisationContext::isActive());
	}//end testAThrowRestoresTheOuterContext()

	public function testAThrowOutOfTheOuterContextLeavesNoContext(): void {
		try {
			OrganisationContext::run(
				'org-a',
				static function (): void {
					throw new RuntimeException('read failed');
				}
			);
			$this->fail('Expected the exception to propagate.');
		} catch (RuntimeException $e) {
			$this->assertSame('read failed', $e->getMessage());
		}

		$this->assertFalse(OrganisationContext::isActive());
	}//end testAThrowOutOfTheOuterContextLeavesNoContext()

	public function testMultitenancyCannotBeSwitchedOffInsideAContext(): void {
		$this->assertSame([false, true], [OrganisationContext::multitenancy(false), OrganisationContext::multitenancy(true)]);
		$this->assertSame(
			[true, true],
			OrganisationContext::run('org-a', static fn (): array => [OrganisationContext::multitenancy(false), OrganisationContext::multitenancy(true)])
		);
	}//end testMultitenancyCannotBeSwitchedOffInsideAContext()

	public function testTheReturnValueIsPassedThrough(): void {
		$this->assertSame('answer', OrganisationContext::run('org-a', static fn (): string => 'answer'));
	}//end testTheReturnValueIsPassedThrough()

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function emptyUuids(): array {
		return ['empty' => [''], 'whitespace' => ['  ']];
	}//end emptyUuids()

	/**
	 * @dataProvider emptyUuids
	 */
	public function testAnEmptyOrganisationIsRefusedBeforeTheCallableRuns(string $uuid): void {
		$ran = false;
		try {
			OrganisationContext::run(
				$uuid,
				static function () use (&$ran): void {
					$ran = true;
				}
			);
			$this->fail('Expected the empty uuid to be refused.');
		} catch (InvalidArgumentException $e) {
			$this->assertFalse($ran);
		}

		$this->assertFalse(OrganisationContext::isActive());
	}//end testAnEmptyOrganisationIsRefusedBeforeTheCallableRuns()
}//end class
