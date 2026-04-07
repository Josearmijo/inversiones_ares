<?php
require_once 'database.php';

header('Content-Type: application/json');

$user_id = $_GET['user_id'] ?? $_POST['user_id'] ?? null;
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
    if ($user_type === 'locatario') {
        $inmueble_id = $inmueble_id ? (int)$inmueble_id : null;
        
        // Obtener los inmuebles del locatario
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
        
        // Obtener pagos desde ingresos
        $sql = "
            SELECT i.id_pago, i.id_inmueble, i.fecha_pago, i.monto_mes, i.total_pagar, 
                   i.tipo_pago, i.referencia_op, i.es_abono, i.captura_pago, 
                   i.pago_verificado, i.usuario_verifica, i.mes_pago, i.total_bolivares, i.total_dolares,
                   inm.nombre as nombre_inmueble
            FROM ingresos i
            LEFT JOIN inmuebles inm ON i.id_inmueble = inm.id
            WHERE i.id_locatario = ?
        ";
        
        $params = [$user_id];
        
        if (!empty($inmueble_id)) {
            $sql .= " AND i.id_inmueble = ?";
            $params[] = $inmueble_id;
        } elseif (!empty($inmueblesIds)) {
            $placeholders = implode(',', array_fill(0, count($inmueblesIds), '?'));
            $sql .= " AND i.id_inmueble IN ($placeholders)";
            $params = array_merge($params, $inmueblesIds);
        }
        
        $sql .= " ORDER BY i.fecha_pago DESC, i.id_pago DESC";
        
        $stmt = $condominioConn->prepare($sql);
        $stmt->execute($params);
        $pagos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Agregar información de estado
        foreach ($pagos as &$pago) {
            if ($pago['pago_verificado'] == true || $pago['pago_verificado'] === '1' || $pago['pago_verificado'] === true) {
                $pago['estado'] = 'aprobado';
            } else {
                $pago['estado'] = 'pendiente';
            }
            // Decodificar mes_pago si es JSON
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
            'pagos' => $pagos,
            'total' => count($pagos)
        ]);
    }
} catch (PDOException $e) {
    echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
}
