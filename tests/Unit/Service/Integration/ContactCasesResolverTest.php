<?php

/**
 * Resolving a contact's links, and what happens when one cannot be read.
 *
 * 🔴 AN EMPTY READ AND A MISSING OBJECT LOOK THE SAME FROM HERE, and both are
 * counted rather than dropped. A panel that silently shortens its own list
 * tells the reader something false — that a contact is involved in two cases
 * when they are involved in five — and there is nothing on screen to correct
 * it.
 *
 * 🔴 A READ THAT THREW IS NOT A LINK THAT DOES NOT EXIST. It is logged where
 * an administrator can act on it and counted where the reader can see that
 * something is missing.
 *
 * 🔴 ONE READ PER LINK MEANS AN UNBOUNDED LIST IS AN UNBOUNDED NUMBER OF
 * READS. The bound is asserted by counting how many times the resolver was
 * called, not by trusting the constant.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/contacts-leaf-cases-panel/specs/integration-contacts/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Integration\ContactCasesPanel;
use OCA\OpenRegister\Service\Integration\ContactCasesResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The resolution behind the cases panel.
 *
 * @spec openspec/changes/contacts-leaf-cases-panel/specs/integration-contacts/spec.md
 */
class ContactCasesResolverTest extends TestCase {

	private ContactCasesResolver $resolver;

	protected function setUp(): void {
		parent::setUp();
		$this->resolver = new ContactCasesResolver(
			new ContactCasesPanel(),
			$this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * One link.
	 *
	 * @param string $uuid   The object it points at.
	 * @param string $schema The schema it points at.
	 *
	 * @return array<string,mixed> The link.
	 */
	private function link(string $uuid, string $schema = 'case'): array {
		return ['objectUuid' => $uuid, 'register' => 'dossiq', 'schema' => $schema, 'role' => 'gemachtigde'];
	}//end link()

	public function testAReadableLinkBecomesARowWithItsTitleAndStatus(): void {
		$panel = $this->resolver->panelFor(
			[$this->link('obj-1')],
			static fn (string $r, string $s, string $u): array => ['title' => 'Zaak A', 'status' => 'open', 'url' => '/x']
		);

		$row = $panel['groups'][0]['rows'][0];
		$this->assertSame('Zaak A', $row['title']);
		$this->assertSame('open', $row['status']);
		$this->assertSame('gemachtigde', $row['role']);
		$this->assertSame(0, $panel['unreadable']);
	}//end testAReadableLinkBecomesARowWithItsTitleAndStatus()

	public function testAnObjectThatReadsBackEmptyIsCountedNotDropped(): void {
		$panel = $this->resolver->panelFor(
			[$this->link('obj-1'), $this->link('gone')],
			static fn (string $r, string $s, string $u): ?array => ($u === 'obj-1' ? ['title' => 'Zaak A'] : null)
		);

		$this->assertSame(2, $panel['total'], 'the reader is told there are two links');
		$this->assertSame(1, $panel['unreadable']);
		$this->assertSame(1, $panel['groups'][0]['count']);
	}//end testAnObjectThatReadsBackEmptyIsCountedNotDropped()

	public function testAReadThatThrewIsCountedAndLoggedRatherThanFatal(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$resolver = new ContactCasesResolver(new ContactCasesPanel(), $logger);

		$panel = $resolver->panelFor(
			[$this->link('boom')],
			static function (string $r, string $s, string $u): array {
				throw new RuntimeException('storage is down');
			}
		);

		$this->assertSame(1, $panel['unreadable']);
		$this->assertSame(1, $panel['total']);
	}//end testAReadThatThrewIsCountedAndLoggedRatherThanFatal()

	public function testTheNumberOfReadsIsBoundedAndTheCutIsDeclared(): void {
		$links = [];
		for ($i = 0; $i < 150; $i++) {
			$links[] = $this->link('obj-' . $i);
		}

		$reads = 0;
		$panel = $this->resolver->panelFor(
			$links,
			static function (string $r, string $s, string $u) use (&$reads): array {
				$reads++;

				return ['title' => 'Zaak ' . $u];
			}
		);

		// Counted, not trusted: one read per link means an unbounded list is
		// an unbounded number of reads to render a sidebar.
		$this->assertSame(ContactCasesResolver::MAX_LINKS, $reads);
		$this->assertTrue($panel['truncated']);
	}//end testTheNumberOfReadsIsBoundedAndTheCutIsDeclared()

	public function testAShortListIsNotDeclaredTruncated(): void {
		$panel = $this->resolver->panelFor(
			[$this->link('obj-1')],
			static fn (string $r, string $s, string $u): array => ['title' => 'Zaak A']
		);

		$this->assertFalse($panel['truncated']);
	}//end testAShortListIsNotDeclaredTruncated()

	public function testAnObjectWithNoTitleFallsBackToItsIdRatherThanABlankRow(): void {
		$panel = $this->resolver->panelFor(
			[$this->link('obj-7')],
			static fn (string $r, string $s, string $u): array => ['status' => 'open']
		);

		$this->assertSame('obj-7', $panel['groups'][0]['rows'][0]['title']);
	}//end testAnObjectWithNoTitleFallsBackToItsIdRatherThanABlankRow()

	public function testALinkWithNoObjectIsNeverRead(): void {
		$reads = 0;
		$panel = $this->resolver->panelFor(
			[['register' => 'dossiq', 'schema' => 'case']],
			static function (string $r, string $s, string $u) use (&$reads): array {
				$reads++;

				return [];
			}
		);

		$this->assertSame(0, $reads, 'a link naming no object has nothing to read');
		$this->assertSame(1, $panel['unreadable']);
	}//end testALinkWithNoObjectIsNeverRead()
}//end class
