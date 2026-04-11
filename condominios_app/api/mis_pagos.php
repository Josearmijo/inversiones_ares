<?php
require_once 'database.php';

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

$debug = [];

$user_id = $_GET['user_id'] ?? $_POST['user_id'] ?? null;
$user_type = $_GET['user_type'] ?? '';
$condominio_db = $_GET['condominio_db'] ?? '';
$inmueble_id = $_GET['inmueble_id'] ?? null;

$debug[] = "Params: user_id=$user_id, user_type=$user_type, condominio_db=$condominio_db, inmueble_id=$inmueble_id";

if (!$user_id || !$condominio_db) {
    echo json_encode(['error' => 'Parámetros incompletos', 'debug' => $debug]);
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
        
        $stmtInmuebles = $condominioConn->prepare("
            SELECT li.inmueble_id, i.nombre as nombre_inmueble
            FROM locatarios_inmuebles li
            JOIN inmuebles i ON li.inmueble_id = i.id
            WHERE li.locatario_id = ? AND li.activo = 1
        ");
        $stmtInmuebles->execute([$user_id]);
        $inmuebles = $stmtInmuebles->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($inmuebles)) {
            echo json_encode(['error' => 'No hay inmuebles asignados']);
            exit;
        }
        
        $inmueblesIds = array_column($inmuebles, 'inmueble_id');
        
        // Obtener pagos APROBADOS
        $sql_aprobados = "
            SELECT i.id_pago, i.id_inmueble, i.fecha_pago, i.monto_mes, i.total_pagar, 
                   i.tipo_pago, i.referencia_op, i.es_abono, i.captura_pago, 
                   i.pago_verificado, i.usuario_verifica, i.mes_pago, i.total_bolivares, i.total_dolares,
                   inm.nombre as nombre_inmueble
            FROM ingresos i
            LEFT JOIN inmuebles inm ON i.id_inmueble = inm.id
            WHERE i.id_locatario = ? AND i.pago_verificado = 1
        ";
        
        // Obtener pagos PENDIENTES
        $sql_pendientes = "
            SELECT i.id_pago, i.id_inmueble, i.fecha_pago, i.monto_mes, i.total_pagar, 
                   i.tipo_pago, i.referencia_op, i.es_abono, i.captura_pago, 
                   i.pago_verificado, i.usuario_verifica, i.mes_pago, i.total_bolivares, i.total_dolares,
                   inm.nombre as nombre_inmueble
            FROM ingresos i
            LEFT JOIN inmuebles inm ON i.id_inmueble = inm.id
            WHERE i.id_locatario = ? AND (i.pago_verificado = 0 OR i.pago_verificado IS NULL OR i.pago_verificado = '')
        ";
        
        $condicion_inmueble = "";
        if (!empty($inmueble_id)) {
            $condicion_inmueble = " AND i.id_inmueble = ?";
        } elseif (!empty($inmueblesIds)) {
            $placeholders = implode(',', array_fill(0, count($inmueblesIds), '?'));
            $condicion_inmueble = " AND i.id_inmueble IN ($placeholders)";
        }
        
        $sql_aprobados .= $condicion_inmueble . " ORDER BY i.fecha_pago DESC, i.id_pago DESC";
        $sql_pendientes .= $condicion_inmueble . " ORDER BY i.fecha_pago DESC, i.id_pago DESC";
        
        // Ejecutar consulta de aprobados
        $params_aprobados = [$user_id];
        if (!empty($inmueble_id)) {
            $params_aprobados[] = $inmueble_id;
        } elseif (!empty($inmueblesIds)) {
            $params_aprobados = array_merge($params_aprobados, $inmueblesIds);
        }
        
        $stmt = $condominioConn->prepare($sql_aprobados);
        $stmt->execute($params_aprobados);
        $pagos_aprobados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($pagos_aprobados as &$pago) {
            $pago['estado'] = 'aprobado';
            if (!empty($pago['mes_pago'])) {
                $meses_decoded = json_decode($pago['mes_pago'], true);
                if (is_array($meses_decoded)) {
                    $pago['meses_detalles'] = $meses_decoded;
                    $pago['mes_pago_display'] = implode(', ', array_map(function($m) {
                        return $m['mes'] . ' ' . $m['anio'];
                    }, $meses_decoded));
                }
            }
        }
        
        // Ejecutar consulta de pendientes
        $params_pendientes = [$user_id];
        if (!empty($inmueble_id)) {
            $params_pendientes[] = $inmueble_id;
        } elseif (!empty($inmueblesIds)) {
            $params_pendientes = array_merge($params_pendientes, $inmueblesIds);
        }
        
        $stmt = $condominioConn->prepare($sql_pendientes);
        $stmt->execute($params_pendientes);
        $pagos_pendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($pagos_pendientes as &$pago) {
            $pago['estado'] = 'pendiente';
            if (!empty($pago['mes_pago'])) {
                $meses_decoded = json_decode($pago['mes_pago'], true);
                if (is_array($meses_decoded)) {
                    $pago['meses_detalles'] = $meses_decoded;
                    $pago['mes_pago_display'] = implode(', ', array_map(function($m) {
                        return $m['mes'] . ' ' . $m['anio'];
                    }, $meses_decoded));
                }
            }
        }
        
        echo json_encode([
            'success' => true,
            'pagos_aprobados' => $pagos_aprobados,
            'pagos_pendientes' => $pagos_pendientes,
            'total_aprobados' => count($pagos_aprobados),
            'total_pendientes' => count($pagos_pendientes)
        ]);
        exit;
    }
} catch (PDOException $e) {
    echo json_encode(['error' => 'Error: ' . $e->getMessage(), 'trace' => $e->getTraceAsString()]);
}