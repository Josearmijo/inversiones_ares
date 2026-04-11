<?php
session_start();

$id = $_GET['id'] ?? null;
$token = $_GET['token'] ?? null;

if (!$id || !$token) {
    die('Parámetros incompletos');
}

$decoded = base64_decode($token);
list($user_id, $db_name) = explode(':', $decoded);

if (!$user_id || !$db_name) {
    die('Token inválido');
}

require_once __DIR__ . '/condominios_app/api/database.php';

$condominioConn = connectCondominioDB($db_name);
if (!$condominioConn) {
    die('No se pudo conectar al condominio');
}

$stmt = $condominioConn->prepare("
    SELECT * FROM ingresos WHERE id_pago = ? AND id_locatario = ? AND pago_verificado = 1
");
$stmt->execute([$id, $user_id]);
$pago = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pago) {
    die('Pago no encontrado o no verificado');
}

$stmt = $condominioConn->prepare("SELECT nombre, db_name FROM condominios WHERE db_name = ?");
$stmt->execute([$db_name]);
$condominio = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $condominioConn->prepare("SELECT nombre FROM inmuebles WHERE id = ?");
$stmt->execute([$pago['id_inmueble']]);
$inmueble = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $condominioConn->prepare("SELECT nombre, apellido, identificacion FROM locatarios WHERE id = ?");
$stmt->execute([$user_id]);
$locatario = $stmt->fetch(PDO::FETCH_ASSOC);

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Recibo de Pago - #<?= $pago['id_pago'] ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; padding: 20px; }
        .recibo { max-width: 600px; margin: 0 auto; border: 2px solid #333; padding: 20px; }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #333; padding-bottom: 10px; }
        .header h1 { font-size: 24px; margin-bottom: 5px; }
        .header h2 { font-size: 18px; color: #666; }
        .info { margin-bottom: 15px; }
        .info p { margin: 5px 0; font-size: 14px; }
        .info strong { display: inline-block; width: 120px; }
        .total { font-size: 20px; font-weight: bold; text-align: right; margin-top: 20px; padding-top: 10px; border-top: 2px solid #333; }
        .footer { text-align: center; margin-top: 30px; font-size: 12px; color: #666; }
        .watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-45deg); font-size: 80px; opacity: 0.1; pointer-events: none; }
    </style>
</head>
<body>
    <div class="recibo">
        <div class="watermark">APROBADO</div>
        <div class="header">
            <h1>CONDOMINIO <?= strtoupper($condominio['nombre']) ?></h1>
            <h2>RECIBO DE PAGO</h2>
        </div>
        
        <div class="info">
            <p><strong>N° Recibo:</strong> <?= $pago['id_pago'] ?></p>
            <p><strong>Fecha:</strong> <?= date('d/m/Y', strtotime($pago['fecha_pago'])) ?></p>
            <p><strong>Inmueble:</strong> <?= $inmueble['nombre'] ?? 'N/A' ?></p>
            <p><strong>Locatario:</strong> <?= $locatario['nombre'] . ' ' . $locatario['apellido'] ?></p>
            <p><strong>Cédula:</strong> <?= $locatario['identificacion'] ?? 'N/A' ?></p>
            <p><strong>Mes(es):</strong> <?= htmlspecialchars($pago['mes_pago']) ?></p>
            <p><strong>Tipo:</strong> <?= $pago['es_abono'] ? 'Abono Parcial' : 'Pago Completo' ?></p>
            <p><strong>Método de Pago:</strong> <?= $pago['tipo_pago'] ?></p>
            <p><strong>Referencia:</strong> <?= $pago['referencia_op'] ?? 'N/A' ?></p>
            <?php if ($pago['total_bolivares'] > 0): ?>
            <p><strong>Monto Bs.:</strong> <?= number_format($pago['total_bolivares'], 2) ?> Bs.</p>
            <?php endif; ?>
        </div>
        
        <div class="total">
            TOTAL PAGADO: $<?= number_format($pago['total_pagar'], 2) ?>
        </div>
        
        <div class="footer">
            <p>Recibo generado automáticamente</p>
            <p>Verificado por: <?= $pago['usuario_verifica'] ?? 'Sistema' ?></p>
        </div>
    </div>
</body>
</html>