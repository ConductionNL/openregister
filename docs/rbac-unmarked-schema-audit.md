# RBAC audit: schemas that declare no authorization

Task 1 of `openspec/changes/rbac-default-authenticated`. Before the
absent-authorization default can flip from public to authenticated, every
unmarked schema needs a stated intent — **by name, not by count**, because a
count cannot be reviewed and the review is the whole point.

**Surveyed 2026-08-15.** Re-run this survey at review time rather than quoting
it; the denominator moves as apps ship schemas, and a stale denominator makes
an audit read as complete when it is not.

## The numbers

**504 of 571 declared schemas (88%) carry no authorization block**, across 15
apps.

| app | marked | UNMARKED |
| --- | --- | --- |
| scholiq | 0 | **118** |
| shillinq | 0 | **114** |
| procest | 6 | **85** |
| openconnector | 0 | **39** |
| decidesk | 5 | **34** |
| hermiq | 1 | **28** |
| pipelinq | 1 | **26** |
| docudesk | 1 | **20** |
| openregister | 15 | **16** |
| larpingapp | 1 | **9** |
| portaliq | 0 | **9** |
| petstore | 0 | **3** |
| doriath · launchpad · nextcloud-app-template | 0 | **3** |
| openbuild | 6 | 0 |
| opencatalogi | 10 | 0 |
| softwarecatalog | 21 | 0 |
| **total** | **67** | **504** |

## Two ways an earlier version of this survey was WRONG

Both are recorded because they are the ways the next re-run will go wrong too.

**1. It read one register file per app.** The first pass globbed
`lib/Settings/*register*.json | head -1` and reported **321 of 368 across 6
apps**. openregister alone ships **14** register files; procest ships 2. The
undercount hid 183 schemas and nine entire apps — including openconnector (39)
and hermiq (28), which had appeared to have none. **Iterate every register
file.**

**2. It reads whichever branch each checkout is on.** These are shared
checkouts and other workstreams move them. At survey time `portaliq` sat on
`fix/local-lib-guard-semver`, which predates its CMS work — so `page`, `menu`,
`glossaryTerm` and `portal` are absent from its nine rows below. **Record the
branch each app was read from, or re-read from a known ref.**

## How to read the proposed intent

The intent column is a **first pass by keyword**, not a decision. It is
deliberately biased toward refusal: anything naming a session, account, token,
secret, audit record, salary, invoice, submission or person is proposed
`restricted`, and only four schemas in 504 are proposed as public candidates at
all.

**Each app's maintainers decide their own rows.** This document collects and
records; it does not decide on anyone's behalf. A row is settled when a
maintainer has said so — an unreviewed `authenticated` here is a default, not
an answer.

Distribution of the first pass: **432 authenticated · 68 restricted · 4 public
candidates** (`ContactDetail`, `PublicationRecord`, `contact`, `portalPage`).
Note that `ContactDetail` and `contact` were flagged only because their names
begin with "contact"; both plainly hold personal data and are the clearest
example of why this column needs a human.

## Before the default flips

Every row marked public must carry an explicit `"group": "public"` read rule
**first**. Flipping the default before that turns those surfaces blank — which
is the safe direction, but it is an outage, and calling an outage a security
fix is how the next one gets reverted.

---

## Unmarked schemas, by app

### scholiq — 118 unmarked

