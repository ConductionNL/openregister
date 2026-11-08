<?php

/**
 * OpenRegister Gdpr ExportBundleService
 *
 * Assembles, signs and hands out the data-subject export bundle for a case, and
 * assembles the regulator dossier. It reuses
 * {@see DataSubjectRequestService::assembleAccessExport} for subject-data
 * discovery/assembly (never re-implemented, ADR-011), renders the assembled data
 * as a PDF disclosure document, and signs it through the injected
 * {@see PadesSigner} seam (default {@see UnsignedPadesSigner} — SHA-256 hash,
 * `signed:false`). The bundle-generation action is audited on the case (pinned
 * to the DSAR processing activity via {@see CaseObjectAccessor}), and a
 * single-use, time-boxed, case-scoped download token is minted for one-time
 * secure retrieval.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Export
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr\Export;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Gdpr\Case\CaseObjectAccessor;
use OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Assembles + signs export bundles and regulator dossiers for a case.

 *
 * @spec openspec/specs/dsar-export-bundle/spec.md
 */
class ExportBundleService implements ExportBundleServiceInterface {
	/**
	 * Constructor.
	 *
	 * @param DataSubjectRequestService $dsrService Reused access-export assembler (ADR-011).
	 * @param CaseObjectAccessor $accessor RBAC-scoped, audited case load/save.
	 * @param PadesSigner $signer Swappable signing seam.
	 * @param OneTimeDownloadTokenStore $tokenStore Single-use download-token store.
	 * @param AuditTrailMapperInterface $auditTrailMapper Immutable trail reader for the dossier history.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly DataSubjectRequestService $dsrService,
		private readonly CaseObjectAccessor $accessor,
		// TODO(ADR-047 Phase-1b, DEFERRED): PAdES-LTV signing deferred — the
		// 2026-07-04 tc-lib-pdf spike was No-Go (8.65 stubs the B-T
		// timestamp). Interim = SHA-256 hash-only (UnsignedPadesSigner).
		// When resumed, implement a real PadesSigner against pyHanko (MIT
		// sidecar, real B-LTA) or a matured tc-lib-pdf; configurable
		// RFC-3161 TSA URL.
		private readonly PadesSigner $signer,
		private readonly OneTimeDownloadTokenStore $tokenStore,
		private readonly AuditTrailMapperInterface $auditTrailMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Generate the signed export bundle for a case and mint a one-time token.
	 *
	 * Assembles the subject data via the existing access-export primitive,
	 * renders it to PDF bytes, signs (or stub-signs) them, records the
	 * generation on the case (audited), and returns the signed bundle + a raw
	 * one-time download token bound to the case.
	 *
	 * @param string $caseUuid The case object uuid.
	 *
	 * @return array{caseUuid: string, contentHash: string, signed: bool, signatureState: string, downloadToken: string}
	 *
	 * @throws RuntimeException When the case cannot be loaded (absent or unauthorised).
	 *
	 * @spec openspec/specs/dsar-export-bundle/spec.md
	 */
	public function generate(string $caseUuid): array {
		$case = $this->requireCase(caseUuid: $caseUuid);
		$data = $case->getObject();
		$signed = $this->signedBundleFor(caseUuid: $caseUuid, data: $data);

		// Record the generation on the case (audited via the accessor's
		// processing-activity pin). A monotonically-growing marker on the case
		// records the last bundle's hash + signature state.
		$data['lastBundle'] = $this->bundleMarker(signed: $signed);
		$this->accessor->save(case: $case, data: $data);

		$token = $this->tokenStore->mint(caseUuid: $caseUuid);

		return array_merge(['caseUuid' => $caseUuid], $this->bundleMarker(signed: $signed), ['downloadToken' => $token]);
	}//end generate()

