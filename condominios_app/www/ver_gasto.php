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
logDebug('Gasto ID: ' . ($_GET['id'] ?? 'N/A'));

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache');

$token = $_GET['token'] ?? '';
$gasto_id = $_GET['id'] ?? null;

if (!$token || !$gasto_id) {
    echo '<html><head><title>Error</title><style>body{font-family:Arial;padding:20px;background:#f5f5f5;}pre{background:#1a1a2e;color:#0f0;padding:15px;border-radius:8px;white-space:pre-wrap;}</style></head><body>';
    echo '<h1 style="color:#dc3545;">Error: Token o ID de gasto no proporcionado</h1>';
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
    
    logDebug("Looking for gasto_id: $gasto_id");
    $query = "
        SELECT g.*, i.nombre AS nombre_inmueble, i.alicuota_porcentaje,
        DATE_FORMAT(g.fecha_registro, '%d/%m/%Y') AS fecha_registro_formateada
        FROM gastos g
        LEFT JOIN inmuebles i ON g.inmueble_id = i.id
        WHERE g.id = ?
    ";
    
    $stmt = $condominioConn->prepare($query);
    $stmt->execute([$gasto_id]);
    $gasto = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$gasto) {
        echo '<html><head><title>Error</title><style>body{font-family:Arial;padding:20px;background:#f5f5f5;}pre{background:#1a1a2e;color:#0f0;padding:15px;border-radius:8px;white-space:pre-wrap;}</style></head><body>';
        echo '<h1 style="color:#dc3545;">Error: Gasto no encontrado</h1>';
        echo '<pre>DEBUG:<br>' . implode("\n", $debug_log) . '</pre>';
        exit;
    }
    
    logDebug('Gasto encontrado: ' . $gasto['mes'] . ' ' . $gasto['anio']);
    
    $gastos_extra = json_decode($gasto['gastos_extra'] ?? '[]', true) ?: [
        'gastos_fijos_extra' => [],
        'gastos_variables_extra' => [],
        'otros_gastos_extra' => [],
        'mas_gastos' => []
    ];
    
    logDebug('Gastos extra: ' . json_encode($gastos_extra));
    
    $stmt = $conn->prepare("SELECT nombre, rif, direccion FROM condominios WHERE db_name = ?");
    $stmt->execute([$db_name]);
    $condominio = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
        'nombre' => 'Condominio',
        'rif' => 'J-XXXXXXXXX-X',
        'direccion' => 'Dirección no especificada'
    ];
    
    logDebug('Condominio: ' . $condominio['nombre']);
    
    function safeText($text) {
        return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
    }
    
    function formatNumber($num) {
        return number_format($num ?? 0, 2, ',', '.');
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
    <title>Recibo de Cobro - <?= safeText($gasto['mes']) ?> <?= safeText($gasto['anio']) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f5f5f5; padding: 10px; }
        .recibo-container { max-width: 600px; margin: 0 auto; }
        .recibo { background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); overflow: hidden; margin-bottom: 20px; }
        .recibo-header { background: linear-gradient(135deg, #3966b1, #2d4f8b); color: white; padding: 20px; text-align: center; }
        .recibo-header h1 { font-size: 22px; margin-bottom: 5px; }
        .recibo-header .numero { font-size: 24px; font-weight: bold; }
        .recibo-header .periodo { font-size: 14px; opacity: 0.9; margin-top: 5px; }
        
        .condominio-info { background: #f8f9fa; padding: 15px; border-bottom: 2px solid #3966b1; }
        .condominio-info h2 { color: #3966b1; font-size: 18px; margin-bottom: 5px; }
        .condominio-info .rif { color: #666; font-size: 14px; }
        .condominio-info .direccion { color: #888; font-size: 12px; margin-top: 5px; }
        
        .datos-pago { padding: 20px; }
        .dato-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; }
        .dato-row .label { color: #666; font-weight: 500; }
        .dato-row .value { color: #333; font-weight: 600; }
        
        .seccion { margin-top: 15px; padding: 15px; background: #f8f9fa; border-radius: 8px; }
        .seccion h3 { color: #3966b1; font-size: 14px; margin-bottom: 10px; display: flex; align-items: center; gap: 8px; border-bottom: 2px solid #3966b1; padding-bottom: 8px; }
        .item-row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #eee; }
        .item-row .concepto { color: #333; }
        .item-row .monto { color: #333; font-weight: 500; }
        .item-row.total { background: #2e7d32; color: white; padding: 10px; border-radius: 5px; margin-top: 10px; font-weight: bold; }
        .item-row.total .monto { color: white; }
        
        .item-row.alicuota { background: #c8e6c9; padding: 10px; border-radius: 5px; margin-top: 10px; }
        
        .total-box { background: #3966b1; color: white; padding: 20px; text-align: center; border-radius: 8px; margin-top: 15px; }
        .total-box .label { font-size: 14px; opacity: 0.9; }
        .total-box .monto { font-size: 32px; font-weight: bold; margin-top: 5px; }
        
        .firma-box { margin-top: 30px; padding: 20px; display: flex; justify-content: space-between; }
        .firma { text-align: center; width: 45%; }
        .firma .line { border-top: 1px solid #333; padding-top: 10px; margin-top: 40px; }
        .firma .nombre { font-weight: 600; margin-top: 5px; }
        
        .observaciones { margin-top: 15px; padding: 15px; background: #fff3cd; border-radius: 8px; border-left: 4px solid #ffc107; }
        .observaciones h4 { color: #856404; margin-bottom: 8px; }
        .observaciones p { color: #856404; font-size: 14px; }
        
        .btn-print { position: fixed; bottom: 20px; right: 20px; background: #3966b1; color: white; border: none; padding: 15px 25px; border-radius: 50px; font-size: 16px; box-shadow: 0 4px 15px rgba(57,102,177,0.4); cursor: pointer; }
        .btn-print:hover { background: #2d4f8b; }
        
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
                <h1><i class="fas fa-file-invoice"></i> RECIBO DE COBRO</h1>
                <div class="numero">Nº G-<?= str_pad($gasto['id'], 5, '0', STR_PAD_LEFT) ?></div>
                <div class="periodo"><?= safeText($gasto['mes']) ?> <?= $gasto['anio'] ?></div>
            </div>
            
            <div class="condominio-info">
                <h2><i class="fas fa-building"></i> <?= safeText($condominio['nombre']) ?></h2>
                <div class="rif">RIF: <?= safeText($condominio['rif']) ?></div>
                <div class="direccion"><?= safeText($condominio['direccion'] ?? 'Dirección no especificada') ?></div>
            </div>
            
            <div class="datos-pago">
                <div class="dato-row">
                    <span class="label"><i class="fas fa-calendar"></i> Fecha de Emisión:</span>
                    <span class="value"><?= safeText($gasto['fecha_registro_formateada']) ?></span>
                </div>
                <div class="dato-row">
                    <span class="label"><i class="fas fa-home"></i> Inmueble:</span>
                    <span class="value"><?= safeText($gasto['nombre_inmueble']) ?></span>
                </div>
                <div class="dato-row">
                    <span class="label"><i class="fas fa-percent"></i> Alícuota:</span>
                    <span class="value"><?= safeText($gasto['alicuota_porcentaje']) ?>%</span>
                </div>
            </div>
            
            <div class="seccion">
                <h3><i class="fas fa-cogs"></i> GASTOS FIJOS</h3>
                <div class="item-row">
                    <span class="concepto">Salarios Personal Mantenimiento</span>
                    <span class="monto">$<?= formatNumber($gasto['salarios_personal_mantenimiento']) ?></span>
                </div>
                <div class="item-row">
                    <span class="concepto">Prestaciones Sociales</span>
                    <span class="monto">$<?= formatNumber($gasto['prestaciones_sociales']) ?></span>
                </div>
                <div class="item-row">
                    <span class="concepto">Servicio Administrativo</span>
                    <span class="monto">$<?= formatNumber($gasto['servicio_administrativo']) ?></span>
                </div>
                <div class="item-row">
                    <span class="concepto">Servicio de Agua</span>
                    <span class="monto">$<?= formatNumber($gasto['servicio_agua']) ?></span>
                </div>
                <div class="item-row">
                    <span class="concepto">Servicio Corpoelec</span>
                    <span class="monto">$<?= formatNumber($gasto['servicio_corpoelec']) ?></span>
                </div>
                <div class="item-row">
                    <span class="concepto">Productos de Limpieza</span>
                    <span class="monto">$<?= formatNumber($gasto['productos_limpieza']) ?></span>
                </div>
                <div class="item-row">
                    <span class="concepto">Mantenimiento</span>
                    <span class="monto">$<?= formatNumber($gasto['mantenimiento']) ?></span>
                </div>
                <div class="item-row">
                    <span class="concepto">Comisiones Bancarias</span>
                    <span class="monto">$<?= formatNumber($gasto['comisiones_bancarias']) ?></span>
                </div>
                <?php foreach($gastos_extra['gastos_fijos_extra'] ?? [] as $gasto_extra): ?>
                <div class="item-row">
                    <span class="concepto"><?= safeText($gasto_extra['concepto']) ?></span>
                    <span class="monto">$<?= formatNumber($gasto_extra['monto']) ?></span>
                </div>
                <?php endforeach; ?>
                <div class="item-row total">
                    <span class="concepto">TOTAL GASTOS FIJOS</span>
                    <span class="monto">$<?= formatNumber($gasto['total_gastos_fijos']) ?></span>
                </div>
                <div class="item-row alicuota">
                    <span class="concepto">Total a Pagar (Alícuota)</span>
                    <span class="monto">$<?= formatNumber($gasto['total_a_pagar_alicuota']) ?></span>
                </div>
            </div>
            
            <div class="seccion">
                <h3><i class="fas fa-chart-line"></i> GASTOS VARIABLES</h3>
                <div class="item-row">
                    <span class="concepto">Mantenimiento de Infraestructura</span>
                    <span class="monto">$<?= formatNumber($gasto['mantenimiento_insfrastutura']) ?></span>
                </div>
                <?php foreach($gastos_extra['gastos_variables_extra'] ?? [] as $gasto_extra): ?>
                <div class="item-row">
                    <span class="concepto"><?= safeText($gasto_extra['concepto']) ?></span>
                    <span class="monto">$<?= formatNumber($gasto_extra['monto']) ?></span>
                </div>
                <?php endforeach; ?>
                <div class="item-row total">
                    <span class="concepto">TOTAL GASTOS VARIABLES</span>
                    <span class="monto">$<?= formatNumber($gasto['total_gastos_variables_pagar']) ?></span>
                </div>
                <div class="item-row alicuota">
                    <span class="concepto">Total Gastos Variables (Alícuota)</span>
                    <span class="monto">$<?= formatNumber($gasto['total_gastos_variables_pagar_alicuota']) ?></span>
                </div>
            </div>
            
            <div class="seccion">
                <h3><i class="fas fa-minus-circle"></i> OTROS GASTOS</h3>
                <div class="item-row">
                    <span class="concepto">Menos Alquiler Áreas Comunes</span>
                    <span class="monto">$<?= formatNumber($gasto['menos_alquiler_areas_comunes']) ?></span>
                </div>
                <?php foreach($gastos_extra['otros_gastos_extra'] ?? [] as $gasto_extra): ?>
                <div class="item-row">
                    <span class="concepto"><?= safeText($gasto_extra['concepto']) ?></span>
                    <span class="monto">$<?= formatNumber($gasto_extra['monto']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            
            <div class="seccion">
                <h3><i class="fas fa-plus-circle"></i> MÁS GASTOS</h3>
                <?php if($gasto['servicio_television'] > 0): ?>
                <div class="item-row">
                    <span class="concepto">Servicio Televisión</span>
                    <span class="monto">$<?= formatNumber($gasto['servicio_television']) ?></span>
                </div>
                <?php endif; ?>
                <?php if($gasto['servicio_internet'] > 0): ?>
                <div class="item-row">
                    <span class="concepto">Servicio Internet</span>
                    <span class="monto">$<?= formatNumber($gasto['servicio_internet']) ?></span>
                </div>
                <?php endif; ?>
                <?php if($gasto['area_comun'] > 0): ?>
                <div class="item-row">
                    <span class="concepto">Área Común</span>
                    <span class="monto">$<?= formatNumber($gasto['area_comun']) ?></span>
                </div>
                <?php endif; ?>
                <?php if($gasto['alquiler_area_comun'] > 0): ?>
                <div class="item-row">
                    <span class="concepto">Alquiler Área Común</span>
                    <span class="monto">$<?= formatNumber($gasto['alquiler_area_comun']) ?></span>
                </div>
                <?php endif; ?>
                <?php foreach($gastos_extra['mas_gastos'] ?? [] as $gasto_extra): ?>
                <div class="item-row">
                    <span class="concepto"><?= safeText($gasto_extra['concepto']) ?></span>
                    <span class="monto">$<?= formatNumber($gasto_extra['monto']) ?></span>
                </div>
                <?php endforeach; ?>
                <?php if($gasto['total_mas_gastos'] > 0): ?>
                <div class="item-row total">
                    <span class="concepto">TOTAL MÁS GASTOS</span>
                    <span class="monto">$<?= formatNumber($gasto['total_mas_gastos']) ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="seccion">
                <h3><i class="fas fa-calculator"></i> RESUMEN DE PAGOS</h3>
                <div class="item-row">
                    <span class="concepto">Fondo de Reserva (<?= $gasto['porcentaje_fondo_reserva'] ?>%)</span>
                    <span class="monto">$<?= formatNumber($gasto['fondo_reserva']) ?></span>
                </div>
                <div class="item-row">
                    <span class="concepto">Condominio</span>
                    <span class="monto">$<?= formatNumber($gasto['condominio']) ?></span>
                </div>
                <div class="item-row alicuota">
                    <span class="concepto">Alícuota: <?= $gasto['alicuota_porcentaje'] ?>%</span>
                    <span class="monto">$<?= formatNumber($gasto['total_a_pagar_mes'] - $gasto['fondo_reserva']) ?></span>
                </div>
            </div>
            
            <div class="datos-pago">
                <div class="total-box">
                    <div class="label">TOTAL A PAGAR</div>
                    <div class="monto">$<?= formatNumber($gasto['total_a_pagar_mes']) ?></div>
                </div>
            </div>
            
            <?php if(!empty($gasto['observaciones'])): ?>
            <div class="observaciones">
                <h4><i class="fas fa-info-circle"></i> OBSERVACIONES</h4>
                <p><?= safeText($gasto['observaciones']) ?></p>
            </div>
            <?php endif; ?>
            
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