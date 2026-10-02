"""Run on a Linux Docker host. Uses a unique disposable Compose project, never production services."""
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import time
import secrets

spec = importlib.util.spec_from_file_location('backup', Path(__file__).resolve().parents[2] / 'scripts/backup.py')
module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)

with tempfile.TemporaryDirectory(prefix='hg-restore-test-') as directory:
    root = Path(directory)
    project = 'hg-restore-test-' + secrets.token_hex(4)
    config = {'name': project, 'services': {
        'postgres': {'image':'timescale/timescaledb:latest-pg14', 'environment':{
            'POSTGRES_USER':'guardian_test','POSTGRES_PASSWORD':'fixture-only','POSTGRES_DB':'guardian_test'},
            'volumes':['db:/var/lib/postgresql/data']},
        'redis': {'image':'redis:7-alpine', 'command':['redis-server','--requirepass','fixture-only','--appendonly','yes'],
                  'volumes':['cache:/data']},
        'webman': {'image':'redis:7-alpine','command':['sleep','infinity']},
        'emqx': {'image':'redis:7-alpine','command':['sleep','infinity'],'volumes':['broker:/opt/emqx/data']}
    }, 'volumes':{'db':{},'cache':{},'broker':{}}}
    (root / 'docker-compose.yml').write_text(json.dumps(config))
    manager = module.Backups(root, root / 'backups')
    try:
        module.execute(manager.compose + ['up','-d'], stdout=subprocess.DEVNULL)
        db = manager.service('postgres')
        for _ in range(60):
            ready = subprocess.run(['docker','exec',db['Id'],'pg_isready','-U','guardian_test'],capture_output=True)
            if ready.returncode == 0: break
            time.sleep(1)
        else: raise RuntimeError('Fixture database did not become ready')
        sql = """CREATE EXTENSION IF NOT EXISTS timescaledb;
CREATE TABLE users(id bigint, auth_version bigint); INSERT INTO users VALUES(1,0);
CREATE TABLE refresh_tokens(id bigint); INSERT INTO refresh_tokens VALUES(1);
CREATE TABLE command_logs(id bigint, status text); INSERT INTO command_logs VALUES(1,'queued');
CREATE TABLE notification_deliveries(id bigint, status text, last_error text); INSERT INTO notification_deliveries VALUES(1,'pending',null);
CREATE TABLE telemetry_logs(ts timestamptz NOT NULL, device_id bigint, metric_key text, value jsonb);
SELECT create_hypertable('telemetry_logs','ts');
INSERT INTO telemetry_logs VALUES(now(),1,'temperature','25');
"""
        module.execute(['docker','exec','-i',db['Id'],'psql','-v','ON_ERROR_STOP=1','-U','guardian_test','-d','guardian_test'],input=sql,text=True,stdout=subprocess.DEVNULL)
        redis = manager.service('redis')
        def redis_command(*args):
            return manager.output(['docker','exec',redis['Id'],'redis-cli','-a','fixture-only',*args],text=True).strip()
        redis_command('SET','fixture','preserved')
        broker = manager.service('emqx')
        module.execute(['docker','exec',broker['Id'],'sh','-c','echo preserved > /opt/emqx/data/fixture'])
        redis_command('-n','1','LPUSH','hg:q:mqtt:command:send','never-replay')
        archive = manager.backup()
        module.execute(['docker','exec',db['Id'],'psql','-U','guardian_test','-d','guardian_test','-c','DELETE FROM telemetry_logs;'],stdout=subprocess.DEVNULL)
        redis_command('SET','fixture','changed')
        module.execute(['docker','exec',broker['Id'],'sh','-c','echo changed > /opt/emqx/data/fixture'])
        manager.restore(archive, archive.stem)
        count = manager.output(['docker','exec',db['Id'],'psql','-U','guardian_test','-d','guardian_test','-At','-c','SELECT count(*) FROM telemetry_logs;'],text=True).strip()
        assert count == '1', count
        assert redis_command('GET','fixture') == 'preserved'
        assert manager.output(['docker','exec',broker['Id'],'cat','/opt/emqx/data/fixture'],text=True).strip() == 'preserved'
        assert redis_command('-n','1','LLEN','hg:q:mqtt:command:send') == '0'
        checks = manager.output(['docker','exec',db['Id'],'psql','-U','guardian_test','-d','guardian_test','-At','-c',
            "SELECT (SELECT count(*) FROM refresh_tokens), (SELECT status FROM command_logs LIMIT 1), (SELECT auth_version > 1000 FROM users LIMIT 1);"],text=True).strip()
        assert checks == '0|timeout|t', checks
        assert manager.service('webman')['State']['Running']
        print('RESTORE_SMOKE_OK: Timescale chunks, Redis, session revocation and command replay prevention')
    finally:
        # This Compose project and its volumes were created solely by this test.
        module.execute(manager.compose + ['down','-v'],stdout=subprocess.DEVNULL)