| schema | proposed intent |
| --- | --- |
| `AccessibilityFeedback` | authenticated |
| `AccessibilityLimitation` | authenticated |
| `AccessibilityStatement` | authenticated |
| `AdmissionsRound` | authenticated |
| `AiFeature` | authenticated |
| `Application` | authenticated |
| `Assessment` | authenticated |
| `AssessmentReliability` | authenticated |
| `AssessmentResult` | authenticated |
| `Assignment` | authenticated |
| `AttendanceFlag` | authenticated |
| `AttendanceRecord` | authenticated |
| `AttendanceThreshold` | authenticated |
| `Attestation` | authenticated |
| `BehaviourIncident` | authenticated |
| `BpvPlacement` | authenticated |
| `BpvVisitReport` | authenticated |
| `BsaDecision` | authenticated |
| `BsaProgressFlag` | authenticated |
| `BsaTrajectory` | authenticated |
| `BsaWarning` | authenticated |
| `Cohort` | authenticated |
| `Competency` | authenticated |
| `CompetencyAttainment` | authenticated |
| `CompetencyFramework` | authenticated |
| `ConferenceReport` | authenticated |
| `ConferenceRound` | authenticated |
| `ConferenceSignup` | authenticated |
| `ConferenceSlot` | authenticated |
| `Course` | authenticated |
| `CourseEvaluationResponse` | authenticated |
| `CoursePackageImportReport` | authenticated |
| `CourseQualityScore` | authenticated |
| `CourseTemplate` | authenticated |
| `Credential` | restricted |
| `CurriculumPlan` | authenticated |
| `DataExchangeJob` | authenticated |
| `DataMappingProfile` | authenticated |
| `DeliberationRecord` | authenticated |
| `DossierNote` | authenticated |
| `EngagementLevel` | authenticated |
| `EngagementRiskFlag` | authenticated |
| `EngagementRiskThreshold` | authenticated |
| `EngagementScore` | authenticated |
| `Enrolment` | authenticated |
| `Entitlement` | authenticated |
| `EvaluationCampaign` | authenticated |
| `EvaluationInvitation` | authenticated |
| `ExamAccommodation` | authenticated |
| `ExchangeErrorCode` | authenticated |
| `ExchangeRejection` | authenticated |
| `ExcuseRequest` | restricted |
| `ExemptionCase` | authenticated |
| `ExternalAssessor` | authenticated |
| `ExternalTrainingRecord` | authenticated |
| `FeeItem` | authenticated |
| `FinalGrade` | authenticated |
| `FraudCase` | authenticated |
| `GradeEntry` | authenticated |
| `GradeNotification` | restricted |
| `GradeScale` | authenticated |
| `GroupPlan` | authenticated |
| `GroupPlanEvaluation` | authenticated |
| `GroupPlanSubgroup` | authenticated |
| `ImprovementAction` | authenticated |
| `Item` | authenticated |
| `ItemBank` | authenticated |
| `ItemRevisionFlag` | authenticated |
| `ItemStatistics` | authenticated |
| `Leaderboard` | authenticated |
| `LearnerEngagement` | authenticated |
| `LearnerProfile` | authenticated |
| `LearningPlan` | authenticated |
| `LearningPlanEvaluation` | authenticated |
| `LearningPlanTemplate` | authenticated |
| `LearningRecordExport` | authenticated |
| `LearningRecordImport` | authenticated |
| `LearningRecordShare` | authenticated |
| `Lesson` | authenticated |
| `LessonCompletion` | authenticated |
| `LtiToolPlacement` | authenticated |
| `Material` | authenticated |
| `Order` | authenticated |
| `OrderLine` | authenticated |
| `PaymentTransaction` | restricted |
| `PeerFeedbackSummary` | authenticated |
| `PeerReview` | authenticated |
| `PointAward` | authenticated |
| `PointRule` | authenticated |
| `PokSignature` | authenticated |
| `Portfolio` | authenticated |
| `PortfolioEntry` | authenticated |
| `PortfolioShare` | authenticated |
| `PortfolioTemplate` | authenticated |
| `Praktijkopleider` | authenticated |
| `Praktijkovereenkomst` | authenticated |
| `ProctoringSession` | restricted |
| `Programme` | authenticated |
| `Regulation` | authenticated |
| `ReportCard` | authenticated |
| `ReportCardParentNotification` | restricted |
| `ReportPeriod` | authenticated |
| `RolloverPlan` | authenticated |
| `Room` | authenticated |
| `Rubric` | authenticated |
| `SelfAssessment` | authenticated |
| `Session` | restricted |
| `Signature` | authenticated |
| `SovereigntyPolicy` | authenticated |
| `SubjectChoice` | authenticated |
| `Submission` | restricted |
| `SupportRequest` | authenticated |
| `TeacherAvailability` | authenticated |
| `TimetableConflict` | authenticated |
| `TlvApplication` | authenticated |
| `WellbeingCheckIn` | authenticated |
| `WerkprocesAssessment` | authenticated |
| `XapiStatement` | authenticated |

