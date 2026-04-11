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
            echo json_encode(['error' => 'No hay inmuebles asignados', 'debug' => $debug]);
            exit;
        }
        
        $inmueblesIds = array_column($inmuebles, 'inmueble_id');
        $debug[] = "Inmuebles del locatario: " . json_encode($inmueblesIds);
        
        // Obtener deuda desde deudas_mensuales
        $sql = "
            SELECT dm.id, dm.id_inmueble, dm.id_locatario, dm.anio,
                   dm.enero, dm.febrero, dm.marzo, dm.abril, dm.mayo, dm.junio,
                   dm.julio, dm.agosto, dm.septiembre, dm.octubre, dm.noviembre, dm.diciembre,
                   dm.deudas_anteriores, dm.total_deuda,
                   inm.nombre as nombre_inmueble
            FROM deudas_mensuales dm
            LEFT JOIN inmuebles inm ON dm.id_inmueble = inm.id
            WHERE dm.id_locatario = ?
        ";
        
        $params = [$user_id];
        
        if (!empty($inmueble_id)) {
            $sql .= " AND dm.id_inmueble = ?";
            $params[] = $inmueble_id;
        } elseif (!empty($inmueblesIds)) {
            $placeholders = implode(',', array_fill(0, count($inmueblesIds), '?'));
            $sql .= " AND dm.id_inmueble IN ($placeholders)";
            $params = array_merge($params, $inmueblesIds);
        }
        
        $sql .= " ORDER BY dm.anio DESC";
        
        $stmt = $condominioConn->prepare($sql);
        $stmt->execute($params);
        $deudas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $debug[] = "Deudas encontradas: " . count($deudas);
        
        // Convertir las deudas mensuales a formato de lista
        $gastos = [];
        $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        $meses_nombres = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
        
        foreach ($deudas as $deuda) {
            $anio = $deuda['anio'];
            
            // Incluir deudas_anteriores si hay
            if (floatval($deuda['deudas_anteriores']) > 0) {
                $gastos[] = [
                    'id' => $deuda['id'],
                    'mes' => 'Deudas Anteriores',
                    'anio' => $anio,
                    'total_a_pagar_mes' => $deuda['deudas_anteriores'],
                    'id_inmueble' => $deuda['id_inmueble'],
                    'inmueble_nombre' => $deuda['nombre_inmueble'],
                    'estado' => 'pendiente'
                ];
            }
            
            // Incluir cada mes con deuda
            foreach ($meses as $idx => $mes) {
                $monto = floatval($deuda[$mes]);
                if ($monto > 0) {
                    $gastos[] = [
                        'id' => $deuda['id'],
                        'mes' => $meses_nombres[$idx],
                        'anio' => $anio,
                        'total_a_pagar_mes' => $monto,
                        'id_inmueble' => $deuda['id_inmueble'],
                        'inmueble_nombre' => $deuda['nombre_inmueble'],
                        'estado' => 'pendiente'
                    ];
                }
            }
        }
        
        // Ordenar por año y mes descendente
        usort($gastos, function($a, $b) {
            if ($a['anio'] != $b['anio']) return $b['anio'] - $a['anio'];
            $meses_ord = ['Enero'=>1,'Febrero'=>2,'Marzo'=>3,'Abril'=>4,'Mayo'=>5,'Junio'=>6,'Julio'=>7,'Agosto'=>8,'Septiembre'=>9,'Octubre'=>10,'Noviembre'=>11,'Diciembre'=>12,'Deudas Anteriores'=>0];
            return ($meses_ord[$b['mes']] ?? 0) - ($meses_ord[$a['mes']] ?? 0);
        });
        
        $debug[] = "Gastos convertidos: " . count($gastos);
        
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
