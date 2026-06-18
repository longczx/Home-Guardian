# Home Guardian

<p align="center">
  <strong>A high-performance, scalable home environment management platform built on Webman.</strong>
  <br />
  <em>Monitor your home in real time, from anywhere.</em>
</p>

<p align="center">
  <a href="https://github.com/longczx/Home-Guardian/actions/workflows/ci.yml"><img src="https://github.com/longczx/Home-Guardian/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
  <a href="#"><img src="https://img.shields.io/badge/PHP-8.2-blue.svg" alt="PHP 8.2"></a>
  <a href="#"><img src="https://img.shields.io/badge/Webman-latest-brightgreen.svg" alt="Webman"></a>
  <a href="#"><img src="https://img.shields.io/badge/MQTT-EMQX_5.8-orange.svg" alt="EMQX"></a>
  <a href="#"><img src="https://img.shields.io/badge/Database-PostgreSQL-blue.svg" alt="PostgreSQL"></a>
  <a href="#"><img src="https://img.shields.io/badge/Admin-LayUI-red.svg" alt="LayUI"></a>
  <a href="#"><img src="https://img.shields.io/badge/Mobile-React_19-61dafb.svg" alt="React"></a>
  <a href="#"><img src="https://img.shields.io/badge/License-MIT-green.svg" alt="License MIT"></a>
</p>

<p align="center">English | <a href="README.md">简体中文</a></p>

---

**Home Guardian** is an open-source IoT project that gives you a private, secure, and powerful smart-home core. By connecting sensors and actuators, you can monitor your home environment (temperature, humidity, etc.) in real time and remotely control devices (lights, switches, and more).

## Key Features

