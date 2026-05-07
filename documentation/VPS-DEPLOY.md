# VPS deploy + systemd + web installer (Hyperlocal backend)

Paths below use `/var/www/hyperlocal/chirag-nanital-backend-web` and port **8080** (Nginx `proxy_pass` to this port). Change if your server differs.

---

## 1) Server packages (Ubuntu example)

```bash
sudo apt update
sudo apt install -y nginx git unzip curl mysql-server php8.3-fpm php8.3-cli php8.3-mysql php8.3-xml php8.3-mbstring php8.3-curl php8.3-zip php8.3-gd php8.3-intl composer
```

Confirm PHP binary:

```bash
which php
php -v
```

If `ExecStart` uses `/usr/bin/php`, ensure it matches `which php` (adjust the `.service` file if needed).

---

## 2) MySQL: empty database

```bash
sudo mysql -e "CREATE DATABASE IF NOT EXISTS your_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER IF NOT EXISTS 'your_user'@'localhost' IDENTIFIED BY 'your_password';"
sudo mysql -e "GRANT ALL PRIVILEGES ON your_db.* TO 'your_user'@'localhost'; FLUSH PRIVILEGES;"
```

Replace `your_db`, `your_user`, `your_password`.

---

## 3) Code + Composer

```bash
sudo mkdir -p /var/www/hyperlocal
sudo chown -R $USER:www-data /var/www/hyperlocal
cd /var/www/hyperlocal
git clone https://github.com/rparit-stacks/chirag-nanital-backend-web.git chirag-nanital-backend-web
cd chirag-nanital-backend-web
git pull origin main
composer install --no-dev --optimize-autoloader
```

---

## 4) `.env` (installer se pehle minimum)

```bash
cp .env.example .env
php artisan key:generate
nano .env
```

Set at least:

- `APP_NAME`, `APP_ENV=production`, `APP_DEBUG=false`
- `APP_URL=https://your-domain.com` (HTTPS agar Nginx + SSL use kar rahe ho)
- `DB_*` → same DB/user/password as MySQL step
- `SESSION_DRIVER=database` (recommended after install; installer last step may switch this — ok)

**Web installer use karoge to:** abhi **`php artisan migrate` mat chalao** — installer **Database** step par migrations + seed run karta hai. Sirf **khali database** hona chahiye.

---

## 5) Permissions (Laravel + installer)

```bash
cd /var/www/hyperlocal/chirag-nanital-backend-web
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rwx storage bootstrap/cache
```

Agar `public/installer` assets chahiye ho to (usually already in repo):

```bash
php artisan vendor:publish --tag=laravel-assets --force 2>/dev/null || true
```

---

## 6) Systemd: `artisan serve` on 8080 (always on)

Copy unit file from repo:

```bash
sudo cp deploy/systemd/hyperlocal-serve.service /etc/systemd/system/hyperlocal-serve.service
sudo nano /etc/systemd/system/hyperlocal-serve.service
```

Check **`WorkingDirectory`**, **`ExecStart`** (`/usr/bin/php` vs `php8.3`), then:

```bash
sudo systemctl daemon-reload
sudo systemctl enable hyperlocal-serve
sudo systemctl start hyperlocal-serve
sudo systemctl status hyperlocal-serve --no-pager
```

Logs:

```bash
journalctl -u hyperlocal-serve -f
```

---

## 7) Queue worker (optional, recommended)

```bash
sudo cp deploy/systemd/hyperlocal-queue.service /etc/systemd/system/hyperlocal-queue.service
sudo nano /etc/systemd/system/hyperlocal-queue.service
sudo systemctl daemon-reload
sudo systemctl enable hyperlocal-queue
sudo systemctl start hyperlocal-queue
```

---

## 8) Nginx + SSL

- `proxy_pass http://127.0.0.1:8080;`
- Certbot: `sudo certbot --nginx -d your-domain.com`

---

## 9) Run **web installer**

1. Ensure **`storage/installed` file does NOT exist** (fresh install).
2. Open: `https://your-domain.com/install`
3. Follow steps (license → environment → … → database).  
   Installer DB step migrations run karta hai — **pehle se `migrate` na chala ho**.

After success, `storage/installed` is created and `/install` routes return 404 (expected).

---

## 10) **Agar installer use nahi kar rahe** (CLI only)

```bash
php artisan migrate --force
php artisan db:seed --force   # only if you have seeders and want them
php artisan storage:link
touch storage/installed      # marks app installed; only if you skip web wizard
php artisan config:cache
php artisan route:cache
```

---

## 11) Sessions table note

Default Laravel migrations create **`sessions`** when you run migrations (via installer DB step or `php artisan migrate`).  
`SESSION_DRIVER=database` tab kaam karega jab `sessions` table exist kare.

---

## 12) Deploy update (code push ke baad)

```bash
cd /var/www/hyperlocal/chirag-nanital-backend-web
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
sudo systemctl restart hyperlocal-serve
sudo systemctl restart hyperlocal-queue
```

---

## Production note

Long-term **`php artisan serve`** ko replace karna better hai **PHP-FPM + Nginx** se (performance). Tab `hyperlocal-serve.service` disable karke FPM listen karo.