### shillinq — 114 unmarked

| schema | proposed intent |
| --- | --- |
| `APInvoice` | restricted |
| `Account` | restricted |
| `AllocationRule` | authenticated |
| `AnalyticalDimension` | authenticated |
| `AuditDocument` | restricted |
| `BBVProgramma` | authenticated |
| `BalanceSheet` | authenticated |
| `BankAccount` | restricted |
| `BankConnection` | authenticated |
| `BankStatement` | authenticated |
| `BankStatementLine` | authenticated |
| `BankingRule` | authenticated |
| `BbvAccountMapping` | restricted |
| `BbvTaakveld` | authenticated |
| `Begrotingswijziging` | authenticated |
| `BeleidsIndicator` | authenticated |
| `CashflowAPSchedule` | authenticated |
| `CashflowARProjection` | authenticated |
| `CashflowBufferPolicy` | authenticated |
| `CashflowCalibrationReport` | authenticated |
| `CashflowForecastHorizon` | authenticated |
| `CashflowRecurring` | authenticated |
| `CashflowScenario` | authenticated |
| `CashflowWeek` | authenticated |
| `ClosingAccount` | restricted |
| `ClosingEntry` | authenticated |
| `ClosingEntryTemplate` | authenticated |
| `ComplianceAuditTrail` | restricted |
| `ComplianceReport` | authenticated |
| `ConsolidatedReport` | authenticated |
| `ConsolidationGroup` | authenticated |
| `CurrencyBalance` | authenticated |
| `DepreciationSchedule` | authenticated |
| `EconomischeCategorie` | authenticated |
| `ExpenseClaimEntry` | authenticated |
| `FiscalYear` | authenticated |
| `FixedAsset` | authenticated |
| `FxRate` | authenticated |
| `GLLine` | authenticated |
| `GLTransaction` | authenticated |
| `GRDeelnemer` | authenticated |
| `GRVerdeelsleutel` | authenticated |
| `Grant` | authenticated |
| `IPAssetValuation` | authenticated |
| `IbAangifteExport` | authenticated |
| `IcpStatement` | authenticated |
| `InnovatieboxElection` | authenticated |
| `InnovatieboxTariff` | authenticated |
| `InventoryReorderRule` | authenticated |
| `InventoryStockTransfer` | authenticated |
| `Iv3Export` | authenticated |
| `KorRegime` | authenticated |
| `KorThreshold` | authenticated |
| `Kostenpost` | authenticated |
| `Location` | authenticated |
| `ManagementLetter` | authenticated |
| `MatchingRule` | authenticated |
| `MaterieleVasteActiva` | authenticated |
| `MeerjarenBudget` | authenticated |
| `MileageEntry` | authenticated |
| `MileageRate` | authenticated |
| `Paragraaf` | authenticated |
| `PerDiem` | authenticated |
| `PerDiemRate` | authenticated |
| `Programma` | authenticated |
| `Project` | authenticated |
| `ProjectAssignment` | authenticated |
| `ProjectBudget` | authenticated |
| `ProvincialeFondsPosting` | authenticated |
| `RateCard` | authenticated |
| `RateCardTemplate` | authenticated |
| `RateCardVersion` | authenticated |
| `RateRecord` | authenticated |
| `RateSchedule` | authenticated |
| `Receipt` | authenticated |
| `ReconciliationMatch` | authenticated |
| `RepaymentInstallment` | restricted |
| `Reserve` | authenticated |
| `RetainedEarnings` | authenticated |
| `RetentionRule` | authenticated |
| `SBRDocumentType` | authenticated |
| `SalesOrder` | authenticated |
| `SalesOrderLine` | authenticated |
| `ServiceCategoryOverride` | authenticated |
| `SisaRegelingIndicator` | authenticated |
| `SisaReport` | authenticated |
| `SoProject` | authenticated |
| `SoUrenStaat` | authenticated |
| `Subsidie` | authenticated |
| `Taakveld` | authenticated |
| `TaxEstimate` | authenticated |
| `TaxRegimeConfiguration` | authenticated |
| `TaxSummaryReport` | authenticated |
| `TreasuryAccount` | restricted |
| `TrialBalance` | authenticated |
| `UrenRegistratie` | authenticated |
| `VATAuditRecord` | restricted |
| `VATGLAccounts` | restricted |
| `VatCorrection` | authenticated |
| `VatReturn` | authenticated |
| `VatTariff` | authenticated |
| `Voorziening` | authenticated |
| `WBSOActivityCode` | authenticated |
| `WBSOExportLog` | restricted |
| `WBSOTag` | authenticated |
| `WaterschapHeffingPosting` | authenticated |
| `WinstToerekening` | authenticated |
| `WipBalance` | authenticated |
| `XBRLMapping` | authenticated |
| `XBRLTaxonomy` | authenticated |
| `ZzpDeduction` | authenticated |
| `ZzpDeductionAmounts` | authenticated |
| `example` | authenticated |
| `kernGegevensConfig` | authenticated |

