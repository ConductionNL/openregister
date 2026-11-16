R = {
 'tests/Unit/Repair/ArmTermijnEngineTimersBranchesTest.php': [('parent::find($id, $_extend, $files, $register, $schema)', 'parent::find($id, $options)')],
 'tests/Unit/Service/CaseDefinition/PackageInstallerTest.php': [('parent::saveObject($object, $register, $schema, $uuid, $_rbac, $_multitenancy)', 'parent::saveObject($object, $options, $_rbac, $_multitenancy)')],
 'tests/Unit/Service/Money/AccruingAmountSettlerTest.php': [('parent::saveObject($object, $extend, $register, $schema, $uuid, $_rbac, $_multitenancy, $silent, $_validation)', 'parent::saveObject($object, $options, $_rbac, $_multitenancy)')],
 'tests/Unit/Service/Money/AmountObjectionServiceFailuresTest.php': [('parent::saveObject($object, $extend, $register, $schema, $uuid, $_rbac, $_multitenancy, $silent, $_validation)', 'parent::saveObject($object, $options, $_rbac, $_multitenancy)')],
 'tests/Unit/Service/Money/PayoutServiceGoldenTest.php': [('parent::find($id, $_extend, $files, $register, $schema)', 'parent::find($id, $options)'), ('parent::saveObject($object, $extend, $register, $schema, $uuid, $_rbac, $_multitenancy, $silent, $_validation)', 'parent::saveObject($object, $options, $_rbac, $_multitenancy)')],
 'tests/Unit/Service/CaseType/CaseVersionMoveTest.php': [('$this->inner->find($id, $register, $schema)', '$this->inner->find($id, $options)')],
 'tests/Unit/Service/Support/BackgroundReadsRunAsSystemTest.php': [('parent::find($id, $register, $schema)', 'parent::find($id, $options)')],
 'tests/Unit/Service/People/CaseRoleProjectionTest.php': [('$this->inner->find($id, $register, $schema)', '$this->inner->find($id, $options)'), ('$this->inner->saveObject($object, $register, $schema, $uuid)', '$this->inner->saveObject($object, $options)'), ('$this->inner->deleteObject($uuid, $register, $schema)', '$this->inner->deleteObject($deletion)')],
 'tests/Unit/Service/Intake/IntakeSourceSeederSeedTest.php': [('$this->inner->saveObject($register, $schema, $object, $uuid)', '$this->inner->saveObject($object, $options)')],
}
import os
os.chdir('/tmp/lanes/ormig/dossiq')
for p, reps in R.items():
    s = open(p).read()
    for a, b in reps:
        if a not in s: print('MISSING', p, a); continue
        s = s.replace(a, b)
    open(p, 'w').write(s)
import re
def add_use(s, fq):
    if re.search(r'^use ' + re.escape(fq) + r';', s, re.M): return s
    ms = list(re.finditer(r'^use [^;]+;[ \t]*$', s, re.M)); line = 'use ' + fq + ';'
    return s[:ms[-1].end()] + '\n' + line + s[ms[-1].end():]
for p in ['tests/Unit/Service/Flow/RetiredActionRunnerTest.php', 'tests/Unit/Service/Transitions/SideEffectDispatcherFallbackTest.php', 'tests/Unit/Service/Transitions/SideEffectDispatcherTest.php']:
    s = open(p).read()
    s = s.replace('createMock(IFlowNode::class)', 'createMock(ICallFlowNode::class)')
    s = add_use(s, 'OCA\\OpenRegister\\Service\\Flow\\ICallFlowNode')
    open(p, 'w').write(s)
import re as _re
def _sub(p, pat, rep, count=0, flags=0):
    s = open(p).read(); s2, n = _re.subn(pat, rep, s, count=count, flags=flags)
    if n == 0: print('NOSUB', p, pat[:60])
    open(p, 'w').write(s2)
# FormDependent: id/app/title (and audience) move into PublishedForm.
_sub('tests/Unit/AppInfo/BoundFormsRegistrationTest.php', r"new FormDependent\(\n(\s*)id: \$id,\n\s*app: \$app,\n\s*title: ('Formulier ' \. \$id),\n", r"new FormDependent(\n\1form: new PublishedForm(id: $id, app: $app, title: \2),\n")
_sub('tests/Unit/Service/Intake/BoundFormsCheckAssessTest.php', r"new FormDependent\(\n(\s*)id: \$id,\n\s*app: \$app,\n\s*title: ('Form ' \. \$id),\n(\s*)author: 'jan',\n\s*mapping: \$mapping,\n\s*audience: \$audience\n", r"new FormDependent(\n\1form: new PublishedForm(id: $id, app: $app, title: \2, audience: $audience),\n\3author: 'jan',\n\3mapping: $mapping\n")
for _p in ['tests/Unit/AppInfo/BoundFormsRegistrationTest.php', 'tests/Unit/Service/Intake/BoundFormsCheckAssessTest.php']:
    _s = open(_p).read(); _s = add_use(_s, 'OCA\\OpenRegister\\Service\\Form\\PublishedForm'); open(_p, 'w').write(_s)
