#!/usr/bin/env python3
# This file was generated entirely by AI.
# It has not been reviewed line by line by a human.
"""Build the PHP 8.3.36 compatibility distribution from fixed inputs."""
import argparse
import base64
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import socket
import subprocess
import tarfile
import tempfile
import time
import urllib.parse
import urllib.request
import zipfile

FRONTEND_SHA256 = '6090d10cb2d751191ae4f343f366fbc51dad8844aace63789e1b47e838121f2a'
UPSTREAM_COMMIT = 'b737a65f5f5fce1f897f8603db74fd970437b351'


def restore_frontend(archive, destination):
    if hashlib.sha256(archive.read_bytes()).hexdigest() != FRONTEND_SHA256:
        raise RuntimeError('The frontend archive does not match official 6.0.2.')
    count = 0
    with zipfile.ZipFile(archive) as source:
        for entry in source.infolist():
            name = entry.filename
            selected = name in {'public/meta.js', 'public/sw.js'} or name.startswith(('public/app/', 'resources/views/assets/'))
            if not selected or entry.is_dir() or name.endswith('/.gitignore'):
                continue
            path = PurePosixPath(name)
            if path.is_absolute() or '..' in path.parts:
                raise RuntimeError('Unsafe frontend archive path.')
            target = destination.joinpath(*path.parts)
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(source.read(entry))
            count += 1
    if count != 69:
        raise RuntimeError(f'Expected 69 frontend files, got {count}.')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--frontend-archive', required=True, type=Path)
    parser.add_argument('--output', type=Path, default=Path('dist/php83'))
    parser.add_argument('--version', default='6.0.2-php83.1')
    parser.add_argument('--prepare-assets-only', action='store_true')
    args = parser.parse_args()
    source = Path(__file__).resolve().parents[1]
    if args.prepare_assets_only:
        restore_frontend(args.frontend_archive, source)
        return
    if not re.fullmatch(r'[A-Za-z0-9._-]+', args.version):
        raise RuntimeError('Invalid release version.')
    php = shutil.which('php')
    composer = shutil.which('composer')
    if not php or not composer:
        raise RuntimeError('PHP and Composer must be on PATH.')
    version = subprocess.check_output([php, '-r', 'echo PHP_VERSION;'], text=True).strip()
    if version != '8.3.36':
        raise RuntimeError(f'This release targets PHP 8.3.36; found {version}.')
    output = args.output.resolve()
    output.mkdir(parents=True, exist_ok=True)
    stage = Path(tempfile.mkdtemp(prefix='runtime-', dir=output))
    logs = output / 'evidence'
    logs.mkdir(exist_ok=True)

    def ignore_files(directory, names):
        ignored = {name for name in names if name in {'.git', '.github', '.phpunit.cache', 'tests', 'node_modules'}}
        relative = Path(directory).relative_to(source)
        if relative in {Path('bootstrap/cache'), Path('public/lang'), Path('public/plugins'), Path('public/app'), Path('resources/views/assets')}:
            ignored.update(name for name in names if name != '.gitignore')
        if relative == Path('public'):
            ignored.update({'meta.js', 'sw.js'} & set(names))
        return ignored

    for name in ['app', 'bootstrap', 'config', 'database', 'resources', 'routes', 'public', 'local-packages']:
        shutil.copytree(source / name, stage / name, ignore=ignore_files)
    for name in ['artisan', 'server.php', 'composer.json', 'composer.lock', '.env.example', 'LICENSE', 'README.md', 'README-zh.md', 'README-PHP83.md']:
        shutil.copy2(source / name, stage / name)
    restore_frontend(args.frontend_archive, stage)

    def empty_storage():
        if (stage / 'storage').exists():
            shutil.rmtree(stage / 'storage')
        for name in ['app', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs', 'textures']:
            (stage / 'storage' / name).mkdir(parents=True, exist_ok=True)

    empty_storage()
    (stage / 'plugins').mkdir(exist_ok=True)
    environment = os.environ.copy()
    environment.update({
        'APP_ENV': 'production', 'APP_DEBUG': 'false',
        'APP_KEY': 'base64:' + base64.b64encode(os.urandom(32)).decode(),
        'DB_CONNECTION': 'sqlite', 'DB_DATABASE': str(stage) + '.sqlite',
        'CACHE_DRIVER': 'file', 'SESSION_DRIVER': 'file', 'MAIL_MAILER': 'array',
        'LOG_CHANNEL': 'stderr',
    })
    Path(environment['DB_DATABASE']).touch()

    def run(command, name):
        with (logs / name).open('w') as log:
            result = subprocess.run(command, cwd=stage, env=environment, stdout=log, stderr=log)
        if result.returncode:
            raise RuntimeError(f'{name}: exit {result.returncode}')

    run([composer, 'install', '--no-dev', '--no-plugins', '--no-scripts', '--prefer-dist', '--no-interaction'], 'install.log')
    run([composer, 'check-platform-reqs', '--no-dev'], 'platform.log')
    run([php, 'artisan', 'package:discover'], 'discovery.log')
    run([php, 'artisan', 'migrate', '--force'], 'migrations.log')
    (stage / 'storage/install.lock').touch()
    run([php, str(source / 'tests/probes/ProductionCompatibility.php'), str(stage)], 'compatibility.log')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    results = []
    with (logs / 'http.log').open('w') as log:
        process = subprocess.Popen([php, '-d', 'display_errors=0', '-S', f'127.0.0.1:{port}', '-t', 'public', 'server.php'], cwd=stage, env=environment, stdout=log, stderr=log)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1):
                        break
                except OSError:
                    if process.poll() is not None:
                        raise RuntimeError('PHP HTTP server exited.')
                    time.sleep(.05)
            else:
                raise RuntimeError('PHP HTTP server startup timed out.')
            for endpoint, marker in {'/': b'hp-wrapper', '/auth/login': b'login-page', '/skinlib': b'content-wrapper'}.items():
                with urllib.request.urlopen(f'http://127.0.0.1:{port}{endpoint}', timeout=15) as response:
                    body = response.read()
                    if response.status != 200 or marker not in body or urllib.parse.urlparse(response.url).path != endpoint:
                        raise RuntimeError(f'Unexpected page response: {endpoint}')
                    results.append({'path': endpoint, 'status': response.status, 'expected_page': True})
            asset = next((stage / 'public/app').glob('style.*.css'))
            endpoint = '/' + str(asset.relative_to(stage / 'public'))
            with urllib.request.urlopen(f'http://127.0.0.1:{port}{endpoint}', timeout=15) as response:
                if response.read() != asset.read_bytes():
                    raise RuntimeError('CSS content mismatch.')
                results.append({'path': endpoint, 'status': response.status})
        finally:
            process.terminate()
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait()
    (logs / 'http.json').write_text(json.dumps(results, indent=2) + '\n')
    empty_storage()
    for directory in [stage / 'public/lang', stage / 'public/plugins']:
        for item in directory.iterdir():
            if item.name != '.gitignore':
                shutil.rmtree(item) if item.is_dir() else item.unlink()
    source_commit = None
    if (source / '.git').exists():
        source_commit = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=source, text=True).strip()
    manifest = {
        'name': 'blessing-skin-server-' + args.version,
        'source_commit': source_commit,
        'upstream_commit': UPSTREAM_COMMIT,
        'php': version,
        'composer_lock_sha256': hashlib.sha256((stage / 'composer.lock').read_bytes()).hexdigest(),
        'frontend_archive_sha256': FRONTEND_SHA256,
        'status': 'compatibility-prerelease',
    }
    (stage / 'BUILD-MANIFEST.json').write_text(json.dumps(manifest, indent=2) + '\n')
    archive_path = output / (manifest['name'] + '.tar.gz')
    with tarfile.open(archive_path, 'w:gz') as archive:
        archive.add(stage, arcname=manifest['name'])
    summary = {**manifest, 'archive': archive_path.name, 'archive_sha256': hashlib.sha256(archive_path.read_bytes()).hexdigest(), 'archive_bytes': archive_path.stat().st_size}
    (output / 'build-result.json').write_text(json.dumps(summary, indent=2) + '\n')
    print(json.dumps(summary, indent=2))


if __name__ == '__main__':
    main()
