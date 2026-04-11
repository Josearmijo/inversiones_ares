<?php
require_once 'database.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

$token = $_GET['token'] ?? '';
$pago_id = $_GET['id'] ?? null;

if (!$token || !$pago_id) {
    echo json_encode(['error' => 'Token y ID de pago requeridos']);
    exit;
}

try {
    $decoded = base64_decode($token);
    if (!$decoded || strpos($decoded, ':') === false) {
        echo json_encode(['error' => 'Token inválido']);
        exit;
    }
    
    list($user_id, $db_name) = explode(':', $decoded, 2);
    
    $debug = [];
    $debug[] = "Token decodificado: user_id=$user_id, db_name=$db_name";
    
    if (empty($db_name)) {
        echo json_encode(['error' => 'Nombre de base de datos no encontrado en token', 'debug' => $debug]);
        exit;
    }
    
    $condominioConn = connectCondominioDB($db_name);
    if (!$condominioConn) {
        echo json_encode(['error' => 'No se pudo conectar al condominio', 'debug' => $debug]);
        exit;
    }
    
    $debug[] = "Conectado a: $db_name";
    
    $stmt = $condominioConn->prepare("
        SELECT id_pago_base, id_pago_relacionado, es_abono
        FROM ingresos 
        WHERE id_pago = ?
    ");
    $stmt->execute([$pago_id]);
    $pago_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$pago_info) {
        echo json_encode(['error' => 'Pago no encontrado', 'debug' => $debug]);
        exit;
    }
    
    $debug[] = "Pago info: " . json_encode($pago_info);
    
    $numero_recibo = $pago_info['id_pago_base'] ?: $pago_id;
    
    $query = "
        SELECT
            i.*,
            CONCAT(l.nombre, ' ', COALESCE(l.apellido, '')) AS nombre_locatario,
            l.identificacion,
            l.telefono,
            l.email,
            inm.nombre AS nombre_inmueble,
            inm.direccion AS direccion_inmueble,
            DATE_FORMAT(i.fecha_pago, '%d/%m/%Y') AS fecha_pago_formateada,
            i.mes_pago,
            i.es_abono,
            i.id_pago_base,
            i.tasa_cambio,
            i.total_bolivares,
            i.total_dolares
        FROM ingresos i
        JOIN locatarios l ON i.id_locatario = l.id
        JOIN inmuebles inm ON i.id_inmueble = inm.id
        WHERE (i.id_pago_base = ? OR i.id_pago = ?) AND i.pago_verificado = 1
        ORDER BY i.es_abono ASC, i.id_pago ASC
    ";
    
    $stmt = $condominioConn->prepare($query);
    $stmt->execute([$numero_recibo, $numero_recibo]);
    $pagos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($pagos)) {
        echo json_encode(['error' => 'No se encontró el pago o no está verificado', 'debug' => $debug]);
        exit;
    }
    
    $debug[] = "Pagos encontrados: " . count($pagos);
    
    $pagos_completos = [];
    $abonos = [];
    
    foreach ($pagos as $pago) {
        if ($pago['es_abono']) {
            $abonos[] = $pago;
        } else {
            $pagos_completos[] = $pago;
        }
    }
    
    $pago_principal = !empty($pagos_completos) ? $pagos_completos[0] : $abonos[0];
    
    $total_pagar = 0;
    $total_bolivares = 0;
    
    foreach ($pagos as $pago) {
        $total_pagar += $pago['total_pagar'];
        $total_bolivares += $pago['total_bolivares'] ?? 0;
    }
    
    $debug[] = "Total pagar: $total_pagar, Total bs: $total_bolivares";
    
    $stmt = $conn->prepare("SELECT nombre, rif, direccion, telefono, email FROM condominios WHERE db_name = ?");
    $stmt->execute([$db_name]);
    $condominio = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$condominio) {
        $condominio = [
            'nombre' => 'Condominio',
            'rif' => 'J-XXXXXXXXX-X',
            'direccion' => 'Dirección no especificada',
            'telefono' => '',
            'email' => ''
        ];
    }
    
    echo json_encode([
        'success' => true,
        'recibo' => [
            'numero' => $numero_recibo,
            'condominio_nombre' => $condominio['nombre'],
            'condominio_rif' => $condominio['rif'],
            'condominio_direccion' => $condominio['direccion'],
            'condominio_telefono' => $condominio['telefono'],
            'condominio_email' => $condominio['email'],
            'fecha_pago' => $pago_principal['fecha_pago_formateada'],
            'nombre_locatario' => $pago_principal['nombre_locatario'],
            'identificacion' => $pago_principal['identificacion'],
            'nombre_inmueble' => $pago_principal['nombre_inmueble'],
            'tipo_pago' => $pago_principal['tipo_pago'],
            'referencia' => $pago_principal['referencia_op'] ?? '',
            'total_pagar' => $total_pagar,
            'total_bolivares' => $total_bolivares,
            'tasa_cambio' => $pago_principal['tasa_cambio'] ?? 0,
            'pagos' => $pagos,
            'pagos_completos' => $pagos_completos,
            'abonos' => $abonos
        ],
        'debug' => $debug
    ]);
    
} catch (Exception $e) {
    echo json_encode(['error' => 'Error: ' . $e->getMessage(), 'trace' => $e->getTraceAsString()]);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Error BD: ' . $e->getMessage()]);
}