<?php

/**
 * OpenRegister ObjectVersionGuard
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;


use DateTimeInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\ConflictReportInterface;
use OCA\OpenRegister\Service\Object\RefusedWrite;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Refuses a write made from a stale read, and says what the other value is.
 *
 * A caller that read the object sends the `updated` it saw as `_expectedUpdated`
 * or `If-Match`. A write that names no version is not checked.
 *
 * @spec openspec/specs/objects-crud/spec.md#requirement-every-write-path-asserts-the-expected-version-req-cso-003
 */
final class ObjectVersionGuard {
	/**
	 * Hold the claim and the services the refusal needs.
	 *
	 * @param VersionClaim       $claim     The version this request claims.
	 * @param ContainerInterface $container Resolves the conflict report.
	 * @param ConflictTrail      $trail     The object's trail.
	 * @param LoggerInterface    $logger    The logger.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-every-write-path-asserts-the-expected-version-req-cso-003
	 */
	public function __construct(
		private readonly VersionClaim $claim,
		private readonly ContainerInterface $container,
		private readonly ConflictTrail $trail,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The 409 for a write that names a version other than the stored one.
	 *
	 * @param ObjectEntity $stored The object as stored.
	 * @param SentWrite    $write  What the caller is writing.
	 *
	 * @return JSONResponse|null The 409, or null when the caller may write.
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-every-write-path-asserts-the-expected-version-req-cso-003
	 */
	public function refusal(ObjectEntity $stored, SentWrite $write): ?JSONResponse {
		$expected = $this->claim->version();
		$current = (string)$stored->getUpdated()?->format(DateTimeInterface::ATOM);
		if ($expected === null || $current === $expected) {
			return null;
		}

		$mismatch = new VersionMismatch(stored: $stored, expected: $expected, current: $current);

		// The two timestamps stay on the body beside the report: every existing
		// client branches on them.
		$body = $this->conflictBody(mismatch: $mismatch, write: $write);
		$body['expectedUpdated'] = $expected;
		$body['currentUpdated'] = $current;

		$this->trail->recordRefusal(mismatch: $mismatch, body: $body);

		return new JSONResponse(data: $body, statusCode: 409);
	}//end refusal()

	/**
	 * The body of the 409: the full report when a schema allows one.
	 *
	 * @param VersionMismatch $mismatch The versions that disagree.
	 * @param SentWrite       $write    What the caller is writing.
	 *
	 * @return array<string, mixed> The body.
	 */
	private function conflictBody(VersionMismatch $mismatch, SentWrite $write): array {
		$plain = [
			'error' => ConflictReportInterface::ERROR,
			'code' => ConflictReportInterface::CODE,
			'message' => 'This object changed since you read it. Re-read it and try again.',
			'conflicts' => [],
			'changedBy' => null,
			'changedAt' => null,
		];
		if (($write->schema instanceof Schema) === false) {
			return $plain;
		}

		return ($this->report(mismatch: $mismatch, write: $write) ?? $plain);
	}//end conflictBody()

	/**
	 * The conflict report, or null when it could not be built.
	 *
	 * A report that could not be built must not turn a 409 into a 500: the
	 * refusal is still correct, it simply says less.
	 *
	 * @param VersionMismatch $mismatch The versions that disagree.
	 * @param SentWrite       $write    What the caller is writing.
	 *
	 * @return array<string, mixed>|null The report.
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-a-refused-write-names-the-values-that-conflict-req-cso-001
	 */
	private function report(VersionMismatch $mismatch, SentWrite $write): ?array {
		try {
			return $this->container->get(ConflictReportInterface::class)->build(
				write: new RefusedWrite(stored: $mismatch->stored, schema: $write->schema, sent: $write->data),
				intervening: $this->trail->interveningChanges(mismatch: $mismatch)
			);
		} catch (\Throwable $e) {
			$this->logger->warning(message: '[ObjectsController] a conflict body could not be built: ' . $e->getMessage(), context: []);
			return null;
		}
	}//end report()
}//end class