*   **High performance:** Built on [Webman](https://www.workerman.net/webman) with a resident-memory model for excellent throughput and very low latency.
*   **Real-time communication:** Uses [EMQX](https://www.emqx.io/) as the MQTT broker for device messaging, and pushes data to the frontend in real time over WebSocket.
*   **Smart alerting:** A highly customizable alert engine that, when telemetry triggers a rule, sends notifications via Email, Webhook, Telegram, WeChat Work, DingTalk, and more.
*   **Time-series storage:** Uses **PostgreSQL + TimescaleDB** to store massive volumes of sensor data with efficient query performance.
*   **Dynamic metric management:** Global metric definitions + per-device metric configuration, flexibly defining the name, unit, icon, and other metadata of telemetry metrics, with the frontend adapting display automatically.
*   **Dual interfaces:**
    *   **Admin panel** — server-rendered with Webman + LayUI, session auth, for administrators to configure the system and manage devices.
    *   **Mobile frontend** — React 19 + Ant Design Mobile + PWA, JWT auth, for regular users to view environment data and control devices.
*   **Fully containerized:** Start all services with a single Docker Compose command.
*   **Flexible permissions:** Two-layer control with RBAC + location scope.

## Architecture

```
Sensors ──GPIO──► ESP32 Gateway ──MQTT──► EMQX ──► Webman MQTT process ──► Redis Queue ──► PostgreSQL
                                                       │
                                                       ├──► Redis Pub/Sub ──► Webman WS Worker ──► Mobile (real-time data)
                                                       └──► Alert Stream  ──► Alert engine ──► Notifications

Mobile (React) ──HTTP──► Nginx:80 ──► /api/*    ──► Webman HTTP :8787 (REST API)
                                    ├── /ws      ──► Webman WS   :8788 (WebSocket)
                                    ├── /admin/* ──► Webman HTTP :8787 (server-rendered)
                                    └── /mobile/ ──► static files (SPA)
```

## Tech Stack

| Component | Technology | Responsibility |
| :--- | :--- | :--- |
| **Reverse proxy** | Nginx 1.27 | SSL termination, static files, request routing |
| **App backend** | PHP 8.2 / Webman | REST API (:8787), WebSocket (:8788), alert engine |
| **Message broker** | EMQX 5.8 | MQTT broker, handling device connections and messaging |
| **Primary database** | PostgreSQL + TimescaleDB | Persists device info and large-scale time-series data |
| **Cache database** | Redis 7 | Hot data, device state, task queues, Pub/Sub |
| **Admin panel** | LayUI 2.9 + ThinkPHP Template | Server-rendered admin panel, session auth |
| **Mobile** | React 19 + Ant Design Mobile + ECharts | PWA mobile app, JWT auth |
| **Device firmware** | ESP32 / Arduino + PlatformIO | IoT device side, MQTT communication, modular sensor architecture |

## Project Structure

```
Home-Guardian/
├── app/
│   ├── controller/
│   │   ├── admin/          # Admin controllers (session auth)
│   │   └── *.php           # REST API controllers (JWT auth)
│   ├── middleware/          # Middleware (Auth, CORS, Permission, AuditLog, AdminAuth)
│   ├── model/               # Eloquent models
│   ├── service/             # Business logic services
│   ├── process/             # Custom processes (MQTT, WebSocket, alert engine)
│   ├── exception/           # Exception handlers
│   └── view/admin/          # Admin templates (LayUI)
├── config/                  # Webman configuration files
├── database/migrations/     # Base SQL migrations (auto-run on first Docker DB init)
├── database/php-migrations/ # Incremental PHP migrations (webman migrate:run)
├── simulator/               # IoT device simulator (Python + MQTT)
├── firmware/                # ESP32 device firmware (Arduino + PlatformIO)
│   ├── include/             # Headers (config, sensor interface, MQTT, WiFi)
│   ├── src/                 # Sources (main, sensor implementations, command handler)
│   └── platformio.ini       # PlatformIO build config
├── mobile/                  # Mobile React project
│   ├── src/
│   │   ├── api/             # Axios + JWT auto-refresh
│   │   ├── stores/          # Zustand state management
│   │   ├── hooks/           # WebSocket hooks
│   │   ├── utils/           # Utilities (metric lookup, etc.)
│   │   ├── pages/           # Page components
│   │   └── components/      # Shared components
│   ├── package.json
│   └── vite.config.ts
├── public/
│   ├── mobile/              # Mobile build output (npm run build)
│   ├── api-docs.html        # Swagger UI page
│   ├── openapi.yaml         # OpenAPI 3.0 spec (auto-generated)
│   └── favicon.ico
├── docker/nginx/            # Nginx config
├── docker-compose.yml       # Production
└── docker-compose.override.yml # Development
```

## Quick Start

**Prerequisites:**
*   [Docker](https://www.docker.com/get-started) + [Docker Compose](https://docs.docker.com/compose/install/)
*   [Node.js](https://nodejs.org/) >= 18 (required for mobile development)
*   [PlatformIO](https://platformio.org/) (required for ESP32 firmware development)

### Production Deployment

1.  **Clone the repository:**
    ```bash
    git clone https://github.com/Longczx/home-guardian.git
    cd home-guardian
    ```

2.  **Create the environment file:**
    ```bash
    cp .env.example .env
    ```
    *Edit `.env` and change the database password, Redis password, and JWT secret.*

3.  **Build the mobile frontend:**
    ```bash
    cd mobile
    npm install
    npm run build    # output goes to ../public/mobile/
    cd ..
    ```

4.  **Start the services:**
    ```bash
    docker compose -f docker-compose.yml up -d
    ```
    > In production, explicitly pass `-f docker-compose.yml` to skip the override file and enable the Nginx reverse proxy.

5.  **Run incremental migrations:**
    ```bash
    docker exec -it home-guardian-app php webman migrate:run
    ```

6.  **Check service status:**
    *   **Mobile:** open `http://localhost/mobile/`
    *   **Admin panel:** open `http://localhost/admin/login`
    *   **EMQX dashboard:** open `http://localhost:18083` (default `admin` / `public`)

7.  **Create an admin account:**
    ```bash
    docker exec -it home-guardian-app php create_admin.php
    ```
    > On first DB init, a default admin `admin / admin123` is created automatically via `17_seed_admin_user.sql`.

### Development

1.  **Start the backend services:**
    ```bash
    docker compose up
    ```
    > Loads `docker-compose.override.yml` by default: skips Nginx and exposes ports 8787/8788.

2.  **Start the mobile dev server (in another terminal):**
    ```bash
    cd mobile
    npm install
    npm run dev
    ```

3.  **Create an admin account (first time):**
    ```bash
    docker exec -it home-guardian-app php create_admin.php
    ```

4.  **Access:**
    *   **Mobile (HMR):** `http://localhost:5173/mobile/`
    *   **Admin panel:** `http://localhost:8787/admin/login`

**Development request flow:**
```
Mobile :5173
  ├── pages/JS/CSS  →  Vite Dev Server (HMR)
  ├── /api/*        →  Vite proxy → localhost:8787 (Webman)
  └── /ws           →  Vite proxy → localhost:8788 (WebSocket)

Admin panel
  └── direct access to localhost:8787/admin/* (Webman server-rendered)
```

**Development vs. Production:**
| | Development | Production |
|:---|:---|:---|
| Mobile | Vite :5173 (HMR) | Nginx serves static files |
| Admin panel | Webman :8787 direct | Nginx → webman:8787 |
| API proxy | Vite proxy → :8787 | Nginx → webman:8787 |
| Nginx | not started | started |
| Start command | `docker compose up` | `docker compose -f docker-compose.yml up -d` |

## Database Migrations

The project uses a **two-stage migration** strategy:

| Stage | Directory | Method | Notes |
|:---|:---|:---|:---|
| Base schema (00-17) | `database/migrations/` | Plain SQL, auto-run on first Docker DB init | PostgreSQL `docker-entrypoint-initdb.d` mechanism |
| Incremental (18+) | `database/php-migrations/` | PHP migration classes, managed by [leekung/webman-migrations](https://packagist.org/packages/leekung/webman-migrations) | Supports version tracking and rollback |

### First Deployment (fresh database)

On first Docker startup, the SQL in `database/migrations/` runs automatically to create the base schema; then run the incremental migrations:

```bash
docker compose up -d
docker exec -it home-guardian-app php webman migrate:run
```

### Existing Database (upgrade)

A single command — the tool automatically skips already-applied migrations and runs only the new ones:

```bash
docker exec -it home-guardian-app php webman migrate:run
```

### Common Migration Commands

```bash
# Show migration status (which are applied / pending)
php webman migrate:status

# Run all pending migrations
php webman migrate:run

# Roll back the last batch
php webman migrate:rollback

# Create a new migration file
php webman migrate:create AddXxxToYyy

# Reset and re-run all migrations (⚠️ wipes data)
php webman migrate:fresh
```

### Creating a New Migration

Migration files live in `database/php-migrations/` and use a Laravel-style Schema Builder:

```php
<?php
use Illuminate\Database\Schema\Blueprint;
use Eloquent\Migrations\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->create('example', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('example');
    }
};
```

## API Documentation

The project ships an embedded Swagger UI for online API docs, auto-generating an OpenAPI 3.0 spec from [swagger-php](https://github.com/zircote/swagger-php) annotations.

### Access

After starting the services, open in a browser:

```
http://localhost/api/docs        # production (Nginx)
http://localhost:8787/api/docs   # development (direct to Webman)
```

### Interactive Debugging

1. Click the **Authorize** button at the top of the page.
2. Paste the `access_token` returned by the login endpoint.
3. Use **Try it out** in Swagger UI to call any endpoint directly.

### Regenerating the Spec

When controller annotations change, regenerate `public/openapi.yaml`:

```bash
composer openapi
# or
vendor/bin/openapi app/ -o public/openapi.yaml
```

### Coverage

| Module | Endpoints | Notes |
|:---|:---|:---|
| Auth | 5 | Login, logout, token refresh, profile |
| Devices | 6 | CRUD + send control command |
| Device attributes | 3 | Extended attribute read/write |
| Metric definitions | 5 | Global telemetry metric metadata CRUD |
| Telemetry | 3 | Raw data, latest values, aggregated charts |
| Alert rules | 5 | CRUD |
| Alert logs | 4 | Query, acknowledge, resolve |
| Automations | 5 | CRUD |
| Notification channels | 6 | CRUD + test send |
| Command logs | 2 | Query |
| Users | 5 | CRUD + role assignment |
| Roles | 5 | CRUD |
| Dashboards | 5 | CRUD |
| Audit logs | 1 | Query |
| MQTT auth | 2 | EMQX internal callbacks |

## Configuring EMQX Device Authentication

After starting the services, configure HTTP authentication in EMQX so devices can authenticate and connect to the broker.

### 1. Configure Authentication

Open the EMQX Dashboard `http://localhost:18083` → **Access Control** → **Authentication** → **Create**:

| Setting | Value |
|:---|:---|
| Mechanism | Password-Based |
| Backend | HTTP Server |
| Method | POST |
| URL | `http://home-guardian-app:8787/api/mqtt/auth` |
| Body | `{"username": "${username}", "password": "${password}"}` |
| Headers | Content-Type: application/json |

### 2. Configure Authorization

**Access Control** → **Authorization** → **Create**:

| Setting | Value |
|:---|:---|
| Backend | HTTP Server |
| Method | POST |
| URL | `http://home-guardian-app:8787/api/mqtt/acl` |
| Body | `{"username": "${username}", "topic": "${topic}", "action": "${action}"}` |
| Headers | Content-Type: application/json |

### 3. ACL Rules

| Identity | Allowed PUBLISH | Allowed SUBSCRIBE |
|:---|:---|:---|
| Device `esp32-xxx` | `home/upstream/esp32-xxx/#` | `home/downstream/esp32-xxx/#` |
| Webman internal client | `home/downstream/#` | `home/upstream/#` |

### 4. Device Connection Example (Arduino/ESP32)

```cpp
// MQTT connection parameters
const char* mqtt_server = "your-server-ip";
const int   mqtt_port   = 1883;
const char* mqtt_user   = "esp32-livingroom-01";   // matches devices.mqtt_username
const char* mqtt_pass   = "your-device-password";  // plaintext; server stores a bcrypt hash

client.connect("esp32-livingroom-01", mqtt_user, mqtt_pass);

// This device may only operate the following topics:
// PUBLISH:   home/upstream/esp32-livingroom-01/telemetry/post
// SUBSCRIBE: home/downstream/esp32-livingroom-01/command/set
```

## ESP32 Device Firmware

The project includes ESP32 firmware (`firmware/`), built on the Arduino framework + PlatformIO. It uses a **gateway + sensor** architecture: the ESP32 acts as a communication gateway and reports telemetry and state independently on behalf of the sensor devices mounted under it.

### Gateway + Sensor Model

```
                    Admin panel
                    ┌─────────────────────────────┐
                    │ Gateway: gw-001 (type=gateway)│
                    │   ├─ sensor-temp-01 (DHT11)  │
                    │   └─ sensor-sound-01 (Sound) │
                    └─────────────────────────────┘

ESP32 (board)                     EMQX Broker                 Home Guardian
┌──────────────┐                 ┌───────────┐               ┌────────────┐
│ gw-001       │───MQTT auth────►│           │               │            │
│  ├ DHT11     │  sensor-temp-01 │           │──telemetry──►│ separate   │
│  └ Sound     │  sensor-sound-01│           │──state─────►│ devices    │
│              │  gw-001 (LWT)   │           │──offline───►│ batch down │
└──────────────┘                 └───────────┘               └────────────┘
```

- **Gateway device:** `type=gateway` in Admin, owns MQTT credentials, firmware flashed onto the ESP32.
- **Sensor devices:** `type=sensor` in Admin, assigned to a gateway, each with its own device_uid, online state, and alert rules.
- **Gateway goes offline** (LWT triggered) → all its sensors are automatically marked offline in batch.
- **A single sensor fails** (e.g. a loose DHT11 wire) → only that sensor goes offline; others are unaffected.

### Supported Sensor Modules

| Module | Sensor | Reported metrics | Pin |
|:---|:---|:---|:---|
| DHT11 | Temp/humidity sensor | `temperature`, `humidity` | GPIO4 |
| Sound | Sound sensor (analog out) | `noise_db` | GPIO34 (ADC1) |

To add a new sensor, implement the `ISensor` interface (`begin` + `read` + `uid`) and register it in `main.cpp`.

### Quick Start

1. **Create devices in Admin:**
    - Create a gateway device: `type=gateway`, set an MQTT password.
    - Create sensor devices: `type=sensor`, choose the owning gateway, configure `metric_fields`.

2. **Generate firmware config:**
    Admin → edit the gateway device → click "Generate firmware config" → fill in WiFi and MQTT password → copy `config.h`.

3. **Flash:**
    ```bash
    cd firmware
    pip install platformio       # first time
    # paste config.h into include/config.h
    pio run -t upload            # build & flash
    pio device monitor           # view serial logs
    ```

4. **Verify:**
    Power on the device → the gateway and all sensors show online in Admin → the dashboard and mobile app display telemetry in real time.

### Built-in Commands

| Command | Description |
|:---|:---|
| `ping` | Connectivity test, returns ok |
| `get_info` | Returns firmware version, gateway UID, uptime, free heap, WiFi RSSI, sensor count |
| `reboot` | Remotely reboot the ESP32 |

### Wiring Reference

```
ESP32-WROOM-32          DHT11           Sound sensor
┌─────────────┐    ┌──────────┐     ┌──────────┐
│         3V3 ├────┤ VCC      │     │ VCC      ├── 3V3
│         GND ├────┤ GND      │     │ GND      ├── GND
│       GPIO4 ├────┤ DATA     │     │ AO       ├── GPIO34
└─────────────┘    └──────────┘     └──────────┘
```

## Device Simulator

The project includes a Python-based IoT device simulator (`simulator/`) that mimics real devices reporting telemetry over MQTT, exercising the full data path: EMQX → Webman MQTT process → database.

### Manual Mode (default)

Edit the `DEVICES` list in `simulator.py` with the device UIDs and MQTT passwords you created in the Admin panel:

```bash
cd simulator
pip install -r requirements.txt
python simulator.py
```

### API Auto-Discovery Mode

Automatically fetches all devices and their `metric_fields` config from the REST API, and generates telemetry dynamically based on the metric definitions:

```bash
# set environment variables
export API_BASE_URL=http://127.0.0.1:8787/api
export API_USERNAME=admin
export API_PASSWORD=admin123
export DEFAULT_MQTT_PASSWORD=pass123

python simulator.py --api
```

The simulator generates realistic data (sine wave + random noise) for each metric of each device, runs one thread per device, and supports full MQTT features such as LWT (last will) messages and downstream command responses.

## Roadmap

- [x] Core technology selection and architecture design
- [x] Database schema design (18 SQL + incremental PHP migrations)
- [x] MQTT topic naming convention
- [x] Alert system implementation design
- [x] Permission system design (RBAC + location scope)
- [x] Authentication system design (JWT dual-token + session)
- [x] Docker Compose environment
- [x] Web API development (16 REST controllers, 50+ endpoints)
- [x] WebSocket real-time push service
- [x] Admin panel (LayUI server-rendered)
- [x] Mobile frontend (React 19 + Ant Design Mobile + PWA)
- [x] Online API docs (Swagger UI + swagger-php annotations)
- [x] Global metric definitions + per-device metric config + simulated data generation
- [x] ESP32 device firmware (Arduino + PlatformIO, modular sensor architecture)
- [ ] **Next: more sensor/actuator modules + OTA remote upgrades**

## Contributing

Contributions of any kind are welcome! You can:
1.  Submit a bug report.
2.  Suggest a new feature.
3.  Fork the repository and open a pull request.

See [CONTRIBUTING.md](CONTRIBUTING.md) for development setup and guidelines, and [SECURITY.md](SECURITY.md) to report vulnerabilities.

## License

This project is open-sourced under the MIT License.
