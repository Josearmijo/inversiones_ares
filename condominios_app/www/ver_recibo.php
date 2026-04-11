<?php
$debug_log = [];

function logDebug($msg) {
    global $debug_log;
    $debug_log[] = $msg;
}

require_once '../api/database.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

logDebug('Script started');
logDebug('Token: ' . ($_GET['token'] ?? 'N/A'));
logDebug('ID: ' . ($_GET['id'] ?? 'N/A'));

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache');

$token = $_GET['token'] ?? '';
$pago_id = $_GET['id'] ?? null;

if (!$token || !$pago_id) {
    echo '<html><head><title>Error</title><style>body{font-family:Arial;padding:20px;background:#f5f5f5;}pre{background:#1a1a2e;color:#0f0;padding:15px;border-radius:8px;white-space:pre-wrap;}</style></head><body>';
    echo '<h1 style="color:#dc3545;">Error: Token o ID no proporcionado</h1>';
    echo '<pre>DEBUG:<br>' . implode("\n", $debug_log) . '</pre>';
    exit;
}

try {
    logDebug('Starting try block');
    
    $decoded = base64_decode($token);
    logDebug('Decoded token: ' . $decoded);
    
    if (!$decoded || strpos($decoded, ':') === false) {
        echo '<html><head><title>Error</title><style>body{font-family:Arial;padding:20px;background:#f5f5f5;}pre{background:#1a1a2e;color:#0f0;padding:15px;border-radius:8px;white-space:pre-wrap;}</style></head><body>';
        echo '<h1 style="color:#dc3545;">Error: Token inválido</h1>';
        echo '<pre>DEBUG:<br>' . implode("\n", $debug_log) . '</pre>';
        exit;
    }
    
    list($user_id, $db_name) = explode(':', $decoded, 2);
    logDebug("user_id: $user_id, db_name: $db_name");
    
    if (empty($db_name)) {
        echo '<html><head><title>Error</title><style>body{font-family:Arial;padding:20px;background:#f5f5f5;}pre{background:#1a1a2e;color:#0f0;padding:15px;border-radius:8px;white-space:pre-wrap;}</style></head><body>';
        echo '<h1 style="color:#dc3545;">Error: Nombre de BD no encontrado</h1>';
        echo '<pre>DEBUG:<br>' . implode("\n", $debug_log) . '</pre>';
        exit;
    }
    
    logDebug("Connecting to: $db_name");
    $condominioConn = connectCondominioDB($db_name);
    if (!$condominioConn) {
        echo '<html><head><title>Error</title><style>body{font-family:Arial;padding:20px;background:#f5f5f5;}pre{background:#1a1a2e;color:#0f0;padding:15px;border-radius:8px;white-space:pre-wrap;}</style></head><body>';
        echo '<h1 style="color:#dc3545;">Error: No se pudo conectar al condominio</h1>';
        echo '<pre>DEBUG:<br>' . implode("\n", $debug_log) . '</pre>';
        exit;
    }
    logDebug('Connected successfully');
    
    logDebug("Looking for pago_id: $pago_id");
    $stmt = $condominioConn->prepare("
        SELECT id_pago_base, es_abono
        FROM ingresos 
        WHERE id_pago = ? AND pago_verificado = 1
    ");
    $stmt->execute([$pago_id]);
    $pago_info = $stmt->fetch(PDO::FETCH_ASSOC);
    logDebug('pago_info: ' . json_encode($pago_info));
    
    if (!$pago_info) {
        echo '<html><head><title>Error</title><style>body{font-family:Arial;padding:20px;background:#f5f5f5;}pre{background:#1a1a2e;color:#0f0;padding:15px;border-radius:8px;white-space:pre-wrap;}</style></head><body>';
        echo '<h1 style="color:#dc3545;">Error: Pago no encontrado o no verificado</h1>';
        echo '<pre>DEBUG:<br>' . implode("\n", $debug_log) . '</pre>';
        exit;
    }
    
    $numero_recibo = $pago_info['id_pago_base'] ?: $pago_id;
    
    $query = "
        SELECT
            i.*,
            CONCAT(l.nombre, ' ', COALESCE(l.apellido, '')) AS nombre_locatario,
            l.identificacion,
            inm.nombre AS nombre_inmueble,
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
        die('<html><head><title>Error</title><style>body{font-family:Arial;padding:20px;text-align:center;background:#f5f5f5;}h1{color:#dc3545;}</style></head><body><h1>No se encontró el pago</h1><a href="javascript:window.close()">Cerrar</a></body></html>');
    }
    
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
    
    $stmt = $conn->prepare("SELECT nombre, rif, direccion FROM condominios WHERE db_name = ?");
    $stmt->execute([$db_name]);
    $condominio = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
        'nombre' => 'Condominio',
        'rif' => 'J-XXXXXXXXX-X',
        'direccion' => 'Dirección no especificada'
    ];
    
    function safeText($text) {
        return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
    }
    
    function formatNumber($num) {
        return number_format($num ?? 0, 2, ',', '.');
    }
    
    function getMesPagoDisplay($mes_pago_json) {
        if (empty($mes_pago_json)) return 'Sin especificar';
        $meses = json_decode($mes_pago_json, true);
        if (!is_array($meses)) return $mes_pago_json;
        return implode(', ', array_map(function($m) {
            return ($m['mes'] ?? 'Mes') . ' ' . ($m['anio'] ?? '');
        }, $meses));
    }
    
} catch (Exception $e) {
    echo '<html><head><title>Error</title><style>body{font-family:Arial;padding:20px;background:#f5f5f5;}pre{background:#1a1a2e;color:#0f0;padding:15px;border-radius:8px;white-space:pre-wrap;}</style></head><body>';
    echo '<h1 style="color:#dc3545;">Error: ' . htmlspecialchars($e->getMessage()) . '</h1>';
    echo '<pre>DEBUG:<br>' . implode("\n", $debug_log) . '</pre>';
    echo '<pre style="background:#dc3545;color:white;margin-top:10px;">EXCEPTION: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . '</pre>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recibo de Pago - <?= safeText($condominio['nombre']) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f5f5f5; padding: 10px; }
        .recibo-container { max-width: 600px; margin: 0 auto; }
        .recibo { background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); overflow: hidden; margin-bottom: 20px; }
        .recibo-header { background: linear-gradient(135deg, #28a745, #1e7e34); color: white; padding: 20px; text-align: center; }
        .recibo-header h1 { font-size: 22px; margin-bottom: 5px; }
        .recibo-header .numero { font-size: 28px; font-weight: bold; }
        .recibo-header .tipo { font-size: 14px; opacity: 0.9; margin-top: 5px; }
        
        .condominio-info { background: #f8f9fa; padding: 15px; border-bottom: 2px solid #28a745; }
        .condominio-info h2 { color: #28a745; font-size: 18px; margin-bottom: 5px; }
        .condominio-info .rif { color: #666; font-size: 14px; }
        .condominio-info .direccion { color: #888; font-size: 12px; margin-top: 5px; }
        
        .datos-pago { padding: 20px; }
        .dato-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; }
        .dato-row .label { color: #666; font-weight: 500; }
        .dato-row .value { color: #333; font-weight: 600; }
        
        .seccion { margin-top: 15px; padding: 15px; background: #f8f9fa; border-radius: 8px; }
        .seccion h3 { color: #28a745; font-size: 14px; margin-bottom: 10px; display: flex; align-items: center; gap: 8px; }
        .mes-item { padding: 8px; background: white; border-radius: 5px; margin-bottom: 5px; display: flex; justify-content: space-between; }
        .mes-item .mes { font-weight: 600; color: #333; }
        .mes-item .monto { color: #28a745; font-weight: bold; }
        
        .total-box { background: #28a745; color: white; padding: 20px; text-align: center; border-radius: 8px; margin-top: 15px; }
        .total-box .label { font-size: 14px; opacity: 0.9; }
        .total-box .monto { font-size: 32px; font-weight: bold; margin-top: 5px; }
        .total-box .equiv { font-size: 14px; opacity: 0.9; margin-top: 5px; }
        
        .firma-box { margin-top: 30px; padding: 20px; display: flex; justify-content: space-between; }
        .firma { text-align: center; width: 45%; }
        .firma .line { border-top: 1px solid #333; padding-top: 10px; margin-top: 40px; }
        .firma .nombre { font-weight: 600; margin-top: 5px; }
        
        .btn-print { position: fixed; bottom: 20px; right: 20px; background: #28a745; color: white; border: none; padding: 15px 25px; border-radius: 50px; font-size: 16px; box-shadow: 0 4px 15px rgba(40,167,69,0.4); cursor: pointer; }
        .btn-print:hover { background: #218838; }
        
        @media print {
            .btn-print { display: none; }
            body { background: white; }
            .recibo { box-shadow: none; }
        }
    </style>
</head>
<body>
    <div class="recibo-container">
        <div class="recibo">
            <div class="recibo-header">
                <h1><i class="fas fa-file-invoice"></i> RECIBO DE INGRESO</h1>
                <div class="numero">Nº <?= $numero_recibo ?></div>
                <div class="tipo">ORIGINAL</div>
            </div>
            
            <div class="condominio-info">
                <h2><i class="fas fa-building"></i> <?= safeText($condominio['nombre']) ?></h2>
                <div class="rif">RIF: <?= safeText($condominio['rif']) ?></div>
                <div class="direccion"><?= safeText($condominio['direccion'] ?? 'Dirección no especificada') ?></div>
            </div>
            
            <div class="datos-pago">
                <div class="dato-row">
                    <span class="label"><i class="fas fa-calendar"></i> Fecha:</span>
                    <span class="value"><?= safeText($pago_principal['fecha_pago_formateada']) ?></span>
                </div>
                <div class="dato-row">
                    <span class="label"><i class="fas fa-user"></i> Locatario:</span>
                    <span class="value"><?= safeText($pago_principal['nombre_locatario']) ?></span>
                </div>
                <div class="dato-row">
                    <span class="label"><i class="fas fa-id-card"></i> Identificación:</span>
                    <span class="value"><?= safeText($pago_principal['identificacion']) ?></span>
                </div>
                <div class="dato-row">
                    <span class="label"><i class="fas fa-home"></i> Inmueble:</span>
                    <span class="value"><?= safeText($pago_principal['nombre_inmueble']) ?></span>
                </div>
                <div class="dato-row">
                    <span class="label"><i class="fas fa-money-bill"></i> Forma de Pago:</span>
                    <span class="value"><?= safeText($pago_principal['tipo_pago']) ?></span>
                </div>
                <?php if(!empty($pago_principal['referencia_op'])): ?>
                <div class="dato-row">
                    <span class="label"><i class="fas fa-hashtag"></i> Referencia:</span>
                    <span class="value"><?= safeText($pago_principal['referencia_op']) ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if(!empty($pagos_completos)): ?>
            <div class="seccion">
                <h3><i class="fas fa-check-circle"></i> Pagos Completos</h3>
                <?php foreach($pagos_completos as $pago): ?>
                <div class="mes-item">
                    <span class="mes"><?= getMesPagoDisplay($pago['mes_pago']) ?></span>
                    <span class="monto">$<?= formatNumber($pago['total_pagar']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <?php if(!empty($abonos)): ?>
            <div class="seccion">
                <h3><i class="fas fa-minus-circle"></i> Abonos Parciales</h3>
                <?php foreach($abonos as $abono): ?>
                <div class="mes-item">
                    <span class="mes"><?= getMesPagoDisplay($abono['mes_pago']) ?></span>
                    <span class="monto">$<?= formatNumber($abono['total_pagar']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <div class="datos-pago">
                <div class="total-box">
                    <div class="label">TOTAL PAGADO</div>
                    <?php if(in_array($pago_principal['tipo_pago'], ['Transferencia', 'Pago Movil', 'Otros'])): ?>
                    <div class="monto"><?= formatNumber($total_bolivares) ?> Bs</div>
                    <div class="equiv">Equivalente: $<?= formatNumber($total_pagar) ?> USD (Tasa: <?= formatNumber($pago_principal['tasa_cambio']) ?>)</div>
                    <?php else: ?>
                    <div class="monto">$<?= formatNumber($total_pagar) ?> USD</div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="firma-box">
                <div class="firma">
                    <div class="line"></div>
                    <div class="nombre">Firma Locatario</div>
                </div>
                <div class="firma">
                    <div class="line"></div>
                    <div class="nombre">Rafael A. Contreras L.</div>
                </div>
            </div>
        </div>
        
        <div style="text-align:center;color:#666;font-size:12px;margin-bottom:60px;">
            <p>PAGO POR CUENTA DE TERCEROS</p>
            <p>A NOMBRE DE: JUNTA DE CONDOMINIO <?= safeText($condominio['nombre']) ?> RIF: <?= safeText($condominio['rif']) ?></p>
        </div>
    </div>
    
    <button class="btn-print" onclick="window.print()">
        <i class="fas fa-print"></i> Imprimir
    </button>
</body>
</html>