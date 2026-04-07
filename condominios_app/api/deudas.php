<?php
require_once 'database.php';

header('Content-Type: application/json');

$user_id = $_GET['user_id'] ?? $_POST['user_id'] ?? null;
$user_type = $_GET['user_type'] ?? '';
$condominio_db = $_GET['condominio_db'] ?? '';
$inmueble_id = $_GET['inmueble_id'] ?? null;

$debug = [];

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
    if ($user_type === 'locatario') {
        $inmueble_id = $inmueble_id ? (int)$inmueble_id : null;
        $debug[] = "User ID: $user_id, Inmueble ID: $inmueble_id";
        
        // Primero obtener los inmuebles del locatario
        $stmtInmuebles = $condominioConn->prepare("
            SELECT li.inmueble_id 
            FROM locatarios_inmuebles li
            WHERE li.locatario_id = ? AND li.activo = 1
        ");
        $stmtInmuebles->execute([$user_id]);
        $inmueblesIds = $stmtInmuebles->fetchAll(PDO::FETCH_COLUMN);
        
        $debug[] = "Inmuebles del locatario: " . implode(',', $inmueblesIds);
        
        // Obtener TODOS los campos de gastos del locatario (todos sus inmuebles)
        // Solo el último registro por mes/año para cada inmueble
        $sql = "
            SELECT g.id, g.mes, g.anio, g.total_a_pagar_mes, g.condominio, g.fondo_reserva, g.fecha_registro,
                   g.salarios_personal_mantenimiento, g.prestaciones_sociales, g.servicio_administrativo,
                   g.servicio_agua, g.servicio_corpoelec, g.productos_limpieza, g.mantenimiento,
                   g.comisiones_bancarias, g.total_gastos_fijos, g.mantenimiento_insfrastutura,
                   g.total_gastos_variables_pagar, g.servicio_television, g.servicio_internet,
                   g.area_comun, g.alquiler_area_comun, g.porcentaje_fondo_reserva,
                   inm.nombre as inmueble_nombre
            FROM gastos g
            INNER JOIN (
                SELECT mes, anio, MAX(fecha_registro) as max_fecha, inmueble_id
                FROM gastos
                GROUP BY mes, anio, inmueble_id
            ) ultimos ON g.mes = ultimos.mes 
                      AND g.anio = ultimos.anio
                      AND g.fecha_registro = ultimos.max_fecha
                      AND g.inmueble_id = ultimos.inmueble_id
            LEFT JOIN inmuebles inm ON g.inmueble_id = inm.id
        ";
        
        $params = [];
        
        // Filtrar por inmueble específico si se proporciona
        if (!empty($inmueble_id)) {
            $sql .= " WHERE g.inmueble_id = ?";
            $params[] = $inmueble_id;
        } 
        // Si no hay inmueble específico, filtrar por los inmuebles del locatario
        elseif (!empty($inmueblesIds)) {
            $placeholders = implode(',', array_fill(0, count($inmueblesIds), '?'));
            $sql .= " WHERE g.inmueble_id IN ($placeholders)";
            $params = $inmueblesIds;
        } else {
            $sql .= " WHERE 1=1";
            $debug[] = "ADVERTENCIA: Locatario sin inmuebles asignados";
        }
        
        $sql .= " ORDER BY g.anio DESC, FIELD(g.mes, 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre') DESC LIMIT 50";
        
        $debug[] = "SQL final: " . substr($sql, 0, 300);
        $debug[] = "Params: " . json_encode($params);
        $debug[] = "inmueble_id original: " . $_GET['inmueble_id'] ?? 'not set';
        $debug[] = "inmueble_id procesed: " . $inmueble_id;
        
        $debug[] = "Query ejecutada";
        
        $stmt = $condominioConn->prepare($sql);
        $stmt->execute($params);
        $gastos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $debug[] = "Gastos encontrados: " . count($gastos);
        
        // Si hay pocos resultados, mostrar qué propiedades tienen gastos
        if (count($gastos) < 5) {
            $debug[] = "Verificando otros inmuebles con gastos...";
            $stmtCheck = $condominioConn->query("
                SELECT DISTINCT g.inmueble_id, inm.nombre as nombre, COUNT(*) as total
                FROM gastos g
                LEFT JOIN inmuebles inm ON g.inmueble_id = inm.id
                GROUP BY g.inmueble_id
                LIMIT 10
            ");
            $otrosGastos = $stmtCheck->fetchAll(PDO::FETCH_ASSOC);
            $debug[] = "Otros inmuebles con gastos: " . json_encode($otrosGastos);
        }
        
        if (count($gastos) > 0) {
            $debug[] = "Primero 3 registros: ";
            foreach (array_slice($gastos, 0, 3) as $g) {
                $debug[] = "- " . $g['mes'] . " " . $g['anio'] . ": total=" . $g['total_a_pagar_mes'];
            }
        }
        
        // Calcular totales
        $total_deuda = 0;
        foreach ($gastos as $g) {
            $total_deuda += floatval($g['total_a_pagar_mes'] ?? 0);
        }
        
        echo json_encode([
            'success' => true,
            'deudas' => $gastos,
            'resumen' => [
                'total_deuda' => $total_deuda,
                'meses_pendientes' => count($gastos)
            ],
            'debug' => $debug
        ]);
    }
} catch (PDOException $e) {
    $debug[] = "ERROR: " . $e->getMessage();
    echo json_encode(['error' => 'Error: ' . $e->getMessage(), 'debug' => $debug]);
}
