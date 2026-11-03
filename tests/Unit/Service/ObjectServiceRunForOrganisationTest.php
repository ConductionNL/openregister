<?php

/**
 * Unit tests for ObjectService::runForOrganisation().
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
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\AnonymousEvaluationContext;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * runForOrganisation() refuses an unknown organisation, then runs the callable
 * as an anonymous caller acting for the organisation.
 *
 * @covers \OCA\OpenRegister\Service\ObjectService
 * @uses   \OCA\OpenRegister\Service\OrganisationContext
 * @uses   \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses   \OCA\OpenRegister\Service\SystemOperationContext
 * @uses   \OCA\OpenRegister\Db\Organisation
 */
class ObjectServiceRunForOrganisationTest extends TestCase {

	private ObjectService $service;

	private ?IUser $current = null;

	/**
	 * The organisations that exist, by uuid.
	 *
	 * @var string[]
	 */
	private array $known = ['org-a', 'org-b'];

	protected function setUp(): void {
		parent::setUp();

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->current);
		$session->method('setVolatileActiveUser')->willReturnCallback(
			function (?IUser $user): void {
				$this->current = $user;
			}
		);

		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('findContextOrganisation')->willReturnCallback(
			fn (string $uuid): ?Organisation => in_array($uuid, $this->known, true) ? new Organisation() : null
		);

		$reflection = new ReflectionClass(ObjectService::class);
		$this->service = $reflection->newInstanceWithoutConstructor();
		foreach (['userSession' => $session, 'organisationService' => $organisations] as $name => $value) {
			$property = $reflection->getProperty($name);
			$property->setAccessible(true);
			$property->setValue($this->service, $value);
		}
	}//end setUp()

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	public function testTheCallableActsForTheOrganisationAsAnAnonymousCaller(): void {
		$this->current = $this->user('admin');

		$seen = $this->service->runForOrganisation(
			'org-a',
			fn (): array => [
				OrganisationContext::current(),
				AnonymousEvaluationContext::isActive(),
				$this->current,
			]
		);

		$this->assertSame(['org-a', true, null], $seen);
		$this->assertFalse(OrganisationContext::isActive());
		$this->assertFalse(AnonymousEvaluationContext::isActive());
		$this->assertSame('admin', $this->current?->getUID(), 'the session user is back afterwards');
	}//end testTheCallableActsForTheOrganisationAsAnAnonymousCaller()

	public function testTheSystemScopeYieldsInsideTheContext(): void {
		$inside = SystemOperationContext::run(
			fn (): bool => $this->service->runForOrganisation('org-a', static fn (): bool => SystemOperationContext::isActive())
		);

		$this->assertFalse($inside);
	}//end testTheSystemScopeYieldsInsideTheContext()

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function unknownOrganisations(): array {
		return ['unknown' => ['org-x'], 'empty' => ['']];
	}//end unknownOrganisations()

	/**
	 * @dataProvider unknownOrganisations
	 */
	public function testAnUnknownOrganisationIsRefusedBeforeTheCallableRuns(string $uuid): void {
		$ran = false;
		try {
			$this->service->runForOrganisation(
				$uuid,
				static function () use (&$ran): void {
					$ran = true;
				}
			);
			$this->fail('Expected the organisation to be refused.');
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString('"' . $uuid . '"', $e->getMessage());
		}

		$this->assertFalse($ran);
		$this->assertFalse(OrganisationContext::isActive());
	}//end testAnUnknownOrganisationIsRefusedBeforeTheCallableRuns()

	public function testAThrowRestoresTheContextAndTheUser(): void {
		$this->current = $this->user('bob');

		try {
			$this->service->runForOrganisation(
				'org-a',
				static function (): void {
					throw new RuntimeException('write failed');
				}
			);
			$this->fail('Expected the exception to propagate.');
		} catch (RuntimeException $e) {
			$this->assertSame('write failed', $e->getMessage());
		}

		$this->assertFalse(OrganisationContext::isActive());
		$this->assertSame('bob', $this->current?->getUID());
	}//end testAThrowRestoresTheContextAndTheUser()

	public function testNestedContextsApplyTheInnermostOrganisation(): void {
		$seen = $this->service->runForOrganisation(
			'org-a',
			fn (): array => [
				$this->service->runForOrganisation('org-b', static fn (): ?string => OrganisationContext::current()),
				OrganisationContext::current(),
			]
		);

		$this->assertSame(['org-b', 'org-a'], $seen);
	}//end testNestedContextsApplyTheInnermostOrganisation()
}//end class
