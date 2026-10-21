"""Check installation layout and separation of development files."""
import importlib.util
from pathlib import Path
import tempfile
import unittest
import zipfile

spec = importlib.util.spec_from_file_location('release', Path(__file__).resolve().parents[1] / 'scripts/build_release.py')
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


class PackageTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name) / 'source'
        self.root.mkdir()
        for name, content in {'storewide-sale.php': '<?php\n/**\n * Version: 0.1.0\n */', 'README.md': 'readme', 'LICENSE': 'GPL', 'includes/engine.php': '<?php', 'assets/admin.js': '// asset', 'tests/private.php': '<?php', '.github/workflows/ci.yml': 'CI', 'node_modules/foo/index.js': '// dependency', '.git/config': 'git'}.items():
            path = self.root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(content)
        self.output = Path(self.temp.name) / 'dist'

    def test_installable_allowlist(self):
        archive = release.build(self.root, self.output)
        with zipfile.ZipFile(archive) as out:
            self.assertEqual(set(out.namelist()), {'storewide-sale/storewide-sale.php', 'storewide-sale/README.md', 'storewide-sale/LICENSE', 'storewide-sale/includes/engine.php', 'storewide-sale/assets/admin.js'})
            self.assertIsNone(out.testzip())

    def test_tag_must_match_header(self):
        with self.assertRaisesRegex(ValueError, 'does not match'):
            release.build(self.root, self.output, 'v0.2.0')

    def test_reproducible_archive(self):
        first = release.build(self.root, self.output).read_bytes()
        self.assertEqual(first, release.build(self.root, self.output).read_bytes())

    def test_required_file_cannot_be_missing(self):
        (self.root / 'LICENSE').unlink()
        with self.assertRaisesRegex(ValueError, 'Missing file'):
            release.build(self.root, self.output)


if __name__ == '__main__':
    unittest.main()