### procest — 85 unmarked

| schema | proposed intent |
| --- | --- |
| `abonnement` | authenticated |
| `adviceRequest` | authenticated |
| `adviceResponse` | authenticated |
| `adviesAanvraag` | authenticated |
| `advisoryBody` | authenticated |
| `advisoryReport` | authenticated |
| `aiAuditEntry` | restricted |
| `appealDecision` | authenticated |
| `automaticAction` | authenticated |
| `bacAdviceRequest` | authenticated |
| `beroep` | authenticated |
| `bezwaar` | authenticated |
| `bezwaarDecision` | authenticated |
| `bezwaaradviescommissie` | authenticated |
| `case` | authenticated |
| `caseDocument` | authenticated |
| `caseFederatedActivity` | authenticated |
| `caseFederatedShare` | authenticated |
| `caseObject` | authenticated |
| `caseProperty` | authenticated |
| `caseShare` | authenticated |
| `caseType` | authenticated |
| `casetransfer` | authenticated |
| `catalogus` | restricted |
| `checklistItem` | authenticated |
| `complaint` | authenticated |
| `complaintCategory` | authenticated |
| `complaintDisposition` | authenticated |
| `consultation` | authenticated |
| `customerContact` | authenticated |
| `decision` | authenticated |
| `decisionDocument` | authenticated |
| `decisionType` | authenticated |
| `dispatch` | authenticated |
| `document` | authenticated |
| `documentLink` | authenticated |
| `documentType` | authenticated |
| `emailTemplate` | authenticated |
| `handhavingsactie` | authenticated |
| `hearing` | authenticated |
| `hearingSession` | restricted |
| `inspectieChecklist` | authenticated |
| `inspectieRapport` | authenticated |
| `inspectionChecklist` | authenticated |
| `inspectionChecklistRun` | authenticated |
| `inspectionChecklistTemplate` | authenticated |
| `inspectionResult` | authenticated |
| `kanaal` | authenticated |
| `lhsMatrix` | authenticated |
| `lhsMatrixCell` | authenticated |
| `lhsRecommendation` | authenticated |
| `location` | authenticated |
| `mapLayer` | authenticated |
| `objection` | authenticated |
| `parafeeractie` | authenticated |
| `parafeerroute` | authenticated |
| `paraferingAuditEntry` | restricted |
| `partnerOrganization` | authenticated |
| `propertyDefinition` | authenticated |
| `result` | authenticated |
| `resultType` | authenticated |
| `role` | authenticated |
| `roleType` | authenticated |
| `statusRecord` | authenticated |
| `statusType` | authenticated |
| `supplier` | authenticated |
| `supplierContract` | authenticated |
| `supplierInvoice` | restricted |
| `supplierKpi` | authenticated |
| `supplierMessage` | restricted |
| `supplierTender` | authenticated |
| `supplierUser` | restricted |
| `task` | authenticated |
| `tenant` | authenticated |
| `tenantBillingEvent` | authenticated |
| `tenantConfiguration` | authenticated |
| `tenantMandate` | authenticated |
| `tenantOnboardingTask` | authenticated |
| `tenantQuota` | authenticated |
| `tenantUser` | restricted |
| `usageRights` | authenticated |
| `voorstel` | authenticated |
| `wmsLayer` | authenticated |
| `workflowTemplate` | authenticated |
| `zaaktypeInformatieobjecttype` | authenticated |

