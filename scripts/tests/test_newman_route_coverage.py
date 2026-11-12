"""Unit tests for scripts/newman-route-coverage.py.

Run: python3 -m unittest discover -s scripts/tests -p 'test_newman_route_coverage.py'

Every test plants a tiny repo in a temp dir: a routes list and a few
collections under tests/integration, then measures it.
"""
import importlib.util
import io
import json
import os
import shutil
import tempfile
import unittest
from contextlib import redirect_stderr, redirect_stdout

HERE = os.path.dirname(os.path.abspath(__file__))
SPEC = importlib.util.spec_from_file_location('nrc', os.path.join(HERE, '..', 'newman-route-coverage.py'))
nrc = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(nrc)

ROUTES = [
    {'name': 'objects#index', 'url': '/api/objects/{register}/{schema}', 'verb': 'GET', 'req': {}},
    {'name': 'objects#show', 'url': '/api/objects/{register}/{schema}/{id}', 'verb': 'GET', 'req': {}},
    {'name': 'registers#destroy', 'url': '/api/registers/{id}', 'verb': 'DELETE', 'req': {}},
    {'name': 'files#path', 'url': '/api/files/{path}', 'verb': 'GET', 'req': {'path': '.+'}},
    {'name': 'excluded#only', 'url': '/api/only-in-excluded', 'verb': 'POST', 'req': {}},
]


def request(method, raw):
    return {'name': raw, 'request': {'method': method, 'url': {'raw': raw}}}


