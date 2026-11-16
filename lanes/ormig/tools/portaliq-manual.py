import subprocess, os
os.chdir('/tmp/lanes/ormig/portaliq')
files = subprocess.run('grep -rlE "ChangeProposalsProvider|PaymentRequestLeafProvider" tests --include=*.php', shell=True, capture_output=True, text=True).stdout.split()
print(subprocess.run(['php', '/tmp/lanes/ormig/tools/refcall.php', r'/provider|Provider/'] + files, capture_output=True, text=True).stdout[-300:])
import re as _re
def _sub(p, pat, rep, count=0):
    s = open(p).read(); s2, n = _re.subn(pat, rep, s, count=count)
    if n == 0: print('NOSUB', p, pat[:60])
    open(p, 'w').write(s2)
def add_use(s, fq):
    if _re.search(r'^use ' + _re.escape(fq) + r';', s, _re.M): return s
    ms = list(_re.finditer(r'^use [^;]+;[ \t]*$', s, _re.M)); line = 'use ' + fq + ';'
    return s[:ms[-1].end()] + '\n' + line + s[ms[-1].end():]
# A __call() double receives the named arguments; record the values the option objects carry.
_p = 'tests/Unit/Controller/StaffAuthoringGoldenTest.php'
_sub(_p, r"(public function __call\(string \$name, array \$args\): mixed \{\n(\t+))", lambda m: m.group(1) + "$args = LegacyObjectArgs::flatten(args: $args);\n" + m.group(2), count=1)
_s = open(_p).read(); _s = add_use(_s, 'OCA\\Portaliq\\Tests\\Support\\LegacyObjectArgs'); open(_p, 'w').write(_s)
# The store passes no explicit null uuid any more.
_sub('tests/Unit/Service/Availability/AvailabilityStoreRecordsTest.php', r"'schema' => 'portalAvailabilityDaily', 'uuid' => null, '_rbac' => false", "'schema' => 'portalAvailabilityDaily', '_rbac' => false")
# TrafficEventStore's fallback saveObjects() call stays in the release form (main has no enrich/deduplicateIds), so its double does too.
_p = 'tests/Unit/Service/Traffic/TrafficEventStoreTest.php'
_head = subprocess.run(['git', 'show', 'HEAD:' + _p], capture_output=True, text=True).stdout
_m = _re.search(r"\t\t\tpublic function saveObjects\(\n\t\t\t\tarray \$objects,\n\t\t\t\t\?string \$register.*?\n\t\t\t\}\n", _head, _re.S)
_s = open(_p).read()
_s2, _n = _re.subn(r"\t\t\tpublic function saveObjects\(\n\t\t\t\tarray \$objects,\n\t\t\t\tBulkSaveOptions.*?\n\t\t\t\}\n", lambda m: _m.group(0), _s, count=1, flags=_re.S)
print('TRAFFIC', _n)
open(_p, 'w').write(_s2)
