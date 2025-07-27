# 🛡️ Dependency Scan System

This is a Laravel 12 application that allows authenticated users to upload multiple dependency files, scan them via [Debricked's API](https://debricked.com), and receive vulnerability summaries through queued background jobs and notifications.

---

## 🧰 Requirements

- **PHP**: 8.3
- **Laravel**: 12
- **PostgreSQL**: ≥ 16.0
- **Redis** (for queues and caching)
- **Docker** (recommended for development)

---

## ⚙️ Project Setup (Local)

### 1. Clone the repo

```bash
git clone <your-repo-url>
cd <your-project>
```

### 2. Install PHP dependencies

```bash
composer install
```

### 3. Setup environment variables

```bash
cp .env.example .env
php artisan key:generate
```

Edit your `.env` file:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=your_db
DB_USERNAME=your_user
DB_PASSWORD=your_pass

QUEUE_CONNECTION=redis

DEBRICKED_API_USERNAME=your_debricked_username
DEBRICKED_API_PASSWORD=your_debricked_password

SLACK_WEBHOOK_URL=https://hooks.slack.com/services/xxxxxxxxx/xxxxxxxxxxxxxxxxx
```

### 4. Clear and optimize cache

```bash
php artisan optimize:clear
php artisan optimize
```

### 5. Run migrations

```bash
php artisan migrate:fresh
```

### 6. Serve the application

```bash
php artisan serve
```

---

## 🔁 Queues and Scheduling

### 7. Start the queue worker

```bash
php artisan queue:work
```

### 8. Start the schedule worker

```bash
php artisan schedule:work
```
### 9. Manually trigger a scan

To manually trigger the dependency scan, run:

```bash
php artisan app:dependency-scan
```
---

## 🧪 Run Tests

```bash
php artisan test
```

---

## 🐳 Docker Setup (Optional)

### 1. Start containers

```bash
docker compose up -d
```

### 2. Access container

```bash
docker exec -it <container-id> bash
```

### 3. Run composer install

```bash
composer install
```

### 4. Set permissions

```bash
chmod -R 777 /var/www/html/storage /var/www/html/bootstrap/cache
```

### 5. Stop containers

```bash
docker compose down
```

---

## 🔌 API Usage

A Postman collection (`Opentext.postman_collection.json`) is included.

### Steps:

1. Register a user: `POST /api/auth/register`
2. Login to get token: `POST /api/auth/login`
3. Upload files: `POST /api/dependency-uploads`
   - Header: `Authorization: Bearer <token>`
   - Payload: Form-data with multiple `files[]`
   - `commit_name`: Name of the commit (Required)
   - `repository_name`: Name of the repository (Required)

---

## 🧠 How It Works

- User registers and logs in.
- File upload via `POST /api/dependency-uploads` triggers a queued job.
- `DependencyUploadJob` uploads files to Debricked and queues scans.
- Scan status is monitored using a scheduled command.
- Notifications are sent for scan started, failed, and completed states.

---

## 📌 Tips

- Use Laravel Horizon for queue monitoring (if Redis is used).
- Set up a mail provider to receive email notifications.
- Use `php artisan config:cache` and `php artisan route:cache` only in production.

---