# FormDependentsCheck takes its dispatcher and notification manager as one FormDependentsSignals.
_sub('tests/Unit/AppInfo/BoundFormsRegistrationTest.php', r"new FormDependentsCheck\(\$dispatcher, \$validator, \$appConfig, \$notifications, \$logger\)", "new FormDependentsCheck(new FormDependentsSignals(dispatcher: $dispatcher, notifications: $notifications), $validator, $appConfig, $logger)")
_s = open('tests/Unit/AppInfo/BoundFormsRegistrationTest.php').read(); _s = add_use(_s, 'OCA\\OpenRegister\\Service\\Form\\FormDependentsSignals'); open('tests/Unit/AppInfo/BoundFormsRegistrationTest.php', 'w').write(_s)
# FormDestinationValidator::validate(mapping, FormDestination); the destination holds a Schema entity.
for _p in ['tests/Unit/Service/Intake/IntakeFormBindingCheckTest.php', 'tests/Unit/Service/Intake/IntakeFormBindingCheckMappingTest.php', 'tests/Unit/AppInfo/IntakeFormBindingRegistrationTest.php']:
    _s = open(_p).read()
    _s, _n = _re.subn(r"public function validate\(array \$mapping, object \$schema, array \$options = \[\]\): (\w+) \{\n(\s*)",
        lambda m: f"public function validate(array $mapping, FormDestination $destination): {m.group(1)} {{\n{m.group(2)}$schema = $destination->schema;\n{m.group(2)}$options = ($destination->audience === null) ? [] : ['audience' => $destination->audience];\n{m.group(2)}", _s)
    _s, _m = _re.subn(r"return \(object\)\['id' => \$id", "return SchemaRow::of(['id' => $id", _s)
    _s = _re.sub(r"(return SchemaRow::of\(\['id' => \$id[^;]*\]);", r"\1);", _s)
    _s = add_use(_s, 'OCA\\OpenRegister\\Service\\Form\\FormDestination'); _s = add_use(_s, 'OCA\\Dossiq\\Tests\\Support\\SchemaRow')
    print('INTAKE', _p, _n, _m)
    open(_p, 'w').write(_s)
# FlowEmailSentEvent(item, message, origin).
_p = 'tests/Unit/Listener/FlowEmailSentListenerTest.php'
_sub(_p, r"new FlowEmailSentEvent\(\n(\s*)register: '12',\n\s*schema: \$schema,\n\s*objectUuid: 'case-1',\n\s*recipient: \$recipient,\n\s*channelKind: \$kind,\n\s*subject: 'Uw aanvraag',\n\s*body: 'Wij hebben uw aanvraag ontvangen.',\n\s*flowId: 'flow-1',\n\s*runId: 'run-1',\n\s*stepName: 'mail-indiener',\n\s*actingUser: 'jan'\n",
     r"new FlowEmailSentEvent(\n\1item: new FlowEmailItem(register: '12', schema: $schema, objectUuid: 'case-1'),\n\1message: new FlowEmailMessage(recipient: $recipient, channelKind: $kind, subject: 'Uw aanvraag', body: 'Wij hebben uw aanvraag ontvangen.'),\n\1origin: new FlowEmailOrigin(flowId: 'flow-1', runId: 'run-1', stepName: 'mail-indiener', actingUser: 'jan')\n")
