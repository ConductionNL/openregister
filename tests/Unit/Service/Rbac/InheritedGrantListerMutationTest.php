<?php

/**
 * Mutation-killing tests for the lister of grants an object inherits from its ancestors.
 *
 * Written against the mutants Infection left alive in InheritedGrantLister:
 * every share of every ancestor is listed (not only the first), the
 * ancestor is looked up system-wide (it is the grant source, not something
 * the caller reads), an unresolvable hierarchy fails loudly on a complete
 * read and is logged on a best-effort one.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Rbac\HierarchyDescender;
use OCA\OpenRegister\Service\Rbac\InheritedGrantLister;
use OCA\OpenRegister\Service\Rbac\ObjectShareReader;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class InheritedGrantListerMutationTest extends TestCase {
	/**
	 * Test double or fixture for hierarchy.
	 *
	 * @var HierarchyDescender&MockObject
	 */
	private HierarchyDescender&MockObject $hierarchy;

	/**
	 * Test double or fixture for mapper.
	 *
	 * @var MagicMapperInterface&MockObject
	 */
	private MagicMapperInterface&MockObject $mapper;

	/**
	 * Test double or fixture for shares.
	 *
	 * @var ObjectShareReader&MockObject
	 */
	private ObjectShareReader&MockObject $shares;

	/**
	 * Test double or fixture for logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->hierarchy = $this->createMock(HierarchyDescender::class);
		$this->mapper    = $this->createMock(MagicMapperInterface::class);
		$this->shares    = $this->createMock(ObjectShareReader::class);
		$this->logger    = $this->createMock(LoggerInterface::class);
	}//end setUp()

	private function lister(): InheritedGrantLister {
		return new InheritedGrantLister($this->hierarchy, $this->mapper, $this->shares, $this->logger);
	}//end lister()

	private function object(string $uuid, ?string $owner=null): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('3');
		$object->setSchema('8');
		$object->setOwner($owner);

		return $object;
	}//end object()

	private function share(string $id, string $with): IShare {
		$share = $this->createMock(IShare::class);
		$share->method('getFullId')->willReturn($id);
		$share->method('getSharedWith')->willReturn($with);
		$share->method('getPermissions')->willReturn(1);
		$share->method('getExpirationDate')->willReturn(null);

		return $share;
	}//end share()

	public function testEveryShareOfEveryAncestorIsListedAndLookedUpSystemWide(): void {
		$dossier = $this->object('dossier-1', 'eigenaar-a');
		$zaak    = $this->object('zaak-1', 'eigenaar-b');
		$this->hierarchy->method('ancestorsOf')->with(new ObjectRef(3, 8, 'document-1'))->willReturn(['dossier-1', 'zaak-1']);
		$this->mapper->expects($this->exactly(2))
			->method('find')
			->willReturnCallback(
				function (string $identifier, $register, $schema, bool $deleted, bool $rbac, bool $tenancy) use ($dossier, $zaak): ObjectEntity {
					$this->assertFalse($rbac, 'the ancestor is the grant source, not a caller read');
					$this->assertFalse($tenancy, 'the ancestor is the grant source, not a caller read');
					return match ($identifier) {
						'dossier-1' => $dossier,
						default => $zaak,
					};
				}
			);
		$this->shares->method('sharesOn')->willReturnCallback(
			fn (ObjectEntity $on, array $sharers): array => match ($on->getUuid()) {
				'dossier-1' => [
					['type' => 'user', 'share' => $this->share('s1', 'jan')],
					['type' => 'group', 'share' => $this->share('s2', 'redactie')],
				],
				'zaak-1' => match ($sharers) {
					['eigenaar-b'] => [['type' => 'user', 'share' => $this->share('s3', 'piet')]],
					default => [],
				},
			}
		);

		$grants = $this->lister()->inheritedGrantsFor($this->object('document-1'));

		$this->assertSame(
			[['s1', 'jan', 'dossier-1'], ['s2', 'redactie', 'dossier-1'], ['s3', 'piet', 'zaak-1']],
			array_map(static fn (array $g): array => [$g['id'], $g['sharedWith'], $g['inheritedFrom']], $grants)
		);
		$this->assertSame([true, true, true], array_column($grants, 'inherited'));
	}//end testEveryShareOfEveryAncestorIsListedAndLookedUpSystemWide()

	public function testAnUnresolvableHierarchyFailsLoudlyOnACompleteRead(): void {
		$cause = new RuntimeException('hierarchy broken');
		$this->hierarchy->method('ancestorsOf')->willThrowException($cause);
		$this->logger->expects($this->never())->method('warning');

		try {
			$this->lister()->inheritedSharesFor($this->object('document-1'), true);
			$this->fail('A complete read swallowed an unresolvable hierarchy.');
		} catch (RuntimeException $e) {
			$this->assertSame(0, $e->getCode());
			$this->assertSame($cause, $e->getPrevious());
		}
	}//end testAnUnresolvableHierarchyFailsLoudlyOnACompleteRead()

	public function testAnUnresolvableHierarchyIsLoggedOnABestEffortRead(): void {
		$this->hierarchy->method('ancestorsOf')->willThrowException(new RuntimeException('hierarchy broken'));
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('Could not resolve the ancestors'),
				$this->callback(
					static fn (array $context): bool => $context['object'] === 'document-1'
						&& $context['exception'] === 'hierarchy broken'
						&& str_ends_with($context['file'], 'InheritedGrantLister.php')
				)
			);

		$this->assertSame([], $this->lister()->inheritedGrantsFor($this->object('document-1')));
	}//end testAnUnresolvableHierarchyIsLoggedOnABestEffortRead()
}//end class
