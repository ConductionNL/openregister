#!/usr/bin/env python3
"""Clean pattern definition: whole directories by the approved map's majority target, and for
the flat layer directories (Controller, Db, Service root, Listener, BackgroundJob, Command,
Repair, Event, Exception) a filename-prefix regex per component derived from the map.
Usage: python3 build_components_clean.py <clone> components-v2-clean.yaml assignment-v2-clean.json"""
import sys, os, csv, json, re, collections
app = os.path.expanduser(sys.argv[1]); loc = json.load(open("exposed.json"))["loc"]
rows = list(csv.DictReader(open(f"{app}/scripts/component-map.csv")))
GROUP = {"Service": "Core", "Objects": "Core", "Schemas": "Core", "Access": "Core", "Db": "Core", "Controller": "Core",
         "Flows": "Processes", "Work": "Processes", "WorkflowEngine": "Processes", "Mcp": "Integrations",
         "AppInfo": "Platform", "Support": "Kernel", "Formats": "Kernel", "Lifecycle": "Kernel", "Dto": "Kernel",
         "Contract": "Kernel", "Kernel": "Kernel", "Event": "Kernel", "Exception": "Kernel"}
seed = {}
for r in rows:
    for f in ("lib/" + r["file"], "lib/" + r["target_path"]):
        if f in loc: seed[f] = GROUP.get(r["target_component"], r["target_component"])
# directory majority
dirs = collections.defaultdict(collections.Counter)
for m, c in seed.items(): dirs[os.path.dirname(m)][c] += 1
FLAT = {"lib/Controller", "lib/Controller/Support", "lib/Db", "lib/Service", "lib/Listener", "lib/BackgroundJob", "lib/Command", "lib/Repair", "lib/Event", "lib/Exception", "lib/BulkAction"}
TOPDIR = {"lib/Files": "Files", "lib/Search": "Search", "lib/Notifications": "Notifications", "lib/Mcp": "Integrations", "lib/AppHost": "Integrations",
          "lib/Kernel": "Kernel", "lib/Contract": "Kernel", "lib/Dto": "Kernel", "lib/Support": "Kernel", "lib/Formats": "Kernel", "lib/Lifecycle": "Kernel",
          "lib/Middleware": "Platform", "lib/AppInfo": "Platform", "lib/Capabilities": "Platform", "lib/Dashboard": "Platform", "lib/Reference": "Platform",
          "lib/Sections": "Platform", "lib/Settings": "Platform", "lib/Activity": "Notifications", "lib/Notification": "Notifications", "lib/Push": "Notifications",
          "lib/Calendar": "Integrations", "lib/Contacts": "Integrations", "lib/Dav": "Integrations", "lib/Federation": "Integrations", "lib/ContextChat": "Integrations",
          "lib/Twig": "Integrations", "lib/WorkflowEngine": "Processes", "lib/BulkAction": "Processes", "lib/Db/MagicMapper": "Core", "lib/Db/SchemaMapper": "Core",
          "lib/Db/Schema": "Core", "lib/Db/Tenancy": "Core", "lib/Db/Register": "Core", "lib/Db/AuditTrail": "Compliance", "lib/Db/SearchTrail": "Search"}
def dir_target(d):
    for top, c in TOPDIR.items():
        if d == top or d.startswith(top + "/"): return c
    if dirs.get(d): return dirs[d].most_common(1)[0][0]
    parent = os.path.dirname(d)
    return dir_target(parent) if parent != "lib" else None
assign = {}; flat = collections.defaultdict(list)
for m in loc:
    d = os.path.dirname(m)
    if d in FLAT and not d.startswith("lib/Controller/Support"):
        flat[d].append(m)
    else:
        assign[m] = dir_target(d) or "Core"