def collection(items, description=None, events=None):
    info = {'name': 'planted', 'schema': 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'}
    if description is not None:
        info['description'] = description
    return {'info': info, 'item': items, 'event': events or []}


class NewmanRouteCoverageTest(unittest.TestCase):
    def setUp(self):
        self.repo = tempfile.mkdtemp()
        self.integration = os.path.join(self.repo, 'tests', 'integration')
        os.makedirs(self.integration)
        self.routes_path = os.path.join(self.repo, 'routes.json')
        with open(self.routes_path, 'w') as fh:
            json.dump(ROUTES, fh)

    def tearDown(self):
        shutil.rmtree(self.repo)

    def plant(self, name, data):
        with open(os.path.join(self.integration, name + '.postman_collection.json'), 'w') as fh:
            json.dump(data, fh)

    def run_ci(self):
        reqs = nrc.collect_requests(self.repo, ci_only=True)
        return nrc.measure(ROUTES, reqs, ci_only=True)

    def covered(self, res):
        return {c['name'] for c in res['covered_list']}

    def test_known_hit_with_variable_segments(self):
        # {{baseUrl}} prefix, a {{var}} segment and a :param segment are all variables.
        self.plant('hit', collection([
            request('GET', '{{baseUrl}}/index.php/apps/openregister/api/objects/{{register}}/:schema?_limit=1'),
        ]))
        res = self.run_ci()
        self.assertEqual({'objects#index'}, self.covered(res))
        self.assertEqual(['tests/integration/hit.postman_collection.json'], res['covered_list'][0]['by'])

    def test_miss_on_wrong_verb_and_extra_segment(self):
        self.plant('miss', collection([
            request('POST', '{{baseUrl}}/index.php/apps/openregister/api/objects/1/2'),       # wrong verb
            request('GET', '{{baseUrl}}/index.php/apps/openregister/api/registers/1'),        # wrong verb
            request('GET', '{{baseUrl}}/index.php/apps/openregister/api/objects/1/2/3/4'),    # too many segments
        ]))
        res = self.run_ci()
        self.assertEqual(set(), self.covered(res))
        self.assertEqual(5, len(res['uncovered']))

    def test_single_segment_param_does_not_swallow_a_slash_but_a_dotplus_one_does(self):
        self.plant('slash', collection([
            request('GET', '/index.php/apps/openregister/api/files/a/b/c.txt'),
            request('GET', '/index.php/apps/openregister/api/objects/r/s/x/y'),
        ]))
        res = self.run_ci()
        self.assertEqual({'files#path'}, self.covered(res))

    def test_excluded_collection_is_skipped_only_in_ci_mode(self):
        self.plant('excluded', collection(
            [request('POST', '{{baseUrl}}/index.php/apps/openregister/api/only-in-excluded')],
            description='Needs a mail server. @newman exclude requires SMTP',
        ))
        self.assertEqual(set(), self.covered(self.run_ci()))
        all_res = nrc.measure(ROUTES, nrc.collect_requests(self.repo, ci_only=False))
        self.assertEqual({'excluded#only'}, self.covered(all_res))

    def test_exclude_marker_without_reason_does_not_exclude(self):
        self.plant('noreason', collection(
            [request('POST', '/index.php/apps/openregister/api/only-in-excluded')],
            description={'content': '@newman exclude'},
        ))
        self.assertEqual({'excluded#only'}, self.covered(self.run_ci()))

    def test_send_request_in_a_script_counts(self):
        script = ["pm.sendRequest({ url: `${baseUrl}/index.php/apps/openregister/api/registers/${id}`,",
                  "  method: 'DELETE' }, () => {});"]
        self.plant('script', collection(
            [], events=[{'listen': 'prerequest', 'script': {'exec': script}}],
        ))
        self.assertEqual({'registers#destroy'}, self.covered(self.run_ci()))

    def test_nested_folders_and_ocs_prefix(self):
        self.plant('nested', collection([{'name': 'folder', 'item': [
            request('GET', 'http://localhost/ocs/v2.php/apps/openregister/api/objects/1/2/{{id}}'),
        ]}]))
        self.assertEqual({'objects#show'}, self.covered(self.run_ci()))

    def write_baseline(self, floor):
        path = os.path.join(self.repo, 'baseline.json')
        with open(path, 'w') as fh:
            json.dump({'covered': floor}, fh)
        return path

    def test_ratchet_fails_below_floor_and_passes_at_or_above(self):
        self.plant('hit', collection([request('GET', '/index.php/apps/openregister/api/registers/{{id}}'),
                                      request('DELETE', '/index.php/apps/openregister/api/registers/1')]))
        res = self.run_ci()
        self.assertEqual(1, res['covered'])
        self.assertFalse(nrc.check_ratchet(res, self.write_baseline(2))[0])
        self.assertTrue(nrc.check_ratchet(res, self.write_baseline(1))[0])
        ok, msg = nrc.check_ratchet(res, self.write_baseline(0))
        self.assertTrue(ok)
        self.assertIn('Raise', msg)

    def test_main_exit_codes(self):
        self.plant('hit', collection([request('DELETE', '/index.php/apps/openregister/api/registers/1')]))
        base = ['--ci-only', '--routes', self.routes_path, '--ratchet']
        with redirect_stdout(io.StringIO()), redirect_stderr(io.StringIO()):
            self.assertEqual(1, nrc.main([self.repo] + base + [self.write_baseline(2)]))
            self.assertEqual(0, nrc.main([self.repo] + base + [self.write_baseline(1)]))
            # A ratchet that is not limited to what CI runs is refused.
            self.assertEqual(2, nrc.main([self.repo, '--routes', self.routes_path, '--ratchet', self.write_baseline(1)]))

    @unittest.skipIf(shutil.which('php') is None, 'php not on PATH')
    def test_routes_php_resources_are_expanded(self):
        os.makedirs(os.path.join(self.repo, 'appinfo'))
        with open(os.path.join(self.repo, 'appinfo', 'routes.php'), 'w') as fh:
            fh.write("<?php return ['resources' => ['Registers' => ['url' => 'api/registers']],"
                     " 'routes' => [['name' => 'a#b', 'url' => '/api/x/{p}', 'verb' => 'PATCH',"
                     " 'requirements' => ['p' => '.+']]]];")
        routes = nrc.load_routes(self.repo)
        self.assertEqual(
            [('registers#index', 'GET', '/api/registers'), ('registers#show', 'GET', '/api/registers/{id}'),
             ('registers#create', 'POST', '/api/registers'), ('registers#update', 'PUT', '/api/registers/{id}'),
             ('registers#destroy', 'DELETE', '/api/registers/{id}'), ('a#b', 'PATCH', '/api/x/{p}')],
            [(r['name'], r['verb'], r['url']) for r in routes])
        self.assertEqual({'p': '.+'}, routes[-1]['req'])


if __name__ == '__main__':
    unittest.main()
