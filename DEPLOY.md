# Deploy `project-flash` To Ubuntu Server

Tài liệu này tổng hợp lại flow deploy cho repo hiện tại với cấu hình:

- Server Ubuntu
- Code đặt tại `/var/www/flash`
- Backend Laravel ở `flash-be`
- Frontend React/Vite ở `flash-fe`
- Database dùng SQLite
- Web server dùng Nginx + PHP-FPM

## 1. Chuẩn bị SSH key và clone repo

Tạo SSH key trên server:

```bash
mkdir -p ~/.ssh
chmod 700 ~/.ssh
ssh-keygen -t ed25519 -C "server-deploy" -f ~/.ssh/id_ed25519
eval "$(ssh-agent -s)"
ssh-add ~/.ssh/id_ed25519
cat ~/.ssh/id_ed25519.pub
```

Copy public key ở trên và thêm vào GitHub/GitLab.

Test kết nối:

```bash
ssh -T git@github.com
```

Clone code:

```bash
cd /var/www
git clone git@github.com:<your-user>/<your-repo>.git flash
cd /var/www/flash
```

## 2. Cài package hệ thống

Ví dụ với Ubuntu và PHP 8.3:

```bash
apt update
apt install -y nginx git unzip curl sqlite3 ca-certificates software-properties-common
apt install -y php8.3 php8.3-cli php8.3-fpm php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath php8.3-sqlite3
```

Cài Composer:

```bash
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer
```

Cài Node.js 20:

```bash
apt remove -y nodejs npm
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt install -y nodejs
```

Kiểm tra:

```bash
php -v
php -m | grep -i sqlite
composer -V
node -v
npm -v
nginx -v
```

Node phải là `20.19+` hoặc mới hơn. PHP phải có `pdo_sqlite` và `sqlite3`.

## 3. Setup backend Laravel

Đi vào backend:

```bash
cd /var/www/flash/flash-be
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Tạo file SQLite:

```bash
mkdir -p /var/www/flash/flash-be/database
touch /var/www/flash/flash-be/database/database.sqlite
chown www-data:www-data /var/www/flash/flash-be/database/database.sqlite
chmod 664 /var/www/flash/flash-be/database/database.sqlite
```

Sửa file `.env`:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=http://your-server-ip

DB_CONNECTION=sqlite
DB_DATABASE=/var/www/flash/flash-be/database/database.sqlite
```

Nên bỏ hoặc comment các biến MySQL nếu còn.

Clear config và migrate:

```bash
php artisan config:clear
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Set quyền thư mục Laravel:

```bash
chown -R www-data:www-data /var/www/flash/flash-be/storage
chown -R www-data:www-data /var/www/flash/flash-be/bootstrap/cache
chmod -R 775 /var/www/flash/flash-be/storage
chmod -R 775 /var/www/flash/flash-be/bootstrap/cache
```

## 4. Setup frontend React

```bash
cd /var/www/flash/flash-fe
rm -rf node_modules package-lock.json
npm install
npm run build
```

Build thành công sẽ tạo ra:

```bash
/var/www/flash/flash-fe/dist
```

## 5. Setup PHP-FPM và Nginx

Enable service:

```bash
systemctl enable php8.3-fpm
systemctl enable nginx
systemctl start php8.3-fpm
systemctl start nginx
```

Tạo file Nginx:

```bash
nano /etc/nginx/sites-available/flash
```

Nội dung mẫu:

```nginx
server {
    listen 80;
    server_name _;

    root /var/www/flash/flash-fe/dist;
    index index.html;

    location / {
        try_files $uri $uri/ /index.html;
    }

    location /api {
        root /var/www/flash/flash-be/public;
        try_files $uri /index.php?$query_string;
    }

    location = /index.php {
        root /var/www/flash/flash-be/public;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME /var/www/flash/flash-be/public/index.php;
    }

    location ~ \.php$ {
        return 404;
    }

    client_max_body_size 20M;
}
```

Enable site:

```bash
ln -sf /etc/nginx/sites-available/flash /etc/nginx/sites-enabled/flash
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl restart nginx
```

## 6. Kiểm tra sau deploy

Kiểm tra local trên server:

```bash
curl -I http://127.0.0.1
curl http://127.0.0.1/api/flashes
ss -ltnp | grep ':80'
systemctl status nginx
systemctl status php8.3-fpm
```

Nếu ổn, mở ngoài trình duyệt:

```text
http://your-server-ip
http://your-server-ip/api/flashes
```

## 7. Mở firewall / cloud firewall

Nếu trình duyệt báo `ERR_CONNECTION_REFUSED` nhưng `curl http://127.0.0.1` trên server vẫn chạy, kiểm tra:

- UFW
- Cloud firewall của DigitalOcean/VPS provider

Ví dụ với UFW:

```bash
ufw allow 80
ufw allow 443
ufw status
```

Nếu dùng DigitalOcean thì cần mở inbound:

- TCP 80
- TCP 443
- TCP 22

## 8. Quy trình deploy khi cập nhật code

```bash
cd /var/www/flash
git pull

cd /var/www/flash/flash-be
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

cd /var/www/flash/flash-fe
npm install
npm run build

systemctl restart php8.3-fpm
systemctl reload nginx
```

## 9. Lệnh debug hay dùng

Kiểm tra DB SQLite:

```bash
ls -l /var/www/flash/flash-be/database/database.sqlite
php artisan tinker
```

Trong `tinker`:

```php
config('database.connections.sqlite.database');
```

Kiểm tra log Laravel:

```bash
tail -f /var/www/flash/flash-be/storage/logs/laravel.log
```

Kiểm tra log Nginx:

```bash
tail -f /var/log/nginx/error.log
tail -f /var/log/nginx/access.log
```

Kiểm tra PHP extension:

```bash
php -m | grep -E 'sqlite|pdo_sqlite'
```

## 10. Các lỗi đã gặp trong quá trình setup

### `Database file ... does not exist`

Nguyên nhân:

- `DB_DATABASE` trong `.env` đang trỏ sai path

Cách sửa:

```env
DB_CONNECTION=sqlite
DB_DATABASE=/var/www/flash/flash-be/database/database.sqlite
```

### `could not find driver`

Nguyên nhân:

- PHP chưa cài `pdo_sqlite`

Cách sửa:

```bash
apt install -y php8.3-sqlite3 sqlite3
```

### `Vite requires Node.js version 20.19+`

Nguyên nhân:

- Server đang dùng Node 18

Cách sửa:

```bash
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt install -y nodejs
```

### `ERR_CONNECTION_REFUSED`

Nếu `nginx` đang chạy và `curl http://127.0.0.1` trả `200`, thì lỗi nằm ở:

- cloud firewall
- security group
- inbound port 80/443 chưa mở

