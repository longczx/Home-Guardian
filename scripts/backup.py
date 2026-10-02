#!/usr/bin/env python3
"""Linux Docker Compose backup/restore. No Docker socket is exposed to the web app."""
import argparse
import contextlib
import datetime as dt
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import subprocess
import tarfile
import tempfile
import zipfile

ID_PATTERN = re.compile(r"^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{8}$")
CONFIG_FILES = ['.env', 'docker-compose.yml', 'docker-compose.override.yml',
                'docker/nginx/default.conf', 'docker/emqx/auth.conf']


def execute(args, **kwargs):
    result = subprocess.run(args, stderr=subprocess.PIPE, **kwargs)
    if result.returncode:
        raise RuntimeError('Command failed: ' + args[0] + '; check the server configuration and service state')
    return result


def digest(path):
    h = hashlib.sha256()
    with path.open('rb') as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b''): h.update(block)
    return h.hexdigest()


def validate_archive(path):
    with zipfile.ZipFile(path) as archive:
        names = archive.namelist()
        allowed = {'manifest.json', 'database.dump', 'redis.tar', 'emqx.tar'} | {'config/' + name for name in CONFIG_FILES}
        if len(names) != len(set(names)) or set(names) - allowed:
            raise ValueError('Unexpected or duplicated archive entry')
        manifest = json.loads(archive.read('manifest.json'))
        if manifest.get('format') != 1 or not ID_PATTERN.fullmatch(manifest.get('id', '')):
            raise ValueError('Unsupported backup format')
        if set(manifest.get('sha256', {})) != set(names) - {'manifest.json'}:
            raise ValueError('Incomplete checksum manifest')
        for name, expected in manifest['sha256'].items():
            h = hashlib.sha256()
            with archive.open(name) as stream:
                for block in iter(lambda: stream.read(1024 * 1024), b''): h.update(block)
            if h.hexdigest() != expected: raise ValueError('Checksum mismatch: ' + name)
        if not {'database.dump', 'redis.tar'}.issubset(names): raise ValueError('Missing data files')
        return manifest


