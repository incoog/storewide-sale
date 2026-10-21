#!/usr/bin/env python3
"""Build the installable plugin ZIP from an explicit runtime allowlist."""
import argparse
from pathlib import Path
import re
import zipfile

ROOT = Path(__file__).resolve().parents[1]


def build(root=ROOT, output_dir=None, expected_tag=None):
    root = Path(root)
    match = re.search(r'^ \* Version: ([0-9]+\.[0-9]+\.[0-9]+)\s*$', (root / 'storewide-sale.php').read_text(), re.M)
    if not match:
        raise ValueError('Plugin header has no supported version')
    version = match.group(1)
    if expected_tag and expected_tag != f'v{version}':
        raise ValueError(f'Tag {expected_tag} does not match plugin version {version}')
    files = [root / name for name in ('storewide-sale.php', 'README.md', 'LICENSE')]
    for directory in ('includes', 'assets'):
        files.extend(p for p in (root / directory).rglob('*') if p.is_file() and not any(part.startswith('.') for part in p.relative_to(root).parts))
    for path in files:
        if not path.is_file() or path.is_symlink():
            raise ValueError(f'Missing file or symlink in release inputs: {path}')
    destination = Path(output_dir) if output_dir is not None else root / 'dist'
    destination.mkdir(parents=True, exist_ok=True)
    archive = destination / f'storewide-sale-{version}.zip'
    with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as out:
        for path in sorted(files):
            info = zipfile.ZipInfo('storewide-sale/' + path.relative_to(root).as_posix(), date_time=(2026, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            out.writestr(info, path.read_bytes())
    with zipfile.ZipFile(archive) as check:
        if check.testzip() is not None:
            raise ValueError('ZIP integrity check failed')
    return archive


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output-dir')
    parser.add_argument('--expected-tag')
    args = parser.parse_args()
    print(build(output_dir=args.output_dir, expected_tag=args.expected_tag))
