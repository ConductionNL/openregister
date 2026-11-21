<?php

/**
 * Unit test for the e-Depot transfer system register (lib/Settings/edepot_transfer_register.json).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The durable transfer list and the write-once proof of transfer, as the
 * register declares them.
 *
 * The proof refers to the object it attests by a plain uuid string. Cascading
 * deletes follow only properties that declare an `onDelete` relation
 * (ReferentialIntegrityService indexes nothing else), so destroying the
 * source object can never take its proof with it.
 *
 * @coversNothing
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
 */
class EdepotTransferRegisterJsonTest extends TestCase {

	/** @var array<string, mixed> */
	private static array $register = [];

	public static function setUpBeforeClass(): void {
		$decoded = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/edepot_transfer_register.json'),
			true
		);
		self::$register = is_array($decoded) === true ? $decoded : [];
	}//end setUpBeforeClass()

	/**
	 * @return array<string, mixed>
	 */
	private static function schema(string $slug): array {
		return self::$register['components']['schemas'][$slug] ?? [];
	}//end schema()

	public function testTheRegisterIsACoreRegisterHoldingBothSchemas(): void {
		$this->assertSame('core', self::$register['x-openregister']['type'] ?? null);
		$this->assertSame(
			['edepotTransfer', 'edepotTransferProof'],
			self::$register['components']['registers']['edepot-transfers']['schemas'] ?? null
		);
	}//end testTheRegisterIsACoreRegisterHoldingBothSchemas()

	public function testATransferListCarriesItsWholeLifecycle(): void {
		$transfer = self::schema('edepotTransfer');

		$this->assertSame(['status'], $transfer['required'] ?? null);
		foreach (['status', 'objectReferences', 'exclusions', 'approvalMetadata', 'attempts', 'transferResult', 'packageFormat', 'createdAt'] as $field) {
			$this->assertArrayHasKey($field, $transfer['properties'], "edepotTransfer lacks {$field}");
		}
	}//end testATransferListCarriesItsWholeLifecycle()

	public function testEveryDeliveryAttemptIsAnEntryInAList(): void {
		$attempts = self::schema('edepotTransfer')['properties']['attempts'];

		$this->assertSame('array', $attempts['type']);
		$this->assertSame(
			['attempt', 'timestamp', 'transport', 'outcome', 'error'],
			array_keys($attempts['items']['properties'])
		);
		$this->assertSame('integer', $attempts['items']['properties']['attempt']['type']);
	}//end testEveryDeliveryAttemptIsAnEntryInAList()

	public function testAProofIsImmutableAndNamesWhatItAttests(): void {
		$proof = self::schema('edepotTransferProof');

		$this->assertTrue($proof['immutable'] ?? false);
		$this->assertSame(['objectUuid', 'transferUuid', 'confirmedAt'], $proof['required'] ?? null);
		foreach (['eDepotReference', 'transportReceipt', 'packageId', 'packageFormat', 'packageManifestSha256', 'fileChecksums', 'transportName'] as $field) {
			$this->assertArrayHasKey($field, $proof['properties'], "edepotTransferProof lacks {$field}");
		}

		$this->assertSame(['name', 'sha256'], array_keys($proof['properties']['fileChecksums']['items']['properties']));
	}//end testAProofIsImmutableAndNamesWhatItAttests()

	public function testAProofHasNoRelationADestructionCouldCascadeAlong(): void {
		$proof = self::schema('edepotTransferProof');

		$this->assertSame('string', $proof['properties']['objectUuid']['type']);
		$this->assertSame([], self::relationKeys(node: $proof['properties']));
	}//end testAProofHasNoRelationADestructionCouldCascadeAlong()

	/**
	 * Every `onDelete`, `$ref` or `inversedBy` key anywhere below a node, as dotted paths.
	 *
	 * @param array<mixed> $node The node to search.
	 * @param string $path The path to the node.
	 *
	 * @return list<string> The paths.
	 */
	private static function relationKeys(array $node, string $path=''): array {
		$found = [];
		foreach ($node as $key => $value) {
			$here = ltrim($path . '.' . $key, '.');
			if (in_array($key, ['onDelete', '$ref', 'inversedBy'], true) === true) {
				$found[] = $here;
			}

			if (is_array($value) === true) {
				$found = array_merge($found, self::relationKeys(node: $value, path: $here));
			}
		}

		return $found;
	}//end relationKeys()
}//end class
