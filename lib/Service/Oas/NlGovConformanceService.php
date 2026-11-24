<?php

/**
 * OpenRegister NLGov conformance service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Oas
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

namespace OCA\OpenRegister\Service\Oas;

use OCA\OpenRegister\Service\OasServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * The conformance report of a register's API, and the response probe that completes it.
 *
 * The report is read off one generation of the public document: it says
 * nothing the document does not, and the response rules carry the last probe
 * an administrator ran.
 *
 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
 */
class NlGovConformanceService {

	/**
	 * Constructor.
	 *
	 * @param OasServiceInterface $oasService The document generator.
	 * @param NlGovResponseProbe  $probe      The response probe and its kept results.
	 * @param ITimeFactory        $time       The clock the report is stamped with.
	 *
	 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
	 */
	public function __construct(
		private readonly OasServiceInterface $oasService,
		private readonly NlGovResponseProbe $probe,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * The report of one register, or of every register's combined document when none is named.
	 *
	 * @param string|null $registerId The register, or null for all.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When the register does not exist.
	 *
	 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
	 */
	public function report(?string $registerId = null): array {
		$this->oasService->createOas($registerId);
		$probe = null;
		if ($registerId !== null) {
			$probe = $this->probe->lastResult(registerId: $registerId);
		}

		return $this->build(probe: $probe);
	}//end report()

	/**
	 * Probe a register's responses, keep the result, and return the report that includes it.
	 *
	 * @param string $registerId The register.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When the register does not exist.
	 *
	 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
	 */
	public function probe(string $registerId): array {
		$oas   = $this->oasService->createOas($registerId);
		$probe = $this->probe->probe(registerId: $registerId, oas: $oas);

		return $this->build(probe: $probe);
	}//end probe()

	/**
	 * The report of the generation that just ran.
	 *
	 * @param array<string, mixed>|null $probe The probe to include.
	 *
	 * @return array<string, mixed>
	 */
	private function build(?array $probe): array {
		return (new NlGovConformanceReport())->build(
			report: $this->oasService->getLastValidationReport(),
			probe: $probe,
			generatedAt: $this->time->getDateTime('now', new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM)
		);
	}//end build()
}//end class
