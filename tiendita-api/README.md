# Tiendita API RESTful (PHP + MySQL/MariaDB)

API REST del recurso **productos**. Sin frontend: solo JSON.

Base URL: `http://<IP_PUBLICA>/api/v1/productos`

| Método | Ruta                     | Acción                  | Éxito       | Error        |
|--------|--------------------------|-------------------------|-------------|--------------|
| GET    | /api/v1/productos        | Lista completa          | 200 OK      | 500          |
| GET    | /api/v1/productos/{id}   | Un registro por ID      | 200 OK      | 404          |
| POST   | /api/v1/productos        | Crear (JSON body)       | 201 Created | 400          |
| PUT    | /api/v1/productos/{id}   | Actualizar (JSON body)  | 200 OK      | 400 / 404    |
| DELETE | /api/v1/productos/{id}   | Eliminar                | 200 OK      | 404          |

Campos: `nombre`, `precio`, `stock` y `categoria` (nombre, se crea si no existe) **o** `categoria_id`.

## Despliegue en OCI (Apache + PHP, mismo servidor de tu app)

1. **Copiar la API** a la VM (conserva tu `index.php` actual en la raíz):
   ```bash
   sudo cp -r api /var/www/html/api
   sudo nano /var/www/html/api/config.php     # credenciales de tu BD
   ```
2. **Permisos de BD** (la app original solo leía): ejecuta el bloque GRANT de `sql/tiendita_db.sql`.
3. **Habilitar reescritura de rutas**:
   - Ubuntu: `sudo a2enmod rewrite`, y en `/etc/apache2/apache2.conf` pon `AllowOverride All` en `<Directory /var/www/>`; luego `sudo systemctl restart apache2`.
   - Oracle Linux: en `/etc/httpd/conf/httpd.conf` pon `AllowOverride All` en `<Directory "/var/www/html">`; luego `sudo systemctl restart httpd`.
4. **Abrir el puerto 80** (son DOS candados):
   - Consola OCI → tu VCN → Security List (o NSG) → *Add Ingress Rule*: Source `0.0.0.0/0`, TCP, puerto destino `80`.
   - Firewall de la VM:
     - Ubuntu: `sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 80 -j ACCEPT && sudo netfilter-persistent save`
     - Oracle Linux: `sudo firewall-cmd --permanent --add-service=http && sudo firewall-cmd --reload`
5. **Probar desde tu PC**: `curl http://<IP_PUBLICA>/api/v1/productos`

## Probar en local (opcional)
```bash
php -S localhost:8000 api/index.php
# baseUrl = http://localhost:8000
```

## Thunder Client
1. Collections → ⋯ → **Import** → `thunder-collection_tiendita-api.json`.
2. Env → New Environment → variable `baseUrl` = `http://<IP_PUBLICA>` (sin `/` final) → actívalo.
3. Ejecuta las 5 peticiones. (Las de ID usan `1`; cámbialo por un ID existente.)