_s = open(_p).read()
for _c in ['FlowEmailItem', 'FlowEmailMessage', 'FlowEmailOrigin']: _s = add_use(_s, 'OCA\\OpenRegister\\Event\\' + _c)
open(_p, 'w').write(_s)
_sub('tests/Unit/AppInfo/BoundFormsRegistrationTest.php', r"(form: new PublishedForm\(id: \$id, app: \$app, title: 'Formulier ' \. \$id)\),((?:.|\n)*?)\n\s*audience: 'public',\n", r"\1, audience: 'public'),\2\n", count=1)
# The recorded find() now carries only the values the caller set; the release defaults are not passed.
_sub('tests/Unit/Controller/PublicCaseSurvivorControllerTest.php', r"\['find' => \['id' => 'new-1', '_extend' => \[\], 'files' => false, 'register' => '5', 'schema' => '12', '_rbac' => true, '_multitenancy' => true\]\]", "['find' => ['id' => 'new-1', 'register' => '5', 'schema' => '12']]")
_sub('tests/Unit/Service/WorkQueueServiceTest.php', r"return parent::find\(\$id, \$register, \$schema\);", "return parent::find($id, $options);")
_sub('tests/Unit/BackgroundJob/PauseChaseJobServiceAccountTest.php', r"\(string\)\(\$rest\['schema'\] \?\? ''\)", "(string)($patch->target->schema ?? '')")
_sub('tests/Unit/Service/Intake/IntakeFormBindingCheckMappingTest.php', r"actual: \(array\)\$this->asked\[0\]\['schema'\]\)", "actual: ['id' => $this->asked[0]['schema']->id, 'rbac' => $this->asked[0]['schema']->rbac, 'tenancy' => $this->asked[0]['schema']->tenancy])")
_sub('tests/Unit/Service/Task/EngineTaskGatewayTest.php', r"->with\(\n(\s*)'task-1',\n\s*\$this->callback\(static fn \(TaskAnswer \$answer\): bool => \$answer->outcome === 'approved'\),\n\s*\['verslag' => 'klaar'\],\n\s*'behandelaar'\n(\s*)\)\n(\s*)->willReturn\(new \\stdClass\(\)\);",
     r"->with(\n\1$this->callback(static fn (FormCompletionRequest $request): bool => $request->uuid === 'task-1' && $request->answer->outcome === 'approved' && $request->data === ['verslag' => 'klaar']),\n\1'behandelaar'\n\2)\n\3->willReturn(new Task());")
_s = open('tests/Unit/Service/Task/EngineTaskGatewayTest.php').read(); _s = add_use(_s, 'OCA\\OpenRegister\\Service\\Task\\FormCompletionRequest'); _s = add_use(_s, 'OCA\\OpenRegister\\Db\\Task'); open('tests/Unit/Service/Task/EngineTaskGatewayTest.php', 'w').write(_s)
_sub('tests/Unit/Controller/DrcControllerContractTest.php', r"->method\('unlockObject'\)\n(\s*)->with\('doc-1'\)", r"->method('unlockObject')\n\1->with($this->callback(static fn (UnlockRequest $request): bool => $request->identifier === 'doc-1'))")
_s = open('tests/Unit/Controller/DrcControllerContractTest.php').read(); _s = add_use(_s, 'OCA\\OpenRegister\\Kernel\\Contracts\\Value\\UnlockRequest'); open('tests/Unit/Controller/DrcControllerContractTest.php', 'w').write(_s)
# Reflection-recording doubles: record OpenRegister's option objects as the named values they carry.
import subprocess
for _p in ['tests/Unit/Service/DsoCaseServiceGoldenTest.php', 'tests/Unit/Service/Bezwaar/DecisionServiceGoldenTest.php']:
    _s = open(_p).read()
    if 'LegacyObjectArgs::flatten(args: $named)' in _s: continue
    _s2 = _re.sub(r"(\n(\s*)\$short = substr\(\$class, \(strrpos\(\$class, '\\\\'\) \+ 1\)\);)", r"\n\2$named = LegacyObjectArgs::flatten(args: $named);\1", _s, count=1)
    if _s2 == _s: print('NOFLAT', _p); continue
    _s2 = add_use(_s2, 'OCA\\Dossiq\\Tests\\Support\\LegacyObjectArgs')
    open(_p, 'w').write(_s2)
# Variadic mocked saveObject(...$args) receives named arguments.
_sub('tests/Unit/Service/SubstitutionServiceTest.php', r"LegacyObjectArgs::positional\(method: 'saveObject', args: \$args\)", "LegacyObjectArgs::flatten(args: $args)")
_sub('tests/Unit/Controller/DrcEioUpdateGoldenTest.php', r"\$this->assertSame\(\['register' => 'zgw', 'schema' => 'eio', 'object' => \['title' => 'Nota', 'locked' => true, 'lockId' => 'L-1'\], 'uuid' => 'doc-1'\], \$save\[1\]\);", "$this->assertSame(['object' => ['title' => 'Nota', 'locked' => true, 'lockId' => 'L-1'], 'register' => 'zgw', 'schema' => 'eio', 'uuid' => 'doc-1'], $save[1]);")
