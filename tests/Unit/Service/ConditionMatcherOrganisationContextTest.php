<?php

/**
 * Unit tests for the `$organisation` token inside an organisation context.
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

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\ConditionUserResolvers;
use OCA\OpenRegister\Service\OperatorEvaluator;
use OCA\OpenRegister\Service\OrganisationContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The organisation memo is keyed by the context, so `$organisation` inside a
 * context is the context's organisation even after an anonymous miss.
 *
 * @covers \OCA\OpenRegister\Service\ConditionMatcher
 * @uses   \OCA\OpenRegister\Service\OrganisationContext
 * @uses   \OCA\OpenRegister\Db\Organisation
 * @uses \OCA\OpenRegister\Service\Rbac\RecordMetadataConditions
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 */
class ConditionMatcherOrganisationContextTest extends TestCase {

	public function testTheTokenFollowsTheContextAndForgetsItAfterwards(): void {
		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('getActiveOrganisation')->willReturnCallback(
			static function (): ?Organisation {
				$uuid = OrganisationContext::current();
				if ($uuid === null) {
					return null;
				}

				$organisation = new Organisation();
				$organisation->setUuid($uuid);
				return $organisation;
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($organisations);

		$matcher = new ConditionMatcher(
			new ConditionUserResolvers($this->createMock(IUserSession::class)),
			$container,
			$this->createMock(OperatorEvaluator::class),
			$this->createMock(LoggerInterface::class)
		);

		$seen = [$matcher->resolveDynamicValue(value: '$organisation')];
		$seen[] = OrganisationContext::run('org-a', static fn (): mixed => $matcher->resolveDynamicValue(value: '$organisation'));
		$seen[] = OrganisationContext::run('org-b', static fn (): mixed => $matcher->resolveDynamicValue(value: '$activeOrganisation.uuid'));
		$seen[] = OrganisationContext::run('org-a', static fn (): mixed => $matcher->resolveDynamicValue(value: ['$eq' => '$organisation']));
		$seen[] = $matcher->resolveDynamicValue(value: '$organisation');

		$this->assertSame([null, 'org-a', 'org-b', ['$eq' => 'org-a'], null], $seen);
	}//end testTheTokenFollowsTheContextAndForgetsItAfterwards()

	public function testUserTokensDenyInsideAContext(): void {
		$matcher = new ConditionMatcher(
			new ConditionUserResolvers($this->createMock(IUserSession::class)),
			$this->createMock(ContainerInterface::class),
			$this->createMock(OperatorEvaluator::class),
			$this->createMock(LoggerInterface::class)
		);

		$seen = OrganisationContext::run(
			'org-a',
			static fn (): array => [$matcher->resolveDynamicValue(value: '$userId'), $matcher->resolveDynamicValue(value: '$user.uid')]
		);

		$this->assertSame([null, null], $seen);
	}//end testUserTokensDenyInsideAContext()
}//end class
