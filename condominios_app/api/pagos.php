<?php
require_once 'database.php';

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

if ($action === 'pagar') {
    $user_id = $_POST['user_id'] ?? null;
    $user_type = $_POST['user_type'] ?? '';
    $condominio_db = $_POST['condominio_db'] ?? '';
    $inmueble_id = $_POST['inmueble_id'] ?? null;
    $deuda_id = $_POST['deuda_id'] ?? null;
    $monto = floatval($_POST['monto'] ?? 0);
    $tipo_pago = $_POST['tipo_pago'] ?? '';
    $referencia = $_POST['referencia'] ?? '';
    $mes_pago = $_POST['mes_pago'] ?? '';
    $anio_pago = $_POST['anio_pago'] ?? date('Y');
    $banco = $_POST['banco'] ?? '';
    
    if (!$user_id || !$condominio_db || !$monto || !$tipo_pago || !$referencia) {
        echo json_encode(['success' => false, 'error' => 'Datos incompletos']);
        exit;
    }
    
    $condominioConn = connectCondominioDB($condominio_db);
    if (!$condominioConn) {
        echo json_encode(['success' => false, 'error' => 'No se pudo conectar al condominio']);
        exit;
    }
    
    try {
        // Convertir a mes número si viene como texto
        $meses = [
            'Enero' => 1, 'Febrero' => 2, 'Marzo' => 3, 'Abril' => 4,
            'Mayo' => 5, 'Junio' => 6, 'Julio' => 7, 'Agosto' => 8,
            'Septiembre' => 9, 'Octubre' => 10, 'Noviembre' => 11, 'Diciembre' => 12
        ];
        $mes_numero = is_numeric($mes_pago) ? (int)$mes_pago : ($meses[$mes_pago] ?? date('n'));
        
        // Insertar pago
        $stmt = $condominioConn->prepare("
            INSERT INTO pagos 
            (id_locatario, id_inmueble, id_deuda, monto, tipo_pago, referencia, banco,
             mes_pago, anio_pago, fecha_pago, estado)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 'pendiente')
        ");
        
        $stmt->execute([
            $user_id,
            $inmueble_id,
            $deuda_id,
            $monto,
            $tipo_pago,
            $referencia,
            $banco,
            $mes_numero,
            $anio_pago
        ]);
        
        $pago_id = $condominioConn->lastInsertId();
        
        // Si el monto cubre la deuda, actualizar estado
        if ($deuda_id) {
            $stmt = $condominioConn->prepare("SELECT total, pagado FROM deudas_mensuales WHERE id = ?");
            $stmt->execute([$deuda_id]);
            $deuda = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($deuda) {
                $nuevo_pagado = floatval($deuda['pagado']) + $monto;
                $total = floatval($deuda['total']);
                
                $estado = $nuevo_pagado >= $total ? 'pagado' : 'parcial';
                
                $stmt = $condominioConn->prepare("
                    UPDATE deudas_mensuales 
                    SET pagado = ?, estado = ? 
                    WHERE id = ?
                ");
                $stmt->execute([$nuevo_pagado, $estado, $deuda_id]);
            }
        }
        
        echo json_encode([
            'success' => true,
            'pago_id' => $pago_id,
            'mensaje' => 'Pago registrado exitosamente. Pendiente de verificación por el administrador.'
        ]);
        
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Acción no válida']);