	/**
	 * Redeem a one-time token and return the signed bundle bytes for download.
	 *
	 * The token is verified + BURNED (single-use) against the case scope; a
	 * replay is refused. On success the bundle is re-assembled and re-signed
	 * from the same RBAC-scoped case so the returned bytes match the recorded
	 * hash. Returns null when the token is invalid/expired/replayed or the case
	 * is not authorised (the caller maps null to a refusal).
	 *
	 * @param string $caseUuid The case the download is scoped to.
	 * @param string $token The raw one-time token.
	 *
	 * @return SignedBundle|null The signed bundle, or null when refused.
	 *
	 * @spec openspec/specs/dsar-export-bundle/spec.md
	 */
	public function download(string $caseUuid, string $token): ?SignedBundle {
		// Case scope is enforced twice: the token is bound to the case, and the
		// case is loaded under the caller's RBAC. Both must pass, and the case
		// is asked first so a refused case does not burn the token.
		$case = $this->accessor->load(caseUuid: $caseUuid);
		if ($case === null || $this->tokenStore->redeem(token: $token, caseUuid: $caseUuid) === false) {
			return null;
		}

		return $this->signedBundleFor(caseUuid: $caseUuid, data: $case->getObject());
	}//end download()

	/**
	 * Assemble the regulator dossier for a case.
	 *
	 * Reads the case under the caller's RBAC and reflects what was collected
	 * (the `evidence` sub-collection), what was redacted with grounds (the
	 * `redactions` sub-collection), and the case history (its immutable
	 * audit-trail entries). It never reaches data outside the caller's
	 * authorisation — it reads only the already-authorised case object + that
	 * object's own trail.
	 *
	 * @param string $caseUuid The case object uuid.
	 *
	 * @return array{caseUuid: string, subjectId: string, status: string, evidence: array, redactions: array, history: array}
	 *
	 * @throws RuntimeException When the case cannot be loaded (absent or unauthorised).
	 *
	 * @spec openspec/specs/dsar-export-bundle/spec.md
	 */
	public function assembleRegulatorDossier(string $caseUuid): array {
		$case = $this->requireCase(caseUuid: $caseUuid);
		$data = $case->getObject();

		return [
			'caseUuid' => $caseUuid,
			'subjectId' => (string)($data['subjectId'] ?? ''),
			'status' => (string)($data['status'] ?? ''),
			'evidence' => $this->listUnder(data: $data, key: 'evidence'),
			'redactions' => $this->listUnder(data: $data, key: 'redactions'),
			'history' => $this->historyOf(case: $case, caseUuid: $caseUuid),
		];
	}//end assembleRegulatorDossier()

	/**
	 * Load the case under the caller's RBAC, or refuse.
	 *
	 * @param string $caseUuid The case object uuid.
	 *
	 * @return ObjectEntity The authorised case.
	 *
	 * @throws RuntimeException When the case is absent or not authorised.
	 */
	private function requireCase(string $caseUuid): ObjectEntity {
		$case = $this->accessor->load(caseUuid: $caseUuid);
		if ($case !== null) {
			return $case;
		}

		throw new RuntimeException(message: sprintf('Case "%s" not found or not authorised.', $caseUuid));
	}//end requireCase()

	/**
	 * Assemble, render and sign the subject's bundle for a case.
	 *
	 * Reuses the existing RBAC-scoped access-export assembler — no
	 * re-implementation of subject-data discovery/assembly (ADR-011).
	 *
	 * @param string               $caseUuid The case object uuid.
	 * @param array<string, mixed> $data     The case payload.
	 *
	 * @return SignedBundle The signed (or stub-signed) bundle.
	 */
	private function signedBundleFor(string $caseUuid, array $data): SignedBundle {
		$assembled = $this->dsrService->assembleAccessExport(
			subjectId: (string)($data['subjectId'] ?? ''),
			type: $this->subjectTypeOf(data: $data)
		);

		return $this->signer->sign(bytes: $this->renderPdf(caseUuid: $caseUuid, assembled: $assembled));
	}//end signedBundleFor()

	/**
	 * The case's PII type filter, or null when it names none.
	 *
	 * @param array<string, mixed> $data The case payload.
	 *
	 * @return string|null The type.
	 */
	private function subjectTypeOf(array $data): ?string {
		if (isset($data['subjectType']) === false || $data['subjectType'] === '') {
			return null;
		}

		return (string)$data['subjectType'];
	}//end subjectTypeOf()

	/**
	 * The hash and signature state of a signed bundle.
	 *
	 * @param SignedBundle $signed The signed bundle.
	 *
	 * @return array{contentHash: string, signed: bool, signatureState: string}
	 */
	private function bundleMarker(SignedBundle $signed): array {
		return [
			'contentHash' => $signed->getContentHash(),
			'signed' => $signed->isSigned(),
			'signatureState' => $signed->getSignatureState(),
		];
	}//end bundleMarker()

