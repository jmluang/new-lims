#!/usr/bin/env python3
"""Package an exact verified binary in an existing runtime, without compiling.
Caller supplies resource admission/observation. No credentials or app data mount.
"""
import argparse
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import uuid


def sha256(path):
    value = hashlib.sha256()
    with path.open('rb') as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b''):
            value.update(block)
    return value.hexdigest()


def package(args):
    binary = Path(args.binary).resolve(strict=True)
    if sha256(binary) != args.binary_sha256:
        raise ValueError('Binary SHA256 differs from the verified source build')
    docker = ['docker'] + (['--context', args.context] if args.context else [])

    def run(command):
        result = subprocess.run(docker + command, capture_output=True, text=True, timeout=120)
        if result.returncode:
            raise RuntimeError('Docker packaging failed: ' + ' '.join(command[:2]))
        return result.stdout.strip()

    runtime = json.loads(run(['image', 'inspect', args.runtime_image]))[0]
    if runtime['Os'] != 'linux' or runtime['Architecture'] != 'amd64':
        raise ValueError('The verified binary requires the Linux amd64 runtime')
    name = 'lims-binary-package-' + uuid.uuid4().hex[:12]
    container = None
    try:
        with tempfile.TemporaryDirectory(prefix='lims-binary-package-') as directory:
            staged = Path(directory) / 'lims-server'
            shutil.copyfile(binary, staged)
            # copyfile creates a new file with the default mode (usually0644).
            # Docker cp preserves that mode: set it explicitly before packaging.
            staged.chmod(0o755)
            container = run(['create', '--name', name, '--network', 'none',
                '--memory', '128m', '--memory-swap', '128m', '--cpus', '0.1',
                '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges:true', args.runtime_image])
            run(['cp', str(staged), container + ':/usr/local/bin/lims-server'])
            labels = {
                'org.opencontainers.image.revision': args.git_commit,
                'io.lims.source.aggregate': args.source_sha256,
                'io.lims.binary.sha256': args.binary_sha256,
                'io.lims.runtime.base': runtime['Id'],
                'io.lims.packaging.executable_mode': '0755',
                'lims.acceptance.source': args.source_sha256,
                'lims.acceptance.build_id': args.git_commit,
            }
            changes = [part for key, value in labels.items()
                       for part in ['--change', f'LABEL {key}={value}']]
            image = run(['commit', *changes, container, args.tag])
        # Check effective container filesystem permissions and loader libraries,
        # rather than accepting a matching byte hash alone.
        check = run(['run', '--rm', '--network', 'none', '--memory', '128m',
            '--memory-swap', '128m', '--cpus', '0.1', '--read-only',
            '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges:true',
            '--entrypoint', '/bin/sh', image, '-c',
            'test -x /usr/local/bin/lims-server && stat -c "%a %s" /usr/local/bin/lims-server '
            '&& sha256sum /usr/local/bin/lims-server && ldd /usr/local/bin/lims-server'])
        lines = check.splitlines()
        if not lines or not lines[0].startswith('755 ') or args.binary_sha256 not in check or 'not found' in check:
            raise RuntimeError('Packaged binary permission, hash or runtime library check failed')
        return {'status': 'PASS_VERIFIED_EXECUTABLE_IMAGE', 'image_id': image,
                'tag': args.tag, 'binary_sha256': args.binary_sha256,
                'mode': '0755', 'source_sha256': args.source_sha256,
                'git_commit': args.git_commit, 'checks': check}
    finally:
        if container:
            run(['rm', container])


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    for option in ['binary', 'runtime-image', 'binary-sha256', 'source-sha256', 'git-commit', 'tag']:
        parser.add_argument('--' + option, required=True)
    parser.add_argument('--context')
    args = parser.parse_args()
    for value, length in [(args.binary_sha256, 64), (args.source_sha256, 64), (args.git_commit, 40)]:
        if len(value) != length or any(c not in '0123456789abcdef' for c in value):
            parser.error('Hashes and the Git commit must use full lowercase hexadecimal identifiers')
    print(json.dumps(package(args), indent=2))


if __name__ == '__main__':
    main()