class Backups:
    def __init__(self, root, directory):
        self.root = root.resolve()
        self.directory = directory.resolve()
        self.catalog = self.root / 'runtime/backup-catalog'
        self.directory.mkdir(parents=True, exist_ok=True, mode=0o700)
        self.directory.chmod(0o700)
        self.catalog.mkdir(parents=True, exist_ok=True)
        self.compose = ['docker', 'compose', '--project-directory', str(self.root)]
        self.services = self.output(self.compose + ['config', '--services'], text=True).splitlines()

    def output(self, args, **kwargs):
        return execute(args, stdout=subprocess.PIPE, **kwargs).stdout

    def service(self, name):
        cid = self.output(self.compose + ['ps', '-a', '-q', name], text=True).strip()
        if not cid or '\n' in cid: raise RuntimeError('Expected one container for ' + name)
        return json.loads(self.output(['docker', 'inspect', cid]))[0]

    def db(self, container, command, *, stdin=None, stdout=None):
        return execute(['docker', 'exec', '-i', container['Id'], 'sh', '-c', command], stdin=stdin, stdout=stdout)

    def redis_volume(self):
        container = self.service('redis')
        mounts = [m for m in container['Mounts'] if m['Destination'] == '/data' and m['Type'] == 'volume']
        if len(mounts) != 1: raise RuntimeError('Redis /data must use a named Docker volume')
        return container, mounts[0]['Name']

    def broker_volume(self):
        if 'emqx' not in self.services: return None
        container = self.service('emqx')
        mounts = [m for m in container['Mounts'] if m['Destination'] == '/opt/emqx/data' and m['Type'] == 'volume']
        if len(mounts) != 1: raise RuntimeError('EMQX data must use a named Docker volume')
        return container, mounts[0]['Name']

    def database_version(self, container):
        result = self.output(['docker','exec',container['Id'],'sh','-c',
            '''exec psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -At -c "SELECT json_build_object('postgres',current_setting('server_version_num')::integer/10000,'timescaledb',(SELECT extversion FROM pg_extension WHERE extname='timescaledb'));"'''],text=True)
        return json.loads(result)

    def record(self, metadata):
        target = self.catalog / (metadata['id'] + '.json')
        temporary = target.with_suffix('.tmp')
        temporary.write_text(json.dumps(metadata, ensure_ascii=False), encoding='utf-8')
        temporary.chmod(0o644)
        temporary.replace(target)

    @contextlib.contextmanager
    def pause(self):
        running = [name for name in ['webman', 'emqx', 'redis'] if name in self.services and self.service(name)['State']['Running']]
        try:
            if 'webman' in running: execute(self.compose + ['stop', 'webman'], stdout=subprocess.DEVNULL)
            if 'emqx' in running: execute(self.compose + ['stop', 'emqx'], stdout=subprocess.DEVNULL)
            if 'redis' in running: execute(self.compose + ['stop', 'redis'], stdout=subprocess.DEVNULL)
            yield
        finally:
            if 'redis' in running: execute(self.compose + ['start', 'redis'], stdout=subprocess.DEVNULL)
            if 'emqx' in running: execute(self.compose + ['start', 'emqx'], stdout=subprocess.DEVNULL)
            if 'webman' in running: execute(self.compose + ['start', 'webman'], stdout=subprocess.DEVNULL)

    def commit(self):
        p = subprocess.run(['git', '-C', str(self.root), 'rev-parse', 'HEAD'], capture_output=True, text=True)
        return p.stdout.strip() if p.returncode == 0 else 'unknown'

    def backup(self, keep=14):
        identity = dt.datetime.now(dt.timezone.utc).strftime('%Y%m%dT%H%M%SZ-') + secrets.token_hex(4)
        metadata = {'id': identity, 'created_at': dt.datetime.now(dt.timezone.utc).isoformat(),
                    'status': 'running', 'commit': self.commit()}
        self.record(metadata)
        try:
            database = self.service('postgres')
            redis, volume = self.redis_volume()
            broker = self.broker_volume()
            env = dict(e.split('=', 1) for e in database['Config']['Env'])
            manifest = {**metadata, 'format': 1, 'database': env['POSTGRES_DB'], 'user': env['POSTGRES_USER'],
                        'database_version':self.database_version(database), 'sha256': {}}
            with tempfile.TemporaryDirectory(dir=self.directory) as temp:
                staging = Path(temp)
                with self.pause():
                    with (staging / 'database.dump').open('wb') as stream:
                        self.db(database, 'exec pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc', stdout=stream)
                    execute(['docker', 'run', '--rm', '--network', 'none', '-v', volume + ':/data:ro',
                             '-v', str(staging) + ':/backup', redis['Image'],
                             'tar', '-cf', '/backup/redis.tar', '-C', '/data', '.'], stdout=subprocess.DEVNULL)
                    if broker:
                        execute(['docker','run','--rm','--network','none','-v',broker[1]+':/data:ro',
                            '-v',str(staging)+':/backup',redis['Image'],'tar','-cf','/backup/emqx.tar','-C','/data','.'],stdout=subprocess.DEVNULL)
                    for name in CONFIG_FILES:
                        source = self.root / name
                        if source.is_file():
                            target = staging / 'config' / name
                            target.parent.mkdir(parents=True, exist_ok=True)
                            shutil.copyfile(source, target)
                with (staging / 'database.dump').open('rb') as stream:
                    self.db(database, 'exec pg_restore --list', stdin=stream, stdout=subprocess.DEVNULL)
                for file in staging.rglob('*'):
                    if file.is_file(): manifest['sha256'][file.relative_to(staging).as_posix()] = digest(file)
                (staging / 'manifest.json').write_text(json.dumps(manifest), encoding='utf-8')
                partial = self.directory / (identity + '.partial')
                with zipfile.ZipFile(partial, 'w', zipfile.ZIP_DEFLATED) as archive:
                    for file in staging.rglob('*'):
                        if file.is_file(): archive.write(file, file.relative_to(staging).as_posix())
                partial.chmod(0o600)
                validate_archive(partial)
                target = self.directory / (identity + '.zip')
                partial.replace(target)
            metadata.update(status='success', size_bytes=target.stat().st_size)
            self.record(metadata)
            if keep > 0:
                archives = sorted((p for p in self.directory.glob('*.zip') if ID_PATTERN.fullmatch(p.stem)), reverse=True)
                for old in archives[keep:]:
                    old.unlink()
                    (self.catalog / (old.stem + '.json')).unlink(missing_ok=True)
            print(json.dumps(metadata), flush=True)
            return target
        except Exception:
            metadata.update(status='failed', error='备份失败，请由管理员检查磁盘空间、Docker 和数据库状态')
            self.record(metadata)
            raise

    def restore(self, archive, confirm, restore_config=False):
        manifest = validate_archive(archive)
        if confirm != manifest['id']: raise ValueError('Restore requires --confirm with the complete backup ID')
        if manifest.get('commit') != self.commit(): raise ValueError('Check out the backup code commit before restoring')
        database = self.service('postgres')
        if manifest.get('database_version') != self.database_version(database):
            raise ValueError('PostgreSQL major/TimescaleDB versions must match the backup')
        env = dict(e.split('=', 1) for e in database['Config']['Env'])
        if (manifest['database'], manifest['user']) != (env['POSTGRES_DB'], env['POSTGRES_USER']):
            raise ValueError('Database name/user mismatch; prepare matching database configuration first')
        redis, volume = self.redis_volume()
        broker = self.broker_volume()
        # Validate nested archive before any write or service outage.
        with tempfile.TemporaryDirectory(dir=self.directory) as temp:
            staging = Path(temp)
            with zipfile.ZipFile(archive) as zipped: zipped.extractall(staging)
            if restore_config and (staging / 'config/.env').is_file():
                if not (self.root / '.env').is_file() or digest(staging / 'config/.env') != digest(self.root / '.env'):
                    raise ValueError('Prepare the backed-up .env and matching database/Redis credentials before restoring configuration')
            if (staging / 'emqx.tar').is_file() and not broker: raise ValueError('Prepare the EMQX service before restoring')
            for file in ['redis.tar','emqx.tar']:
                if not (staging / file).is_file(): continue
                with tarfile.open(staging / file) as nested:
                    for member in nested:
                        if Path(member.name).is_absolute() or '..' in Path(member.name).parts or not (member.isfile() or member.isdir()):
                            raise ValueError('Unsafe data archive entry')
            with (staging / 'database.dump').open('rb') as stream:
                self.db(database, 'exec pg_restore --list', stdin=stream, stdout=subprocess.DEVNULL)
            before = self.backup(keep=0)
            print('Recovery checkpoint: ' + str(before), flush=True)
            # Leave services stopped on failure. Starting with partially restored data is unsafe.
            execute(self.compose + ['stop', 'webman', 'redis'] + (['emqx'] if broker else []), stdout=subprocess.DEVNULL)
            self.db(database, 'dropdb --force -U "$POSTGRES_USER" "$POSTGRES_DB" && createdb -U "$POSTGRES_USER" "$POSTGRES_DB"')
            self.db(database, 'psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "CREATE EXTENSION IF NOT EXISTS timescaledb; SELECT timescaledb_pre_restore();"', stdout=subprocess.DEVNULL)
            with (staging / 'database.dump').open('rb') as stream:
                self.db(database, 'exec pg_restore --exit-on-error -U "$POSTGRES_USER" -d "$POSTGRES_DB"', stdin=stream, stdout=subprocess.DEVNULL)
            self.db(database, 'exec psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT timescaledb_post_restore(); UPDATE users SET auth_version = GREATEST(auth_version + 1, EXTRACT(EPOCH FROM clock_timestamp())::bigint); DELETE FROM refresh_tokens; UPDATE command_logs SET status=\'timeout\' WHERE status IN (\'queued\',\'sent\',\'delivered\'); UPDATE notification_deliveries SET status=\'failed\', last_error=\'恢复后需人工确认重试\' WHERE status=\'pending\';"', stdout=subprocess.DEVNULL)
            execute(['docker', 'run', '--rm', '--network', 'none', '-v', volume + ':/data',
                     '-v', str(staging) + ':/backup:ro', redis['Image'], 'sh', '-c',
                     'find /data -mindepth 1 -maxdepth 1 -exec rm -rf {} +; tar -xf /backup/redis.tar -C /data'], stdout=subprocess.DEVNULL)
            if broker and (staging / 'emqx.tar').is_file():
                execute(['docker','run','--rm','--network','none','-v',broker[1]+':/data',
                    '-v',str(staging)+':/backup:ro',redis['Image'],'sh','-c',
                    'find /data -mindepth 1 -maxdepth 1 -exec rm -rf {} +; tar -xf /backup/emqx.tar -C /data'],stdout=subprocess.DEVNULL)
            if restore_config:
                for name in CONFIG_FILES:
                    source = staging / 'config' / name
                    if source.is_file():
                        target = self.root / name
                        target.parent.mkdir(parents=True, exist_ok=True)
                        shutil.copyfile(source, target)
                        if name == '.env': target.chmod(0o600)
            execute(self.compose + ['start', 'redis'], stdout=subprocess.DEVNULL)
            # Do not replay old physical commands or notification tasks after a restore.
            password = next(e.split('=', 1)[1] for e in redis['Config']['Env'] if e.startswith('REDIS_PASSWORD=')) if any(e.startswith('REDIS_PASSWORD=') for e in redis['Config']['Env']) else None
            if password is None:
                args = redis['Config']['Cmd']
                password = args[args.index('--requirepass') + 1] if '--requirepass' in args else ''
            import time
            for _ in range(30):
                state = self.service('redis')['State']
                if state.get('Health', {}).get('Status', 'healthy') == 'healthy': break
                time.sleep(1)
            else: raise RuntimeError('Restored Redis did not become healthy; application remains stopped')
            execute(['docker', 'exec', '-i', redis['Id'], 'sh', '-c',
                     'IFS= read -r REDISCLI_AUTH; export REDISCLI_AUTH; exec redis-cli -n 1 DEL hg:q:mqtt:command:send hg:q:mqtt:command:processing hg:q:notify:queue hg:q:notify:queue:processing'],
                    input=password + '\n', text=True, stdout=subprocess.DEVNULL)
            command = ['up', '-d', '--no-deps', 'webman'] if restore_config else ['start', 'webman']
            if broker: execute(self.compose + ['start','emqx'],stdout=subprocess.DEVNULL)
            execute(self.compose + command, stdout=subprocess.DEVNULL)
            print('Restore completed. All users must sign in again. Physical commands were not replayed.', flush=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument('--backup-dir', type=Path)
    commands = parser.add_subparsers(dest='command', required=True)
    backup = commands.add_parser('backup'); backup.add_argument('--keep', type=int, default=14)
    verify = commands.add_parser('verify'); verify.add_argument('archive', type=Path)
    restore = commands.add_parser('restore'); restore.add_argument('archive', type=Path)
    restore.add_argument('--confirm', required=True); restore.add_argument('--restore-config', action='store_true')
    args = parser.parse_args()
    os.umask(0o077)
    if args.command == 'verify':
        m = validate_archive(args.archive); print('Verified ' + m['id']); return
    manager = Backups(args.root, args.backup_dir or args.root / 'runtime/backups')
    with (manager.directory / '.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        if args.command == 'backup': manager.backup(args.keep)
        else: manager.restore(args.archive.resolve(), args.confirm, args.restore_config)


if __name__ == '__main__': main()
