<?php

/**
 * Failure-path tests for InheritedGrantLister.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Rbac\HierarchyDescender;
use OCA\OpenRegister\Service\Rbac\InheritedGrantLister;
use OCA\OpenRegister\Service\Rbac\ObjectShareReader;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An unresolvable hierarchy is a warning for the panel and a refusal for the complete
 * reading; an unreadable ancestor is skipped.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\InheritedGrantLister
 */
class InheritedGrantListerFailureTest extends TestCase {

	/**
	 * An object in register 3, schema 7.
	 *
	 * @return ObjectEntity
	 */
	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('child');
		$object->setRegister('3');
		$object->setSchema('7');
		return $object;
	}//end object()

	/**
	 * The panel reading lists nothing and logs; the complete reading refuses.
	 *
	 * @return void
	 */
	public function testUnresolvableHierarchy(): void {
		$hierarchy = $this->createMock(HierarchyDescender::class);
		$hierarchy->method('ancestorsOf')->with(new ObjectRef(3, 7, 'child'))->willThrowException(new RuntimeException('no table'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('[InheritedGrantLister] Could not resolve the ancestors of an object; its inherited grants are not listed');
		$lister = new InheritedGrantLister(
			hierarchy: $hierarchy,
			mapper: $this->createMock(MagicMapperInterface::class),
			shares: $this->createMock(ObjectShareReader::class),
			logger: $logger
		);

		$this->assertSame([], $lister->inheritedGrantsFor(object: $this->object()));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The ancestors of an object could not be resolved');
		$lister->inheritedSharesFor(object: $this->object(), complete: true);
	}//end testUnresolvableHierarchy()

	/**
	 * An ancestor that cannot be read is skipped and the next one is still asked.
	 *
	 * @return void
	 */
	public function testUnreadableAncestorIsSkipped(): void {
		$hierarchy = $this->createMock(HierarchyDescender::class);
		$hierarchy->method('ancestorsOf')->willReturn(['gone', 'parent']);
		$parent = new ObjectEntity();
		$parent->setUuid('parent');
		$parent->setOwner('alice');
		$mapper = $this->createMock(MagicMapperInterface::class);
		$mapper->method('find')->willReturnCallback(
			static fn (string|int $identifier): ObjectEntity => $identifier === 'parent' ? $parent : throw new RuntimeException('gone')
		);
		$shares = $this->createMock(ObjectShareReader::class);
		$shares->expects($this->once())->method('completeSharesOn')->with($parent, ['alice'])->willReturn([]);

		$lister = new InheritedGrantLister(hierarchy: $hierarchy, mapper: $mapper, shares: $shares, logger: $this->createMock(LoggerInterface::class));

		$this->assertSame([], $lister->inheritedSharesFor(object: $this->object(), complete: true));
	}//end testUnreadableAncestorIsSkipped()
}//end class
