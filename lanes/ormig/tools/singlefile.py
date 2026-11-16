import os, re, shutil, sys
# Apps whose OpenRegister stubs are single files: add main's value objects (autoload fallback) and ICallFlowNode.
app, bootstrap, flowstub = sys.argv[1], sys.argv[2], (sys.argv[3] if len(sys.argv) > 3 else '')
os.chdir('/tmp/lanes/ormig/' + app)
OR = '/tmp/lanes/ormig/openregister/lib/Kernel/Contracts/Value/'
d = 'tests/stubs/OpenRegister/Kernel/Contracts/Value/'
os.makedirs(d, exist_ok=True)
for c in ['ObjectFindOptions', 'ObjectSaveOptions', 'ObjectFindTarget', 'SavePolicy', 'SaveActor', 'ObjectDeletion', 'ObjectPatch', 'LockRequest', 'LockRunStep', 'UnlockRequest', 'BulkSaveOptions', 'BulkBatchDefaults']:
    shutil.copy(OR + c + '.php', d)
s = open(bootstrap).read()
if 'stubs/OpenRegister/Kernel/Contracts/Value' not in s:
    s = s.rstrip('\n') + """

// OpenRegister's object-service option objects, from main, when OpenRegister itself is absent.
spl_autoload_register(
	static function (string $class): void {
		$prefix = 'OCA\\\\OpenRegister\\\\Kernel\\\\Contracts\\\\Value\\\\';
		if (str_starts_with($class, $prefix) === true) {
			$file = __DIR__ . '/stubs/OpenRegister/Kernel/Contracts/Value/' . substr($class, strlen($prefix)) . '.php';
			if (is_file($file) === true) {
				require_once $file;
			}
		}
	}
);
"""
    open(bootstrap, 'w').write(s)
if flowstub:
    s = open(flowstub).read()
    if 'interface ICallFlowNode' not in s:
        i = s.index('final class FlowNodeCall {')
        j = s.rfind('/**', 0, i)
        ind = re.match(r'[ \t]*', s[s.rfind('\n', 0, j) + 1:]).group(0)
        block = """/**
 * A flow node in main's call form: execute() takes one FlowNodeCall.
 */
interface ICallFlowNode extends IFlowNode {
	/**
	 * Run the node.
	 *
	 * @param FlowNodeCall $call The items, the step config and the run context.
	 *
	 * @return array<int, array<string, mixed>> The resulting items.
	 */
	public function execute(FlowNodeCall $call): array;
}

"""
        s = s[:j] + block + s[j:]
        open(flowstub, 'w').write(s)
print('ok')
