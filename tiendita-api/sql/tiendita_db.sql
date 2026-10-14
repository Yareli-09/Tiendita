-- Script de apoyo. Si tu base tiendita_db YA existe con estas tablas, NO necesitas ejecutar la parte de CREATE.
-- Lo importante para la API es el bloque de PERMISOS del final.

CREATE DATABASE IF NOT EXISTS tiendita_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE tiendita_db;

CREATE TABLE IF NOT EXISTS categoria (
  id     INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS producto (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  nombre       VARCHAR(100)  NOT NULL,
  precio       DECIMAL(10,2) NOT NULL DEFAULT 0,
  stock        INT           NOT NULL DEFAULT 0,
  categoria_id INT           NOT NULL,
  CONSTRAINT fk_producto_categoria FOREIGN KEY (categoria_id) REFERENCES categoria(id)
) ENGINE=InnoDB;

-- Datos de muestra (opcional)
INSERT IGNORE INTO categoria (nombre) VALUES ('Periféricos'), ('Abarrotes'), ('Bebidas');

-- PERMISOS: la app original solo leía; la API también escribe (INSERT/UPDATE/DELETE).
-- Ejecuta esto como root/admin en MySQL/MariaDB:
GRANT SELECT, INSERT, UPDATE, DELETE ON tiendita_db.producto  TO 'app_webuser'@'localhost';
GRANT SELECT, INSERT                  ON tiendita_db.categoria TO 'app_webuser'@'localhost';
FLUSH PRIVILEGES;
