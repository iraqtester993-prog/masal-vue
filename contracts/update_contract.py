"""Export registered routes using an isolated Laravel testing configuration."""
from pathlib import Path
import argparse
import hashlib
import json
import os
import re
import shutil
import subprocess
import tempfile


def yaml_lines(value, indent=0):
    prefix = ' ' * indent
    if isinstance(value, dict):
        for key, item in value.items():
            label = key if re.fullmatch(r'[A-Za-z_][A-Za-z0-9_-]*', key) else json.dumps(key, ensure_ascii=False)
            if isinstance(item, (dict, list)) and item:
                yield prefix + label + ':'
                yield from yaml_lines(item, indent + 2)
            else:
                yield prefix + label + ': ' + json.dumps(item, ensure_ascii=False)
    elif isinstance(value, list):
        for item in value:
            if isinstance(item, (dict, list)) and item:
                yield prefix + '-'
                yield from yaml_lines(item, indent + 2)
            else:
                yield prefix + '- ' + json.dumps(item, ensure_ascii=False)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default=os.environ.get('MASAL_PHP') or shutil.which('php'))
    args = parser.parse_args()
    if not args.php:
        parser.error('Set MASAL_PHP or provide --php with PHP 8.4.1 or newer.')
    root = Path(__file__).resolve().parents[1]
    backend = root / 'backend'
    if not (backend / 'vendor/autoload.php').is_file():
        parser.error('Install the locked backend dependencies first.')
    version = subprocess.run([args.php, '-r', 'echo PHP_VERSION;'], check=True, capture_output=True, text=True).stdout
    if tuple(int(p) for p in version.split('.')[:3]) < (8, 4, 1):
        parser.error('PHP 8.4.1 or newer is required.')
    rows = []
    cache_directory = backend / 'bootstrap/cache'
    if not cache_directory.is_dir() or cache_directory.is_symlink():
        parser.error('The local bootstrap/cache directory must exist and be writable.')
    with tempfile.TemporaryDirectory(prefix='.route-catalog-', dir=cache_directory) as temporary:
        env = {**os.environ, 'APP_ENV': 'testing', 'APP_DEBUG': 'false', 'DB_CONNECTION': 'sqlite',
               'DB_DATABASE': ':memory:', 'CACHE_STORE': 'array', 'SESSION_DRIVER': 'array',
               'QUEUE_CONNECTION': 'sync', 'LOG_CHANNEL': 'null',
               'APP_KEY': 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='}
        for variable, name in [('APP_CONFIG_CACHE', 'config.php'), ('APP_SERVICES_CACHE', 'services.php'),
                               ('APP_PACKAGES_CACHE', 'packages.php'), ('APP_ROUTES_CACHE', 'routes.php')]:
            env[variable] = (Path(temporary) / name).relative_to(backend).as_posix()
        for path in ['api/v1', 'sanctum']:
            result = subprocess.run([args.php, 'artisan', 'route:list', '--json', '--path=' + path],
                                    cwd=backend, env=env, capture_output=True, text=True, encoding='utf-8')
            if result.returncode:
                raise RuntimeError('Isolated Laravel route inspection failed; no contract was written.')
            rows.extend(json.loads(result.stdout))
    rows = [row for row in rows if row['uri'].startswith('api/v1/') or row['uri'] == 'sanctum/csrf-cookie']
    rows.sort(key=lambda row: (row['uri'], row['method']))
    inventory = root / 'contracts/API_ROUTE_CATALOG.json'
    catalog_text = json.dumps(rows, ensure_ascii=False, indent=2) + '\n'
    paths = {}
    for row in rows:
        uri = '/' + row['uri']
        for method in row['method'].split('|'):
            method = method.lower()
            if method not in ['get', 'post', 'put', 'patch', 'delete', 'head', 'options']:
                raise RuntimeError('Unsupported registered method.')
            if method in paths.setdefault(uri, {}):
                raise RuntimeError('Duplicate method and path; inspect domain-specific routes manually.')
            protected = any('Authenticate:sanctum' in middleware for middleware in row['middleware'])
            operation = {'operationId': method + '_' + re.sub(r'[^A-Za-z0-9]+', '_', row['uri']).strip('_'),
                         'tags': [row['uri'].split('/')[2] if row['uri'].startswith('api/v1/') else 'session'],
                         'x-handler': row['action'], 'x-middleware': row['middleware'],
                         'security': [{'portalSession': []}] if protected else [],
                         'responses': {'default': {'description': 'The controller, FormRequest, Resource and service define validation, response bodies and status codes.'}}}
            params = re.findall(r'\{([^}]+)\}', uri)
            if params:
                operation['parameters'] = [{'name': name, 'in': 'path', 'required': True, 'schema': {'type': 'string'}} for name in params]
            paths[uri][method] = operation
    document = {'openapi': '3.0.3',
                'info': {'title': 'Dananeer v1 API route reference', 'version': '1.0',
                         'description': 'Generated from current Laravel routes. This is a complete registered method/path index, not inferred request or response schemas. Current FormRequests, controllers, Resources, services and their tests are authoritative. Cookie authentication, CSRF, operational state and account scope are enforced by the backend.'},
                'servers': [{'url': 'https://' + portal + '.dananir-iq.com'} for portal in ['admin', 'agents', 'pos']],
                'x-route-catalog': {'file': inventory.name, 'generator': 'python contracts/update_contract.py --php <PHP>',
                                    'records': len(rows), 'unique-uri-paths': len(paths),
                                    'sha256': hashlib.sha256(catalog_text.encode()).hexdigest()},
                'paths': paths,
                'components': {'securitySchemes': {'portalSession': {'type': 'apiKey', 'in': 'cookie', 'name': 'masal_session',
                                                                     'description': 'Use the host-scoped session cookie configured by Laravel. The actual cookie name is server-configured; obtain it through the normal CSRF and login flow.'}}}}
    output = root / 'contracts/openapi.yaml'
    inventory.write_text(catalog_text, encoding='utf-8', newline='\n')
    output.write_text('\n'.join(yaml_lines(document)) + '\n', encoding='utf-8', newline='\n')
    print(json.dumps({'passed': True, 'registered_route_records': len(rows), 'unique_paths': len(paths),
                      'operations': sum(len(value) for value in paths.values()), 'business_database_connected': False}))


if __name__ == '__main__':
    main()
