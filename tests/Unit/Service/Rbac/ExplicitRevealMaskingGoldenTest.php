<?php

/**
 * Characterisation tests for explicit-reveal masking, the oracle-query refusal
 * and the share-link protection.
 *
 * Pins `ExplicitReveal::maskAll()` (missing, null and empty values left alone,
 * set values masked, other properties untouched), `refuseOracleQuery()`
 * (refused AND allowed: a filter or a sort on an explicit property is refused
 * with the exact message naming the first such property in schema order;
 * other properties, no explicit property, and trusted code pass),
 * `ExplicitRevealChangeMask::maskChange()` (diffs with one or both sides,
 * plain values, an array that only looks like a diff) and
 * `LinkProtection::applyTo()` (password and expiry given, blank or absent,
 * an unreadable expiry refused).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ExplicitRevealQueryException;
use OCA\OpenRegister\Service\Rbac\ExplicitReveal;
use OCA\OpenRegister\Service\Rbac\ExplicitRevealChangeMask;
use OCA\OpenRegister\Service\Rbac\LinkProtection;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests for masking, the oracle refusal and link protection.
 */
class ExplicitRevealMaskingGoldenTest extends TestCase {

	/**
	 * A schema with two explicit-reveal properties and one plain one.
	 *
	 * @return Schema
	 */
	private function schema(): Schema {
		$explicit = ['type' => 'string', 'authorization' => ['read' => [['group' => 'g']], 'reveal' => 'explicit']];
		$schema   = new Schema();
		$schema->setProperties(['bsn' => $explicit, 'name' => ['type' => 'string'], 'iban' => $explicit]);

		return $schema;
	}//end schema()

	/**
	 * Only set explicit-reveal values are masked.
	 *
	 * @return void
	 */
	public function testMaskAllMasksOnlySetExplicitValues(): void {
		$this->assertSame(
			['bsn' => '*****6789', 'name' => '123456789', 'iban' => null],
			ExplicitReveal::maskAll($this->schema(), ['bsn' => '123456789', 'name' => '123456789', 'iban' => null])
		);
		$this->assertSame(['bsn' => '', 'other' => 'x'], ExplicitReveal::maskAll($this->schema(), ['bsn' => '', 'other' => 'x']));
		$this->assertSame(['iban' => '*****'], ExplicitReveal::maskAll($this->schema(), ['iban' => 0]));
		$this->assertSame(['bsn' => '123456789'], ExplicitReveal::maskAll(null, ['bsn' => '123456789']));
	}//end testMaskAllMasksOnlySetExplicitValues()

	/**
	 * Run the refusal and return its message, or null when the query passes.
	 *
	 * @param ExplicitReveal $reveal The reveal state.
	 * @param array<mixed>   $query  The query.
	 * @param Schema|null    $schema The schema.
	 *
	 * @return string|null
	 */
	private function refusal(ExplicitReveal $reveal, array $query, ?Schema $schema): ?string {
		try {
			$reveal->refuseOracleQuery(query: $query, schema: $schema);
		} catch (ExplicitRevealQueryException $e) {
			return $e->getMessage();
		}

		return null;
	}//end refusal()

	/**
	 * A filter or sort on an explicit property is refused naming the first one in schema order.
	 *
	 * @return void
	 */
	public function testOracleQueriesAreRefusedOrAllowed(): void {
		$reveal = new ExplicitReveal();
		$schema = $this->schema();

		$this->assertSame("'bsn' is shown only on request, so it cannot be filtered or sorted on.", $this->refusal($reveal, ['iban' => 'x', 'bsn' => 'y'], $schema));
		$this->assertSame("'bsn' is shown only on request, so it cannot be filtered or sorted on.", $this->refusal($reveal, ['iban' => 'x', '_order' => ['bsn' => 'asc']], $schema));
		$this->assertSame("'iban' is shown only on request, so it cannot be filtered or sorted on.", $this->refusal($reveal, ['_order' => ['iban' => 'desc']], $schema));
		$this->assertNull($this->refusal($reveal, ['name' => 'x', '_order' => ['name' => 'asc']], $schema));
		$this->assertNull($this->refusal($reveal, ['bsn' => 'x'], null));
		$this->assertNull($reveal->trustedDuring(fn () => $this->refusal($reveal, ['bsn' => 'x'], $schema)));
	}//end testOracleQueriesAreRefusedOrAllowed()

	/**
	 * Every change shape is masked as before.
	 *
	 * @return void
	 */
	public function testMaskChangeForEveryShape(): void {
		$this->assertSame(['old' => '*****6789', 'new' => '*****4321'], ExplicitRevealChangeMask::maskChange(['old' => '123456789', 'new' => '987654321']));
		$this->assertSame(['old' => null, 'new' => '*****6789'], ExplicitRevealChangeMask::maskChange(['old' => null, 'new' => '123456789']));
		$this->assertSame(['new' => '*****6789', 'x' => 'kept'], ExplicitRevealChangeMask::maskChange(['new' => '123456789', 'x' => 'kept']));
		$this->assertSame(['old' => '*****'], ExplicitRevealChangeMask::maskChange(['old' => ['a']]));
		$this->assertSame('*****', ExplicitRevealChangeMask::maskChange(['a' => '123456789']));
		$this->assertSame('*****6789', ExplicitRevealChangeMask::maskChange('123456789'));
		$this->assertNull(ExplicitRevealChangeMask::maskChange(null));
	}//end testMaskChangeForEveryShape()

	/**
	 * Password and expiry are applied only when given; an unreadable expiry is refused.
	 *
	 * @return void
	 */
	public function testLinkProtectionApplication(): void {
		$share = $this->createMock(IShare::class);
		$share->expects($this->once())->method('setPassword')->with('s3cret');
		$share->expects($this->once())->method('setExpirationDate')->with($this->callback(
			static fn (DateTime $date): bool => $date->format('Y-m-d') === '2030-01-02'
		));
		(new LinkProtection(password: 's3cret', expiration: '2030-01-02'))->applyTo($share);

		$untouched = $this->createMock(IShare::class);
		$untouched->expects($this->never())->method('setPassword');
		$untouched->expects($this->never())->method('setExpirationDate');
		(new LinkProtection(password: '  ', expiration: ' '))->applyTo($untouched);
		(new LinkProtection(password: null, expiration: null))->applyTo($untouched);

		$refused = $this->createMock(IShare::class);
		$refused->expects($this->once())->method('setPassword')->with(' pw ');
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Could not read that expiry date');
		(new LinkProtection(password: ' pw ', expiration: 'not a date at all'))->applyTo($refused);
	}//end testLinkProtectionApplication()
}//end class
