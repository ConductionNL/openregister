# flow-execution-history

## ADDED Requirements

### Requirement: Every node execution is recorded as a row

The system SHALL record one row per node execution in a native table
(`oc_openregister_flow_run_steps`), carrying at least the run it belongs to, the flow
id, the node id, the node type, its sequence in the walk, its status, when it started
and finished, its output, and its error when it failed. Per-node history SHALL be
queryable without loading or parsing a run's aggregate log.

#### Scenario: A completed run has one step row per node executed
- **WHEN** a flow of four connected nodes runs to completion
- **THEN** four step rows exist for that run, their sequence reflects the walk order, and each carries the output its node produced.

#### Scenario: A failing node is queryable by node and type
- **WHEN** one node of a run throws
- **THEN** its step row records status failed with the error message, the surrounding steps keep their own statuses, and the failed step is findable by querying on node id or node type without reading the run's log.

#### Scenario: A resumed run keeps its earlier steps
- **WHEN** a run suspends on a wait node and later resumes
- **THEN** the steps recorded before the suspension are still present, and the steps after it are appended rather than replacing them.

### Requirement: Flow log retention is an administrator setting

The system SHALL expose a flow-log retention period as an **administrator** app
setting, defaulting to **31 days**. It SHALL NOT be a personal setting. Runs and
their step rows older than the effective retention period SHALL be deleted by a
scheduled sweep.

#### Scenario: The default applies when unset
- **WHEN** no retention value has been configured
- **THEN** the effective retention period is 31 days.

#### Scenario: Expired history is swept
- **WHEN** the retention sweep runs and a completed run is older than the effective retention period
- **THEN** that run and all of its step rows are deleted.

#### Scenario: Retention is not personally configurable
- **WHEN** a non-administrator opens their personal settings
- **THEN** no flow-log retention control is offered.

### Requirement: Retention is overridable per flow, in both directions

A flow MAY declare its own retention period, which SHALL take precedence over the
administrator default for that flow's runs. The override SHALL be permitted to be
both **shorter** and **longer** than the default. A flow that declares no override
SHALL follow the administrator setting, and SHALL continue to follow it when that
setting later changes.

#### Scenario: A shorter override is honoured
- **WHEN** the administrator default is 31 days and a flow declares 7
- **THEN** that flow's runs are swept once they exceed 7 days, while other flows' runs survive to 31.

#### Scenario: A longer override is honoured
- **WHEN** the administrator default is 31 days and a flow declares 365
- **THEN** that flow's runs survive past 31 days and are swept once they exceed 365.

#### Scenario: An unset override tracks the administrator setting
- **WHEN** a flow declares no override and the administrator changes the default from 31 to 14
- **THEN** that flow's runs are thereafter swept at 14 days without editing the flow.
