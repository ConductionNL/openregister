import json, sys, collections
d, app = sys.argv[1] + '/', sys.argv[2]


def load(fn):
    try:
        j = json.load(open(d + fn))
    except Exception as e:
        print('bad json', fn, e)
        return {}
    out = {}
    for f, v in j.get('files', {}).items():
        key = 'lib/' + f.split('/lib/', 1)[-1]
        out[key] = collections.Counter((m['source'], m['message']) for m in v['messages'] if m['type'] == 'ERROR')
    return out


n = load(f'cs-new-{app}.json')
o = load(f'cs-old-{app}.json')
tot = 0
for f, c in n.items():
    diff = c - o.get(f, collections.Counter())
    for (src, msg), k in diff.items():
        tot += k
        print(f'NEW {k} {f}: {src} {msg[:140]}')
print('new phpcs errors:', tot)
