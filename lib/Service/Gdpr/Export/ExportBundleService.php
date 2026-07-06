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

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\Gdpr\Case\CaseObjectAccessor;
use OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Assembles + signs export bundles and regulator dossiers for a case.
 */
class ExportBundleService
{
    /**
     * Constructor.
     *
     * @param DataSubjectRequestService $dsrService       Reused access-export assembler (ADR-011).
     * @param CaseObjectAccessor        $accessor         RBAC-scoped, audited case load/save.
     * @param PadesSigner               $signer           Swappable signing seam.
     * @param OneTimeDownloadTokenStore $tokenStore       Single-use download-token store.
     * @param AuditTrailMapper          $auditTrailMapper Immutable trail reader for the dossier history.
     * @param LoggerInterface           $logger           Logger.
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
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly LoggerInterface $logger
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
     * @spec openspec/changes/dsar-case-engine/specs/dsar-export-bundle/spec.md
     */
    public function generate(string $caseUuid): array
    {
        $case = $this->accessor->load(caseUuid: $caseUuid);
        if ($case === null) {
            throw new RuntimeException(
                message: sprintf('Case "%s" not found or not authorised.', $caseUuid)
            );
        }

        $data      = $case->getObject();
        $subjectId = (string) ($data['subjectId'] ?? '');
        $type      = null;
        if (isset($data['subjectType']) === true && $data['subjectType'] !== '') {
            $type = (string) $data['subjectType'];
        }

        // Reuse the existing RBAC-scoped access-export assembler — no
        // re-implementation of subject-data discovery/assembly (ADR-011).
        $assembled = $this->dsrService->assembleAccessExport(
            subjectId: $subjectId,
            type: $type
        );

        $pdfBytes = $this->renderPdf(caseUuid: $caseUuid, assembled: $assembled);
        $signed   = $this->signer->sign(bytes: $pdfBytes);

        // Record the generation on the case (audited via the accessor's
        // processing-activity pin). A monotonically-growing marker on the case
        // records the last bundle's hash + signature state.
        $data['lastBundle'] = [
            'contentHash'    => $signed->getContentHash(),
            'signed'         => $signed->isSigned(),
            'signatureState' => $signed->getSignatureState(),
        ];
        $this->accessor->save(case: $case, data: $data);

        $token = $this->tokenStore->mint(caseUuid: $caseUuid);

        return [
            'caseUuid'       => $caseUuid,
            'contentHash'    => $signed->getContentHash(),
            'signed'         => $signed->isSigned(),
            'signatureState' => $signed->getSignatureState(),
            'downloadToken'  => $token,
        ];
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
     * @param string $token    The raw one-time token.
     *
     * @return SignedBundle|null The signed bundle, or null when refused.
     *
     * @spec openspec/changes/dsar-case-engine/specs/dsar-export-bundle/spec.md
     */
    public function download(string $caseUuid, string $token): ?SignedBundle
    {
        // Case scope is enforced twice: the token is bound to the case, and the
        // case is loaded under the caller's RBAC. Both must pass.
        $case = $this->accessor->load(caseUuid: $caseUuid);
        if ($case === null) {
            return null;
        }

        if ($this->tokenStore->redeem(token: $token, caseUuid: $caseUuid) === false) {
            return null;
        }

        $data      = $case->getObject();
        $subjectId = (string) ($data['subjectId'] ?? '');
        $type      = null;
        if (isset($data['subjectType']) === true && $data['subjectType'] !== '') {
            $type = (string) $data['subjectType'];
        }

        $assembled = $this->dsrService->assembleAccessExport(
            subjectId: $subjectId,
            type: $type
        );
        $pdfBytes  = $this->renderPdf(caseUuid: $caseUuid, assembled: $assembled);

        return $this->signer->sign(bytes: $pdfBytes);
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
     * @spec openspec/changes/dsar-case-engine/specs/dsar-export-bundle/spec.md
     */
    public function assembleRegulatorDossier(string $caseUuid): array
    {
        $case = $this->accessor->load(caseUuid: $caseUuid);
        if ($case === null) {
            throw new RuntimeException(
                message: sprintf('Case "%s" not found or not authorised.', $caseUuid)
            );
        }

        $data       = $case->getObject();
        $evidence   = [];
        $redactions = [];
        if (isset($data['evidence']) === true && is_array($data['evidence']) === true) {
            $evidence = array_values($data['evidence']);
        }

        if (isset($data['redactions']) === true && is_array($data['redactions']) === true) {
            $redactions = array_values($data['redactions']);
        }

        $history = [];
        try {
            $entries = $this->auditTrailMapper->findByObjectUntil(
                objectId: (int) $case->getId(),
                objectUuid: (string) $case->getUuid()
            );
            foreach ($entries as $entry) {
                $history[] = $entry->jsonSerialize();
            }
        } catch (Throwable $e) {
            $this->logger->warning(
                message: sprintf(
                    '[ExportBundleService] dossier history read failed for case "%s": %s',
                    $caseUuid,
                    $e->getMessage()
                )
            );
        }

        return [
            'caseUuid'   => $caseUuid,
            'subjectId'  => (string) ($data['subjectId'] ?? ''),
            'status'     => (string) ($data['status'] ?? ''),
            'evidence'   => $evidence,
            'redactions' => $redactions,
            'history'    => $history,
        ];
    }//end assembleRegulatorDossier()

    /**
     * Render the assembled export data as PDF disclosure-document bytes.
     *
     * A minimal, dependency-free PDF is produced here so the bundle is a real
     * `application/pdf` document that the SHA-256 hash + (future) PAdES-LTV
     * signature apply to. Rich PDF layout is a rendering concern that can grow
     * behind this method without touching the signing/token/dossier contract.
     *
     * @param string               $caseUuid  The case object uuid (document header).
     * @param array<string, mixed> $assembled The assembled access-export payload.
     *
     * @return string The PDF bytes.
     *
     * @spec openspec/changes/dsar-case-engine/specs/dsar-export-bundle/spec.md
     */
    private function renderPdf(string $caseUuid, array $assembled): string
    {
        $subject     = (string) ($assembled['subject'] ?? '');
        $objectCount = (int) ($assembled['objectCount'] ?? 0);
        $generatedAt = (string) ($assembled['generatedAt'] ?? '');

        // A deterministic, single-page PDF skeleton carrying the disclosure
        // summary. Deterministic output keeps the SHA-256 hash stable for a
        // given case + assembled payload.
        $text    = sprintf(
            'GDPR Access Disclosure - case %s - subject %s - %d object(s) - generated %s',
            $caseUuid,
            $subject,
            $objectCount,
            $generatedAt
        );
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);

        $stream    = "BT /F1 12 Tf 40 720 Td (".$escaped.") Tj ET";
        $objects   = [];
        $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] '
            .'/Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>';
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[] = '<< /Length '.strlen($stream).' >>'."\nstream\n".$stream."\nendstream";

        $pdf     = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[($i + 1)] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n".$body."\nendobj\n";
        }

        $xrefPos = strlen($pdf);
        $count   = (count($objects) + 1);
        $pdf    .= "xref\n0 ".$count."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= "trailer\n<< /Size ".$count." /Root 1 0 R >>\nstartxref\n".$xrefPos."\n%%EOF";

        return $pdf;
    }//end renderPdf()
}//end class
