<?php
$conn = new PDO("mysql:host=localhost;dbname=inversiones_ares_db", "root", "");
$conn->exec("ALTER TABLE usuarios ADD COLUMN locatario_id INT NULL AFTER activo");
echo "Columna agregada";