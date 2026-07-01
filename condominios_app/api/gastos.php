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
    
    $sql .= " ORDER BY g.anio DESC, FIELD(g.mes, 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre') DESC, g.fecha_registro DESC";
    
    $stmt = $condominioConn->prepare($sql);
    $stmt->execute($params);
    $gastos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Eliminar duplicados basándose en mes, anio e inmueble_id (quedarse con el primero que es el más reciente)
    $seen = [];
    $gastos_unicos = [];
    foreach ($gastos as $g) {
        $key = $g['mes'] . '-' . $g['anio'] . '-' . $g['inmueble_id'];
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $gastos_unicos[] = $g;
        }
    }
    
    echo json_encode([
        'success' => true,
        'gastos' => $gastos_unicos,
        'total' => count($gastos_unicos)
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
}