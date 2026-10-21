"""Boot all application workers against disposable dependencies, never production .env."""
import os
import subprocess
import time
import uuid


def main():
    project = os.environ.get("HG_TEST_PROJECT", "hg") + "-runtime-" + uuid.uuid4().hex[:10]
    compose = ["docker", "compose", "-p", project, "-f", "docker-compose.test.yml", "--profile", "smoke"]

    def run(*args, **kwargs):
        return subprocess.run(compose + list(args), check=True, text=True, **kwargs)

    container = project + "-runtime-check"
    try:
        run("up", "-d", "--wait", "redis", "postgres", "mqtt")
        run("exec", "-T", "postgres", "createdb", "-h", "127.0.0.1", "-U", "guardian_test", "guardian_smoke")
        command = r'''set -eu
cd /source
tar --exclude=.env --exclude='.env.*' --exclude=.git --exclude=.aws --exclude=.codex --exclude=.agents --exclude=.claude --exclude=runtime --exclude=vendor --exclude=uniapp/node_modules --exclude=uniapp/dist --exclude=firmware/.pio -cf - . | tar -xf - -C /app
cd /app
composer install --no-scripts --no-interaction --prefer-dist
php -r '$p="config/process.php"; file_put_contents($p,str_replace("cpu_count() * 4", "2", file_get_contents($p)));'
php -r '$pdo=new PDO("pgsql:host=postgres;dbname=guardian_smoke","guardian_test","guardian_test"); foreach(glob("database/migrations/*.sql") as $file) $pdo->exec(file_get_contents($file));'
php webman migrate:run
php start.php start
'''
        run("run", "-d", "--name", container, "--no-deps", "runtime", "sh", "-c", command)
        for _ in range(30):
            result = subprocess.run(
                ["docker", "exec", container, "php", "-r",
                 '$r=@file_get_contents("http://127.0.0.1:8787/api/ready"); echo $r; exit($r && json_decode($r,true)["status"]==="ready" ? 0 : 1);'],
                text=True, capture_output=True,
            )
            if result.returncode == 0:
                print("Application workers ready:", result.stdout)
                # Verify the fail-closed signal when a worker loses its MQTT connection.
                run("stop", "mqtt", stdout=subprocess.DEVNULL)
                time.sleep(5)
                result = subprocess.run(["docker", "exec", container, "php", "-r",
                    '$r=@file_get_contents("http://127.0.0.1:8787/api/ready",false,stream_context_create(["http"=>["ignore_errors"=>true]])); echo $r; exit($r && json_decode($r,true)["status"]==="unavailable" ? 0 : 1);'], text=True, capture_output=True)
                if result.returncode != 0:
                    raise RuntimeError("Readiness failed to detect stopped MQTT: " + result.stdout)
                print("Stopped MQTT correctly reported unavailable")
                return
            time.sleep(2)
        raise RuntimeError("Application did not become ready")
    except Exception:
        subprocess.run(["docker", "logs", "--tail", "100", container], check=False)
        raise
    finally:
        subprocess.run(["docker", "rm", "-f", container], stdout=subprocess.DEVNULL, check=False)
        run("down", "--volumes", stdout=subprocess.DEVNULL)


if __name__ == "__main__":
    main()