### openconnector — 39 unmarked

| schema | proposed intent |
| --- | --- |
| `bankfeed_batch` | authenticated |
| `bankfeed_connection` | authenticated |
| `call_log` | restricted |
| `cardfeed_account` | restricted |
| `cardfeed_batch` | authenticated |
| `consumer` | authenticated |
| `dso_message` | restricted |
| `dso_verzoek` | authenticated |
| `endpoint` | authenticated |
| `event` | authenticated |
| `event_message` | restricted |
| `event_subscription` | authenticated |
| `fsc_call` | authenticated |
| `fsc_service` | authenticated |
| `iwmo_ijw_message` | restricted |
| `job` | authenticated |
| `job_log` | restricted |
| `kiss_klantcontact` | authenticated |
| `lti_deployment` | authenticated |
| `lti_identity_link` | authenticated |
| `lti_platform` | authenticated |
| `lti_tool` | authenticated |
| `mapping` | authenticated |
| `notificaties_abonnement` | authenticated |
| `openformulieren_form_mapping` | authenticated |
| `openformulieren_submission` | restricted |
| `payment_intent` | restricted |
| `peppol_transmission` | authenticated |
| `ris_sync_record` | authenticated |
| `rule` | authenticated |
| `sms_message` | restricted |
| `source` | authenticated |
| `stuf_message` | restricted |
| `sync_item_dead_letter` | authenticated |
| `synchronization` | authenticated |
| `synchronization_contract` | authenticated |
| `synchronization_contract_log` | restricted |
| `synchronization_log` | restricted |
| `zgw_version_translation_log` | restricted |

### decidesk — 34 unmarked

| schema | proposed intent |
| --- | --- |
| `ActionItem` | authenticated |
| `AgendaItem` | authenticated |
| `BoardProxy` | authenticated |
| `BudgetProposal` | authenticated |
| `CitizenPanel` | authenticated |
| `CitizenVote` | authenticated |
| `ConflictOfInterest` | authenticated |
| `ContactDetail` | **public?** — confirm |
| `Decision` | authenticated |
| `DecisionStage` | authenticated |
| `Deliberation` | authenticated |
| `DigitalDocument` | authenticated |
| `EngagementRecord` | authenticated |
| `EvaluationResponse` | authenticated |
| `EvaluationTemplate` | authenticated |
| `GovernanceBody` | authenticated |
| `GovernanceReport` | authenticated |
| `Meeting` | authenticated |
| `Membership` | restricted |
| `Minutes` | authenticated |
| `MonetaryAmount` | authenticated |
| `Notification` | restricted |
| `NotificationPreference` | restricted |
| `Offer` | authenticated |
| `Order` | authenticated |
| `Participant` | authenticated |
| `Person` | restricted |
| `Post` | authenticated |
| `Product` | authenticated |
| `PublicationRecord` | **public?** — confirm |
| `Report` | authenticated |
| `Transcript` | authenticated |
| `Vote` | authenticated |
| `VotingRound` | authenticated |

### hermiq — 28 unmarked

| schema | proposed intent |
| --- | --- |
| `AgentFlow` | authenticated |
| `AgentFlowRun` | authenticated |
| `AgentTemplate` | authenticated |
| `AgentWebhook` | authenticated |
| `AiFeature` | authenticated |
| `Approval` | authenticated |
| `Budget` | authenticated |
| `Context` | authenticated |
| `Control` | authenticated |
| `ControlFramework` | authenticated |
| `Conversation` | authenticated |
| `CourseRecommendation` | authenticated |
| `EvalDataset` | authenticated |
| `EvalRun` | authenticated |
| `Feedback` | authenticated |
| `GuardrailPolicy` | authenticated |
| `Incident` | authenticated |
| `Memory` | authenticated |
| `Message` | restricted |
| `ModelPolicy` | authenticated |
| `Schedule` | authenticated |
| `Session` | restricted |
| `SessionTurn` | restricted |
| `Skill` | authenticated |
| `SkillDraft` | authenticated |
| `SkillSource` | authenticated |
| `TenantControl` | authenticated |
| `UserProfile` | restricted |

