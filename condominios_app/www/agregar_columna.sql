-- Agregar columna locatario_id a la tabla usuarios
-- Ejecutar en phpMyAdmin o cliente MySQL

ALTER TABLE usuarios ADD COLUMN locatario_id INT NULL AFTER activo;