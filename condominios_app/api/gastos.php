<?php
require_once 'database.php';

header('Content-Type: application/json');

$user_id = $_GET['user_id'] ?? null;
$user_type = $_GET['user_type'] ?? '';
$condominio_db = $_GET['condominio_db'] ?? '';
$inmueble_id = $_GET['inmueble_id'] ?? null;

if (!$user_id || !$condominio_db) {
    echo json_encode(['error' => 'Parámetros incompletos']);
    exit;
}

$condominioConn = connectCondominioDB($condominio_db);
if (!$condominioConn) {
    echo json_encode(['error' => 'No se pudo conectar al condominio']);
    exit;
}

try {
    $params = [];
    $sql = "
        SELECT g.id, g.mes, g.anio, g.total_a_pagar_mes, g.condominio, g.fondo_reserva, g.fecha_registro,
               g.inmueble_id, inm.nombre as inmueble_nombre
        FROM gastos g
        LEFT JOIN inmuebles inm ON g.inmueble_id = inm.id
        WHERE 1=1
    ";
    
    if (!empty($inmueble_id)) {
        $sql .= " AND g.inmueble_id = ?";
        $params[] = $inmueble_id;
    }
    
    $sql .= " ORDER BY g.anio DESC, FIELD(g.mes, 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre') DESC";
    
    $stmt = $condominioConn->prepare($sql);
    $stmt->execute($params);
    $gastos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'gastos' => $gastos,
        'total' => count($gastos)
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
}