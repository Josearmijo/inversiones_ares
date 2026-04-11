<?php
require_once 'database.php';

header('Content-Type: application/json');

$user_id = $_GET['user_id'] ?? $_POST['user_id'] ?? null;
$user_type = $_GET['user_type'] ?? '';
$condominio_db = $_GET['condominio_db'] ?? '';
$condominio_id = $_GET['condominio_id'] ?? null;

if (!$user_id) {
    echo json_encode(['error' => 'Usuario no especificado']);
    exit;
}

// Si es locatario, necesita la DB del condominio
if ($user_type === 'locatario') {
    if (empty($condominio_db)) {
        echo json_encode(['error' => 'Condominio no especificado']);
        exit;
    }
    
    $condominioConn = connectCondominioDB($condominio_db);
    if (!$condominioConn) {
        echo json_encode(['error' => 'No se pudo conectar al condominio']);
        exit;
    }
    
    try {
        // Obtener inmuebles del locatario
        $stmt = $condominioConn->prepare("
            SELECT i.id, i.nombre, i.alicuota_porcentaje, i.estado
            FROM inmuebles i
            JOIN locatarios_inmuebles li ON i.id = li.inmueble_id
            WHERE li.locatario_id = ? AND li.activo = 1
            ORDER BY i.nombre
        ");
        $stmt->execute([$user_id]);
        $inmuebles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Obtener deuda de cada inmueble
        foreach ($inmuebles as &$inmueble) {
            $inmueble['id'] = (int)$inmueble['id'];
            
            // Obtener deuda mensual actual (calcular desde columnas mensuales)
            $anioActual = date('Y');
            $stmt = $condominioConn->prepare("
                SELECT dm.anio, 
                       dm.enero + dm.febrero + dm.marzo + dm.abril + dm.mayo + dm.junio + 
                       dm.julio + dm.agosto + dm.septiembre + dm.octubre + dm.noviembre + dm.diciembre as total_deuda
                FROM deudas_mensuales dm
                WHERE dm.id_inmueble = ? AND dm.id_locatario = ? 
                AND dm.anio = ?
                LIMIT 12
            ");
            $stmt->execute([$inmueble['id'], $user_id, $anioActual]);
            $deudas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $total_deuda = 0;
            foreach ($deudas as $deuda) {
                $total_deuda += floatval($deuda['total_deuda'] ?? 0);
            }
            
            $inmueble['deudas'] = $deudas;
            $inmueble['total_deuda'] = $total_deuda;
        }
        
        echo json_encode([
            'success' => true,
            'inmuebles' => $inmuebles
        ]);
        
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// Si es admin, asistente o superadmin - obtener lista de condominios
if (in_array($user_type, ['superadmin', 'administrador', 'asistente'])) {
    try {
        $stmt = $conn->query("
            SELECT id, nombre, db_name, direccion, tipo, num_inmuebles, activo 
            FROM condominios 
            WHERE activo = 1 
            ORDER BY nombre
        ");
        $condominios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'condominios' => $condominios
        ]);
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['error' => 'Tipo de usuario no válido']);
