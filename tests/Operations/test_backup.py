import importlib.util
import hashlib
import json
from pathlib import Path
import tempfile
import unittest
import zipfile

spec = importlib.util.spec_from_file_location('backup', Path(__file__).resolve().parents[2] / 'scripts/backup.py')
backup = importlib.util.module_from_spec(spec)
spec.loader.exec_module(backup)


class ArchiveValidationTest(unittest.TestCase):
    def archive(self, directory, extra=None, corrupt=False):
        contents = {'database.dump': b'database', 'redis.tar': b'redis'}
        manifest = {'format': 1, 'id': '20261002T000000Z-1234abcd',
                    'sha256': {name: hashlib.sha256(data).hexdigest() for name, data in contents.items()}}
        path = Path(directory) / 'backup.zip'
        with zipfile.ZipFile(path, 'w') as z:
            for name, data in contents.items(): z.writestr(name, b'corrupt' if corrupt else data)
            z.writestr('manifest.json', json.dumps(manifest))
            if extra: z.writestr(extra, 'unsafe')
        return path

    def test_checksums_and_expected_files_are_required(self):
        with tempfile.TemporaryDirectory() as directory:
            self.assertEqual(backup.validate_archive(self.archive(directory))['format'], 1)
            with self.assertRaises(ValueError): backup.validate_archive(self.archive(directory, corrupt=True))

    def test_unknown_and_traversal_entries_are_refused(self):
        with tempfile.TemporaryDirectory() as directory:
            for name in ['../.env', '/etc/passwd', 'unexpected.txt']:
                with self.assertRaises(ValueError): backup.validate_archive(self.archive(directory, extra=name))


if __name__ == '__main__': unittest.main()