# flat files: seed target, else a sibling with the same name prefix, else Core
PREFIX_RULES = [  # (regex on basename, component) applied in order for unseeded flat files
 (r"^(Flow|Bpmn|Dmn|Decision|Task|Case|Form|Rule|Lifecycle|Transition|Handoff|BulkJob|Macro|ObjectAction|Operation|Survey|Hinge|Intake|Deferr|Timer|Run|Workflow|Approval|Calculation|Sequence)", "Processes"),
 (r"^(Configuration|Config|Import|Export|ExampleSet|Baseline|MigrationPack|Setting|ApiVersion|Deployment|Seed|Install)", "Configuration"),
 (r"^(Archiv|Edepot|Retention|Destruction|Gdpr|Subject|Deletion|Audit|Anonymis|Consent|Quality|LegalHold|Purpose|Dedup)", "Compliance"),
 (r"^(Mcp|Agent|Conversation|Chat|GraphQL|Dav|Calendar|Contact|Federation|AppHost|Integration|Leaf|Webhook|Outbound|Consumer|Sync|Talk|ExternalLink|Portal|WellKnown|Twig|Mapping|Application|Endpoint|ApiCall|Cospend|Deck|Collective|Bookmark|Analytics|Email|Company|Github|GitHub)", "Integrations"),
 (r"^(File|Folder|Upload|TextExtraction|Chunk|Vector|Pdf|Ocr|Attachment)", "Files"),
 (r"^(Search|Facet|Aggregation|Report|View|Duplicate|Index|Timeseries|Geo)", "Search"),
 (r"^(Notification|Notifier|Timeline|Watcher|Push|Activity|Message|Mention|Note|Party|Feedback|ScheduledReport|Email)", "Notifications"),
 (r"^(Capabilit|Dashboard|Reference|Section|Middleware|Language|Translation|Quota|Metrics|Health|Operations|Console|Deprecation|Feature)", "Platform"),
]
for d, ms in flat.items():
    for m in ms:
        if m in seed: assign[m] = seed[m]; continue
        b = os.path.basename(m)
        for rx, c in PREFIX_RULES:
            if re.match(rx, b): assign[m] = c; break
        else: assign[m] = "Core"
# emit: directory patterns per component for non-flat dirs; per flat dir, one alternation regex per component over the file stems
def esc(p): return re.sub(r"([.\[\]()+?^$])", lambda mm: "[" + mm.group(1) + "]", p)
order = ["Core", "Processes", "Configuration", "Compliance", "Integrations", "Files", "Search", "Notifications", "Platform", "Kernel"]
pats = collections.defaultdict(list)
bydir = collections.defaultdict(lambda: collections.defaultdict(list))
for m, c in assign.items(): bydir[os.path.dirname(m)][c].append(m)
for d, cs in sorted(bydir.items()):
    if d in FLAT:
        for c, ms in cs.items():
            stems = sorted(os.path.basename(m)[:-4] for m in ms)
            # compress stems with a common capitalised prefix group where the whole group is one component
            pats[c].append(f'.*/{esc(d)}/({"|".join(stems)})[.]php')
    else:
        for c, ms in cs.items():
            if len(cs) == 1: pats[c].append(f".*/{esc(d)}/[^/]*")
            else:
                for m in ms: pats[c].append(f".*/{esc(m)}")
out = ["# Functional components over the Nextcloud layered layout (ADR-012 v2, clean variant).",
       "# Generated by build_components_clean.py: whole directories where a directory belongs to one",
       "# component; one alternation per component for the flat layer directories.", "components:"]
for c in order:
    out.append(f"  - name: {c}"); out.append("    include:")
    for p in pats[c]: out.append(f'      - "{p}"')
open(sys.argv[2], "w").write("\n".join(out) + "\n")
json.dump(assign, open(sys.argv[3], "w"), indent=0)
n = collections.Counter(assign.values()); l = collections.Counter()
for m, c in assign.items(): l[c] += loc[m]
print("patterns:", sum(len(v) for v in pats.values()), {c: len(pats[c]) for c in order})
for c in order: print(f"  {c:14} files={n[c]:5} loc={l[c]:7}")
