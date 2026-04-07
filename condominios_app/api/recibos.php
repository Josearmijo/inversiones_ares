<?php
require_once 'database.php';

header('Content-Type: application/json');

$user_id = $_GET['user_id'] ?? null;
$user_type = $_GET['user_type'] ?? '';
$condominio_db = $_GET['condominio_db'] ?? '';
$inmueble_id = $_GET['inmueble_id'] ?? null;
$anio = $_GET['anio'] ?? '';

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
        $debug[] = "User ID: $user_id, Año: $anio, Inmueble: $inmueble_id";
        
        // Obtener pagos verificados desde ingresos
        $sqlIngresos = "
            SELECT i.id_pago as id, i.fecha_pago, i.concepto, i.mes_pago as mes_aplicado, 
                   i.total_pagar as monto, i.tipo_pago, i.referencia_op,
                   'verificado' as estado, NULL as motivo_rechazo,
                   inm.nombre as nombre_inmueble
            FROM ingresos i
            LEFT JOIN inmuebles inm ON i.id_inmueble = inm.id
            WHERE i.id_locatario = ?
        ";
        
        $params = [$user_id];
        
        if ($inmueble_id) {
            $sqlIngresos .= " AND i.id_inmueble = ?";
            $params[] = $inmueble_id;
        }
        
        if (!empty($anio)) {
            $sqlIngresos .= " AND YEAR(i.fecha_pago) = ?";
            $params[] = $anio;
        }
        
        $sqlIngresos .= " ORDER BY i.fecha_pago DESC LIMIT 50";
        
        $debug[] = "Buscando ingresos...";
        
        $stmt = $condominioConn->prepare($sqlIngresos);
        $stmt->execute($params);
        $ingresos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $debug[] = "Ingresos encontrados: " . count($ingresos);
        
        // Verificar si existe la tabla pagos_pendientes
        try {
            $checkTable = $condominioConn->query("SELECT 1 FROM pagos_pendientes LIMIT 1");
            $tablaPendientes = true;
        } catch (Exception $e) {
            $tablaPendientes = false;
        }
        
        $pagos = $ingresos;
        
        if ($tablaPendientes) {
            // Obtener pagos pendientes
            $sqlPendientes = "
                SELECT pp.id as id_pago, pp.fecha_pago, pp.concepto, pp.mes_pago as mes_aplicado, 
                       pp.monto, pp.tipo_pago, pp.referencia_op,
                       pp.estado, pp.motivo_rechazo,
                       inm.nombre as nombre_inmueble
                FROM pagos_pendientes pp
                LEFT JOIN inmuebles inm ON pp.id_inmueble = inm.id
                WHERE pp.id_locatario = ?
            ";
            
            $params2 = [$user_id];
            
            if ($inmueble_id) {
                $sqlPendientes .= " AND pp.id_inmueble = ?";
                $params2[] = $inmueble_id;
            }
            
            if (!empty($anio)) {
                $sqlPendientes .= " AND YEAR(pp.fecha_pago) = ?";
                $params2[] = $anio;
            }
            
            $sqlPendientes .= " ORDER BY pp.fecha_pago DESC LIMIT 50";
            
            $stmt = $condominioConn->prepare($sqlPendientes);
            $stmt->execute($params2);
            $pendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $pagos = array_merge($pagos, $pendientes);
            $debug[] = "Pendientes encontrados: " . count($pendientes);
        }
        
        // Ordenar por fecha
        usort($pagos, function($a, $b) {
            return strtotime($b['fecha_pago']) - strtotime($a['fecha_pago']);
        });
        
        // Obtener años disponibles (especificar la tabla)
        $debug[] = "Buscando años...";
        try {
            $stmt = $condominioConn->prepare("
                SELECT DISTINCT YEAR(i.fecha_pago) as anio
                FROM ingresos i
                WHERE i.id_locatario = ?
                ORDER BY anio DESC
            ");
            $stmt->execute([$user_id]);
            $anios = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $debug[] = "Años encontrados: " . count($anios);
        } catch (Exception $e) {
            $debug[] = "Error años: " . $e->getMessage();
            $anios = [];
        }
        
        echo json_encode([
            'success' => true,
            'pagos' => $pagos,
            'anios' => array_column($anios, 'anio'),
            'anio_actual' => !empty($anio) ? (int)$anio : (isset($anios[0]) ? (int)$anios[0]['anio'] : date('Y')),
            'debug' => $debug
        ]);
    }
} catch (PDOException $e) {
    $debug[] = "ERROR: " . $e->getMessage();
    echo json_encode(['error' => 'Error: ' . $e->getMessage(), 'debug' => $debug]);
}
