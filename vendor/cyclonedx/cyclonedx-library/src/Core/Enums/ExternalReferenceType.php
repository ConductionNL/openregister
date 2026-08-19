<?php

declare(strict_types=1);

/*
 * This file is part of CycloneDX PHP Library.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * SPDX-License-Identifier: Apache-2.0
 * Copyright (c) OWASP Foundation. All Rights Reserved.
 */

namespace CycloneDX\Core\Enums;

/**
 * See {@link https://cyclonedx.org/schema/bom/1.1 Schema 1.1} for `externalReferenceType`.
 * See {@link https://cyclonedx.org/schema/bom/1.2 Schema 1.2} for `externalReferenceType`.
 * See {@link https://cyclonedx.org/schema/bom/1.3 Schema 1.3} for `externalReferenceType`.
 * See {@link https://cyclonedx.org/schema/bom/1.4 Schema 1.4} for `externalReferenceType`.
 * See {@link https://cyclonedx.org/schema/bom/1.5 Schema 1.5} for `externalReferenceType`.
 * See {@link https://cyclonedx.org/schema/bom/1.6 Schema 1.6} for `externalReferenceType`.
 * See {@link https://cyclonedx.org/schema/bom/1.7 Schema 1.7} for `externalReferenceType`.
 *
 * @author jkowalleck
 */
enum ExternalReferenceType: string
{
    case VCS = 'vcs';
    case IssueTracker = 'issue-tracker';
    case Website = 'website';
    case Advisories = 'advisories';
    case BOM = 'bom';
    case MailingList = 'mailing-list';
    case Social = 'social';
    case Chat = 'chat';
    case Documentation = 'documentation';
    case Support = 'support';
    case SourceDistribution = 'source-distribution';
    case Distribution = 'distribution';
    case DistributionIntake = 'distribution-intake';
    case License = 'license';
    case BuildMeta = 'build-meta';
    case BuildSystem = 'build-system';
    case ReleaseNotes = 'release-notes';
    case SecurityContact = 'security-contact';
    case ModelCard = 'model-card';
    case Log = 'log';
    case Configuration = 'configuration';
    case Evidence = 'evidence';
    case Formulation = 'formulation';
    case Attestation = 'attestation';
    case ThreatModel = 'threat-model';
    case AdversaryModel = 'adversary-model';
    case RiskAssessment = 'risk-assessment';
    case VulnerabilityAssertion = 'vulnerability-assertion';
    case ExploitabilityStatement = 'exploitability-statement';
    case PentestReport = 'pentest-report';
    case StaticAnalysisReport = 'static-analysis-report';
    case DynamicAnalysisReport = 'dynamic-analysis-report';
    case RuntimeAnalysisReport = 'runtime-analysis-report';
    case ComponentAnalysisReport = 'component-analysis-report';
    case MaturityReport = 'maturity-report';
    case CertificationReport = 'certification-report';
    case QualityMetrics = 'quality-metrics';
    case CodifiedInfrastructure = 'codified-infrastructure';
    case POAM = 'poam';
    case ElectronicSignature = 'electronic-signature';
    case DigitalSignature = 'digital-signature';
    case RFC9116 = 'rfc-9116';
    case Citation = 'citation';
    case Patent = 'patent';
    case PatentAssertion = 'patent-assertion';
    case PatentFamily = 'patent-family';

    // ----

    case Other = 'other';
}
