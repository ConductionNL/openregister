<?php

/**
 * Reading, refusing and stripping the undecidable posture of an authorization block.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/an-app-declares-object-access-rather-than-guarding-it/specs/object-access-contract/spec.md#requirement-a-schema-declares-what-an-undecidable-access-question-means
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\InvalidAuthorizationRuleException;
use OCA\OpenRegister\Service\Rbac\UndecidablePosture;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\UndecidablePosture
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Schema\AuthorizationControlKeys
 * @uses   \OCA\OpenRegister\Db\Schema\AuthorizationEntry
 * @uses   \OCA\OpenRegister\Db\Schema\AuthorizationRulesValidator
 * @uses   \OCA\OpenRegister\Db\Schema\DeclaredActions
 * @uses   \OCA\OpenRegister\Db\JsonArrayColumn
 */
final class UndecidablePostureTest extends TestCase {

	/**
	 * Blocks and the posture they declare.
	 *
	 * @return array<string, array{0: array|null, 1: string}>
	 */
	public static function blocks(): array {
		return [
			'no block'          => [null, 'closed'],
			'an empty block'    => [[], 'closed'],
			'rules, no posture' => [['read' => ['g']], 'closed'],
			'closed'            => [['onUndecidable' => 'closed'], 'closed'],
			'open'              => [['onUndecidable' => 'open'], 'open'],
			'open in capitals'  => [['onUndecidable' => 'OPEN'], 'closed'],
			'true'              => [['onUndecidable' => true], 'closed'],
		];
	}//end blocks()

	/**
	 * Only the exact word `open` opens.
	 *
	 * @param array|null $authorization The block.
	 * @param string     $posture       The posture it declares.
	 *
	 * @return void
	 *
	 * @dataProvider blocks
	 */
	public function testOnlyTheWordOpenOpens(?array $authorization, string $posture): void {
		$this->assertSame($posture, (new UndecidablePosture())->of($authorization));
		$this->assertSame($posture === 'open', (new UndecidablePosture())->admits($authorization));
	}//end testOnlyTheWordOpenOpens()

	public function testOnlyClosedAndOpenCanBeSaved(): void {
		$posture = new UndecidablePosture();

		$this->assertNull($posture->refusalFor('closed'));
		$this->assertNull($posture->refusalFor('open'));
		$this->assertSame('must be "closed" or "open"', $posture->refusalFor('Open'));
		$this->assertSame('must be "closed" or "open"', $posture->refusalFor(null));
		$this->assertSame('must be "closed" or "open"', $posture->refusalFor(false));
	}//end testOnlyClosedAndOpenCanBeSaved()

	public function testThePostureIsNotARuleOfItsOwn(): void {
		$posture = new UndecidablePosture();

		$this->assertSame([], $posture->rulesOf(['onUndecidable' => 'open']));
		$this->assertSame(['read' => ['g']], $posture->rulesOf(['read' => ['g'], 'onUndecidable' => 'closed']));
		$this->assertNull($posture->rulesOf(null));
	}//end testThePostureIsNotARuleOfItsOwn()

	public function testASchemaSavesAKnownPostureAndRefusesAnyOther(): void {
		$schema = new Schema();
		$schema->setAuthorization(['read' => ['g'], 'onUndecidable' => 'open']);
		$this->assertTrue($schema->validateAuthorization());

		$schema->setAuthorization(['read' => ['g'], 'onUndecidable' => 'sometimes']);
		$this->expectException(InvalidAuthorizationRuleException::class);
		$this->expectExceptionMessage('Authorization key \'onUndecidable\' in schema must be "closed" or "open"');
		$schema->validateAuthorization();
	}//end testASchemaSavesAKnownPostureAndRefusesAnyOther()
}//end class