	/**
	 * A sub-collection of the case as a list, or empty when it is not one.
	 *
	 * @param array<string, mixed> $data The case payload.
	 * @param string               $key  The sub-collection key.
	 *
	 * @return array<int, mixed> The list.
	 */
	private function listUnder(array $data, string $key): array {
		if (is_array($data[$key] ?? null) === false) {
			return [];
		}

		return array_values($data[$key]);
	}//end listUnder()

	/**
	 * The case's own immutable audit-trail entries; empty when unreadable.
	 *
	 * @param ObjectEntity $case     The authorised case.
	 * @param string       $caseUuid The case uuid as requested.
	 *
	 * @return array<int, array<string, mixed>> The serialised entries, as far as they could be read.
	 */
	private function historyOf(ObjectEntity $case, string $caseUuid): array {
		$history = [];
		try {
			foreach ($this->auditTrailMapper->findByObjectUntil(objectUuid: (string)$case->getUuid()) as $entry) {
				$history[] = $entry->jsonSerialize();
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				message: sprintf('[ExportBundleService] dossier history read failed for case "%s": %s', $caseUuid, $e->getMessage())
			);
		}

		return $history;
	}//end historyOf()

	/**
	 * Render the assembled export data as PDF disclosure-document bytes.
	 *
	 * A minimal, dependency-free PDF is produced here so the bundle is a real
	 * `application/pdf` document that the SHA-256 hash + (future) PAdES-LTV
	 * signature apply to. Rich PDF layout is a rendering concern that can grow
	 * behind this method without touching the signing/token/dossier contract.
	 *
	 * A deterministic, single-page PDF skeleton carrying the disclosure
	 * summary. Deterministic output keeps the SHA-256 hash stable for a given
	 * case + assembled payload.
	 *
	 * @param string $caseUuid The case object uuid (document header).
	 * @param array<string, mixed> $assembled The assembled access-export payload.
	 *
	 * @return string The PDF bytes.
	 *
	 * @spec openspec/specs/dsar-export-bundle/spec.md
	 */
	private function renderPdf(string $caseUuid, array $assembled): string {
		$text = $this->disclosureText(caseUuid: $caseUuid, assembled: $assembled);
		$stream = 'BT /F1 12 Tf 40 720 Td (' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text) . ') Tj ET';

		return $this->serialisePdf(
			objects: [
				'<< /Type /Catalog /Pages 2 0 R >>',
				'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
				'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
				'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
				'<< /Length ' . strlen($stream) . ' >>' . "\nstream\n" . $stream . "\nendstream",
			]
		);
	}//end renderPdf()

	/**
	 * The one line of disclosure summary the PDF carries.
	 *
	 * @param string               $caseUuid  The case object uuid.
	 * @param array<string, mixed> $assembled The assembled access-export payload.
	 *
	 * @return string The summary line.
	 */
	private function disclosureText(string $caseUuid, array $assembled): string {
		return sprintf(
			'GDPR Access Disclosure - case %s - subject %s - %d object(s) - generated %s',
			$caseUuid,
			(string)($assembled['subject'] ?? ''),
			(int)($assembled['objectCount'] ?? 0),
			(string)($assembled['generatedAt'] ?? '')
		);
	}//end disclosureText()

	/**
	 * Lay numbered PDF objects out with their cross-reference table and trailer.
	 *
	 * @param array<int, string> $objects The object bodies, numbered from 1 in order.
	 *
	 * @return string The PDF bytes.
	 */
	private function serialisePdf(array $objects): string {
		$pdf = "%PDF-1.4\n";
		$xref = '';
		foreach ($objects as $i => $body) {
			$xref .= sprintf("%010d 00000 n \n", strlen($pdf));
			$pdf .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
		}

		$count = (count($objects) + 1);

		return $pdf . "xref\n0 " . $count . "\n0000000000 65535 f \n" . $xref
			. "trailer\n<< /Size " . $count . " /Root 1 0 R >>\nstartxref\n" . strlen($pdf) . "\n%%EOF";
	}//end serialisePdf()
}//end class
