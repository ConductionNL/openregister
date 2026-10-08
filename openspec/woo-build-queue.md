# Woo build queue for this repository

28 OpenSpec changes in this repository close gaps in the Woo capability programme. Each has a change folder under `openspec/changes/` and an issue titled `[OpenSpec] <change-name>` that the OpenSpec workflow keeps in step with the spec.

## How to pick up a change

1. Take the first change below whose dependencies are all merged on `development`. A dependency in another repository is linked to its issue there; check that issue's linked PR is merged.
2. Inside a wave, the order below is the order to build. Statutory rows come first.
3. Read `openspec/woo-build-rules.md` before the first command, then the change's `proposal.md`, its specs and its `tasks.md`.
4. The decisions the specs cite (D1 to D13) are in `openspec/woo-decisions.md`. A spec never contradicts one. If a task seems to, stop and say so in the issue.
5. Work on the branch the issue names, open one PR with `--base development`, and close the issue through the PR.

Two things need a person, not an agent: settling the Woo refusal grounds against the law (dossiq `woo-refusal-grounds-list`, task 1, blocks seeding), and the screen-reader pass for row 15.5.

## Wave 1

| change | rows | depends on |
|---|---|---|
| [openregister/anonymisation-discloses-itself](https://github.com/ConductionNL/openregister/issues/4379) | 14.13, 14.14, 14.16, 14.17 | nothing |
| [openregister/anonymisation-discloses-itself-pipeline](https://github.com/ConductionNL/openregister/issues/4409) | 13.22, 14.18 | [openregister/anonymisation-discloses-itself](https://github.com/ConductionNL/openregister/issues/4379) |
| [openregister/anonymisation-image-seam](https://github.com/ConductionNL/openregister/issues/4380) | 4.13 | nothing |
| [openregister/anonymisation-placeholder-id-scope](https://github.com/ConductionNL/openregister/issues/4381) | 4.18, 4.28 | nothing |
| [openregister/appraisal-inherited-from-a-parent](https://github.com/ConductionNL/openregister/issues/4382) | supports 11.14 | nothing |
| [openregister/archive-members-are-searchable](https://github.com/ConductionNL/openregister/issues/4383) | 16.8 | nothing |
| [openregister/case-insensitive-ordering](https://github.com/ConductionNL/openregister/issues/4384) | 6.27 | nothing |
| [openregister/detection-knows-the-person](https://github.com/ConductionNL/openregister/issues/4385) | 14.19, 18.8 | nothing |
| [openregister/files-create-from-url](https://github.com/ConductionNL/openregister/issues/4386) | 1.18 | nothing |
| [openregister/history-schema-and-settings-edits-audited](https://github.com/ConductionNL/openregister/issues/4387) | 12.22, 12.30 | nothing |
| [openregister/large-file-handling](https://github.com/ConductionNL/openregister/issues/4388) | 16.7, 17.13, 17.14 | nothing |
| [openregister/near-duplicate-documents-by-text](https://github.com/ConductionNL/openregister/issues/4389) | 19.5 | nothing |
| [openregister/object-archive-state](https://github.com/ConductionNL/openregister/issues/4390) | supports 5.18, 19.15 | nothing |
| [openregister/object-organisation-from-a-property](https://github.com/ConductionNL/openregister/issues/4391) | supports 12.34 | nothing |
| [openregister/redaction-release-safeguards](https://github.com/ConductionNL/openregister/issues/4392) | 4.5, 4.21, 4.22, 4.26, 4.27 | nothing |
| [openregister/register-document-store](https://github.com/ConductionNL/openregister/issues/4414) | 11.11 | nothing |
| [openregister/reply-threading-by-headers](https://github.com/ConductionNL/openregister/issues/4393) | 19.6 | nothing |
| [openregister/reviewer-owns-their-decisions](https://github.com/ConductionNL/openregister/issues/4394) | 12.28 | nothing |
| [openregister/rights-administration-hardening](https://github.com/ConductionNL/openregister/issues/4395) | 12.23, 12.24, 12.26 | nothing |
| [openregister/scoped-api-tokens](https://github.com/ConductionNL/openregister/issues/4396) | 12.19 | nothing |
| [openregister/scoped-api-tokens-machine-callers](https://github.com/ConductionNL/openregister/issues/4411) | 12.20, 13.11 | [openregister/scoped-api-tokens](https://github.com/ConductionNL/openregister/issues/4396) |
| [openregister/search-dutch-language-quality](https://github.com/ConductionNL/openregister/issues/4397) | 6.32, 6.33 | nothing |
| [openregister/search-quality-operators-and-facets](https://github.com/ConductionNL/openregister/issues/4398) | 9.16, 9.19 | nothing |
| [openregister/upload-malware-scan](https://github.com/ConductionNL/openregister/issues/4399) | 1.19 | nothing |

## Wave 2

| change | rows | depends on |
|---|---|---|
| [openregister/published-file-digest](https://github.com/ConductionNL/openregister/issues/4400) | 11.7, 11.8 | nothing |
| [openregister/recompute-and-reindex-on-demand](https://github.com/ConductionNL/openregister/issues/4401) | 4.17, 9.15 | [openregister/search-quality-operators-and-facets](https://github.com/ConductionNL/openregister/issues/4398) |
| [openregister/redaction-policy-as-data](https://github.com/ConductionNL/openregister/issues/4402) | 18.1, 18.2, 18.3, 18.4, 18.5, 18.6, 18.7 | nothing |
| [openregister/seed-from-a-mounted-directory](https://github.com/ConductionNL/openregister/issues/4403) | 13.31 | nothing |
