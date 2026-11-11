# flow-and-run-detail-pages Specification

## Purpose
A person who looks after a flow can see what it does, whether it works,
and what each run did, without opening the editor. The flow gets an
overview page; a run gets a page at its own address.

## Requirements

### Requirement: A flow has an overview page that the index opens

OpenRegister SHALL serve a flow overview at `/flows/{id}/overview`. A row
click in the `/flows` index SHALL open the overview, not the editor. The
editor SHALL stay at `/flows/{id}`, including `/flows/new` and
`/flows/{id}?run={uuid}`.

The overview SHALL show the flow's name, description, version, lifecycle
status, whether it is enabled, and its app. It SHALL offer "Open in
editor", "Run now" and enable or disable. It SHALL list the steps in
graph order, starting from the nodes nothing points at. It SHALL show
how the flow runs (trigger, what it watches, execution mode, owner) and
its versions.

#### Scenario: A row click opens the overview

- **GIVEN** the `/flows` index
- **WHEN** a person clicks a flow row
- **THEN** the router MUST navigate to the route named `flow-overview`
  with the flow id
- @e2e exclude the manifest value and the route registration are asserted
  in src/views/flows/flowDetail.spec.js; the Playwright journey is task 5.2

#### Scenario: Steps follow the graph, not the storage order

- **GIVEN** a flow whose nodes are stored out of order and whose edges run
  trigger, filter, write
- **WHEN** the overview lists the steps
- **THEN** they MUST read trigger, filter, write
- @e2e exclude a pure derivation, covered by the jest spec

### Requirement: The overview summarises the run history it loaded

The overview SHALL read the flow's runs from `GET /api/flow-runs` and
show: the last run with its status, time and error; how many of the
loaded runs completed, failed and stopped; and the average run duration
as the mean of each run's summed step durations. A figure the loaded
runs cannot support SHALL be left out, not shown as zero.

The runs table SHALL filter on all, failed, completed and running, where
running means queued, running or suspended. Each row SHALL link to the
run's own page and, for a failed run, name the step it stopped at.

#### Scenario: Health reads the loaded runs

- **GIVEN** eleven loaded runs: one completed, nine failed, one stopped
- **WHEN** the overview renders its health cards
- **THEN** it MUST read "1 of 11 completed" and "9 failed, 1 stopped"
- @e2e exclude a pure derivation, covered by the jest spec

#### Scenario: No step durations means no average

- **GIVEN** loaded runs whose logs carry no `durationMs`
- **WHEN** the overview renders
- **THEN** the average duration card MUST be absent
- @e2e exclude a pure derivation, covered by the jest spec

### Requirement: A run has its own page

`/flow-runs/{uuid}` SHALL render the run, not redirect to the editor. The
page SHALL show the run's status, flow version and start time; a summary
(started, ended for a finished run, duration, trigger, who started it,
who it ran as, the subject, the parent run and the correlation key when
present); and tabs for steps, objects, tasks, context and the raw log.
Each step SHALL show its status, items in and out, duration, and its
input, output and error when recorded.

A run that does not resolve SHALL say so at its own address.

#### Scenario: A failed run names the step it stopped at

- **GIVEN** a failed run whose log records a failed `object-read` step
- **WHEN** the run page renders
- **THEN** an alert MUST name that step's label and its position
- **AND** show the step's error
- @e2e exclude a pure derivation plus a load method, covered by the jest
  spec

#### Scenario: A failed run without a step log still shows its error

- **GIVEN** a failed run with an error and an empty log
- **WHEN** the run page renders
- **THEN** the alert MUST show the run's error without naming a step
- @e2e exclude covered by the jest spec

#### Scenario: An unknown run says so

- **GIVEN** a run uuid the API answers with 404
- **WHEN** the run page loads
- **THEN** it MUST show "No such run" and MUST NOT navigate away
- @e2e exclude covered by the jest spec

### Requirement: Run actions follow what the API accepts

The run page SHALL offer "Retry" only for a finished run (completed,
stopped, failed, dead letter) and "Resume" only for a suspended run, the
same states `POST /api/flow-runs/{uuid}/retry` and `/resume` accept. A
retry SHALL open the new run's page. "Show on the canvas" SHALL open the
editor at `/flows/{flowId}?run={uuid}`.

#### Scenario: Retry opens the new run

- **GIVEN** a failed run
- **WHEN** a person clicks "Retry" and the API answers with a new run
- **THEN** the page MUST navigate to that new run's address
- @e2e exclude covered by the jest spec

#### Scenario: Resume is not offered on a finished run

- **GIVEN** a completed run
- **WHEN** the run page renders
- **THEN** "Resume" MUST NOT be offered
- @e2e exclude covered by the jest spec
