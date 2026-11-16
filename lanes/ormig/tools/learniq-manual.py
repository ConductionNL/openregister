import os
os.chdir('/tmp/lanes/ormig/learniq')
p = 'tests/bootstrap-unit.php'; s = open(p).read()
if 'Support/LegacyObjectArgs.php' not in s:
    s = s.rstrip('\n') + "\n\n// Test helpers for OpenRegister's option objects.\nrequire_once __DIR__ . '/Support/ReleaseObjectSignatures.php';\nrequire_once __DIR__ . '/Support/LegacyObjectArgs.php';\n"
    open(p, 'w').write(s)
import subprocess
print(subprocess.run(['python3', '/tmp/lanes/ormig/tools/learniq-manual2.py'], capture_output=True, text=True).stdout)
