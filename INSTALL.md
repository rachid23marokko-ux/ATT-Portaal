# ATT Portaal v2.1.0

Complete update voor authenticatie en gebruikersbeheer.

## Installatie

```bash
sudo cp -a /var/www/att-address-portal /var/www/att-address-portal-backup-v2.0
sudo cp -a ./* /var/www/att-address-portal/
sudo mariadb att_portaal < /var/www/att-address-portal/database/update-v2.1.0.sql
find /var/www/att-address-portal -name '*.php' -print0 | xargs -0 -n1 php -l
sudo chown -R client_30445_1:www-data /var/www/att-address-portal
sudo systemctl restart php8.5-fpm
sudo systemctl reload nginx
```

De bestaande bestanden `config/config.php` en `config/mail.php` blijven behouden.