### pipelinq — 26 unmarked

| schema | proposed intent |
| --- | --- |
| `agentProfile` | authenticated |
| `brpLookupVerzoek` | authenticated |
| `brpPersoon` | authenticated |
| `bsnAuditRecord` | restricted |
| `bsnValidatie` | restricted |
| `client` | authenticated |
| `contact` | **public?** — confirm |
| `leadProduct` | authenticated |
| `optOutVlag` | authenticated |
| `paymentProvider` | restricted |
| `pipeline` | authenticated |
| `posRefund` | authenticated |
| `posRefundLine` | authenticated |
| `posRole` | authenticated |
| `posStaff` | authenticated |
| `posTransaction` | authenticated |
| `posTransactionLine` | authenticated |
| `product` | authenticated |
| `productCategory` | authenticated |
| `queue` | authenticated |
| `receiptPrintLog` | restricted |
| `receiptTemplate` | authenticated |
| `refundReason` | authenticated |
| `relationship` | authenticated |
| `skill` | authenticated |
| `task` | authenticated |

### docudesk — 20 unmarked

| schema | proposed intent |
| --- | --- |
| `anonymizationLink` | authenticated |
| `base` | authenticated |
| `batchCorrespondenceJob` | authenticated |
| `correspondence` | authenticated |
| `customDictionary` | authenticated |
| `customDictionaryTerm` | authenticated |
| `dossier` | authenticated |
| `financialExtraction` | authenticated |
| `generatedDocument` | authenticated |
| `glAccountBooking` | restricted |
| `glAccountMappingRule` | restricted |
| `huisstijl` | authenticated |
| `prohibitionOverrideAudit` | restricted |
| `publicationConsent` | restricted |
| `signerRecord` | authenticated |
| `signingAuditEntry` | restricted |
| `signingRequest` | authenticated |
| `signingSession` | restricted |
| `template` | authenticated |
| `templateVersion` | authenticated |

### openregister — 16 unmarked

| schema | proposed intent |
| --- | --- |
| `activiteit` | authenticated |
| `brokeredcredential` | restricted |
| `dataSubjectRequest` | authenticated |
| `dsarPolicyPack` | authenticated |
| `edepotTransfer` | authenticated |
| `edepotTransferProof` | authenticated |
| `locatie` | authenticated |
| `mergeOperation` | authenticated |
| `notification` | restricted |
| `omgevingsdocument` | authenticated |
| `schedule` | authenticated |
| `trigger` | authenticated |
| `trustConfiguration` | authenticated |
| `vergunningaanvraag` | authenticated |
| `webhook` | authenticated |
| `workflow` | authenticated |

### larpingapp — 9 unmarked

| schema | proposed intent |
| --- | --- |
| `ability` | authenticated |
| `character` | authenticated |
| `condition` | authenticated |
| `effect` | authenticated |
| `event` | authenticated |
| `item` | authenticated |
| `player` | authenticated |
| `setting` | authenticated |
| `skill` | authenticated |

### portaliq — 9 unmarked

| schema | proposed intent |
| --- | --- |
| `exampleDocument` | authenticated |
| `portalAccount` | restricted |
| `portalAuditEntry` | restricted |
| `portalMessage` | restricted |
| `portalNotification` | restricted |
| `portalOidcState` | restricted |
| `portalPage` | **public?** — confirm |
| `portalSession` | restricted |
| `portalSubmission` | restricted |

### petstore — 3 unmarked

| schema | proposed intent |
| --- | --- |
| `category` | authenticated |
| `order` | authenticated |
| `pet` | authenticated |

### doriath — 1 unmarked

| schema | proposed intent |
| --- | --- |
| `example` | authenticated |

### launchpad — 1 unmarked

| schema | proposed intent |
| --- | --- |
| `Dashboard` | authenticated |

### nextcloud-app-template — 1 unmarked

| schema | proposed intent |
| --- | --- |
| `example` | authenticated |
