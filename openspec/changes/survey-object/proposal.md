---
kind: code
depends_on: []
---

# Proposal: survey-object

## Summary

A satisfaction survey is a thing, not a rating field. It has questions,
people who may answer it, a rule about when it goes out, answers that are
read back, and an export. The fleet asks the question with one number on a
closed case, and the klanttevredenheidsonderzoek a gemeente actually runs
lives in a separate tool nobody links to anything.

## Candidates and cluster

Cluster 67 of `procest/_round4/discovery/build-plan.md`
(ConductionNL/market-intelligence, 2026-09-14), "The satisfaction survey as
its own object". Owner openregister, size M, decision D5, depends on the
intake form as its own object. Two candidates, both `should`, four passers
of which three driven. Proving system huly. The cluster's mechanism line:
"new openregister change `survey-object` carrying the questions, the
answers and the export; dossiq fires it per case type on closure and
portaliq renders it for a requester with no account (D5)".

| candidate | relevance | dossiq | what it asks |
|---|---|---|---|
| C-reporting-8 | should, documented | no | a survey as its own object with questions, access rules, automation and an export |
| C-reporting-31 | should | no | a satisfaction survey after closure, as its own object |

## The evidence, verbatim

Quoted from `procest/_round4/discovery/candidates.md`, best-evidence
column.

- **C-reporting-8**, relevance "should, the klanttevredenheidsonderzoek a
  gemeente already runs in a separate tool". Best evidence:
  "jira-service-management: Get started with surveys, Create and manage
  surveys, Manage survey access and restrictions, Use survey automation
  actions, Review and export survey data, Connect survey feedback to work
  items, Collect feedback from your organization with surveys". Documented,
  never counted in a driven tally (D21). Promoted as a D5 revival.
- **C-reporting-31**, "a satisfaction survey after closure". Three driven
  passers: glpi, huly and odoo. The candidate carries its own history:
  round 3 proposed it "with a reservation because GLPI was the only passer
  of six and a row with one passer is close to measuring a specification".
  Round 4 found three driven passers, which answers the reservation.

## Why openregister and not the app that asks the question

A survey is a schema, a set of objects, an access rule and an export. All
four already exist here. Putting the survey in the case app would give the
fleet one survey per app, each with its own questions and its own export,
and a schema slug is global per organisation, so the copies collide rather
than coexist.

`integration-forms` is the nearest neighbour and is not the answer. It
links Nextcloud Forms responses to objects, with editing delegated to the
Forms app and access inheriting from Forms. A survey sent to a requester
with no account cannot inherit Forms access, and a survey that reports per
case type needs its answers as objects rather than as linked responses.

## What openregister builds

- **A survey definition.** A title, an introduction, an ordered list of
  questions (scale, choice, free text), and the object type it is asked
  about.
- **A survey invitation.** One per subject and respondent, with a signed
  token, an expiry and a state. Answering requires no account, which is
  decision D5's point.
- **An answer set.** The respondent's answers as an object, linked to the
  subject it is about, readable through the ordinary access rules.
- **Automation.** A rule that fires an invitation on an event, with a delay
  and a fatigue rule so one person is not surveyed weekly.
- **Access rules.** Who may read the answers, and whether a respondent's
  identity is visible with them.
- **An export.** Answers as rows, with the questions as columns, through
  the existing export path.

## How the fleet consumes it

- **pipelinq**, `customer-satisfaction-closed-loop`, is the consumer. It
  carries ledger row 6.16 in the gap register, "surveys with invitations
  and fatigue rules are pipelinq's KTO". That change holds the campaign and
  the follow-up. This change holds the object it runs on.
- **dossiq** fires an invitation when a case of a type that wants one
  closes, and declares nothing else. Row 6.16 records dossiq's no as
  deliberate, opt in per case type.
- **portaliq** renders the survey for a requester with no account, under
  decision D5.

## Affected projects

- [x] `openregister`: this change.
- [ ] `pipelinq`: `customer-satisfaction-closed-loop`, the consumer.
      Unchanged here.
- [ ] `dossiq`: fires the invitation per case type on closure. Needs one
      declaration, not a change of its own scope.
- [ ] `portaliq`: renders the survey without an account. Its cluster 51,
      the intake form as its own object, is the dependency the build plan
      names.

## Out of scope

- The campaign, the reminder schedule and the reporting over time. Those
  are pipelinq's under row 6.16.
- Replacing `integration-forms`. A Nextcloud Form linked to an object stays
  exactly as it is.
