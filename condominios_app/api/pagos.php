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
    $tasa_cambio = floatval($_POST['tasa_cambio'] ?? 0);
    $referencia = $_POST['referencia'] ?? '';
    $mes_pago = $_POST['mes_pago'] ?? '';
    $anio_pago = $_POST['anio_pago'] ?? date('Y');
    $banco = $_POST['banco'] ?? '';
    $tipo_operacion = $_POST['tipo_operacion'] ?? 'completo';
    $mes_abono = $_POST['mes_abono'] ?? '';
    $anio_abono = $_POST['anio_abono'] ?? '';
    $monto_abono = floatval($_POST['monto_abono'] ?? 0);
    $monto_bs = floatval($_POST['monto_bs'] ?? 0);
    
    if (!$user_id || !$condominio_db || !$monto || !$tipo_pago || !$referencia) {
        echo json_encode(['success' => false, 'error' => 'Datos incompletos']);
        exit;
    }
    
    $condominioConn = connectCondominioDB($condominio_db);
    if (!$condominioConn) {
        echo json_encode(['success' => false, 'error' => 'No se pudo conectar al condominio']);
        exit;
    }
    
    $comprobante_name = null;
    if (!empty($_FILES['comprobante']['name'])) {
        $upload_dir = "../../../uploads/pagos/";
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_ext = pathinfo($_FILES['comprobante']['name'], PATHINFO_EXTENSION);
        $comprobante_name = "comprobante_" . time() . "_" . $user_id . "." . strtolower($file_ext);
        $file_path = $upload_dir . $comprobante_name;
        
        if (!move_uploaded_file($_FILES['comprobante']['tmp_name'], $file_path)) {
            echo json_encode(['success' => false, 'error' => 'Error al subir el comprobante']);
            exit;
        }
    }
    
    try {
        $meses = [
            'Enero' => 1, 'Febrero' => 2, 'Marzo' => 3, 'Abril' => 4,
            'Mayo' => 5, 'Junio' => 6, 'Julio' => 7, 'Agosto' => 8,
            'Septiembre' => 9, 'Octubre' => 10, 'Noviembre' => 11, 'Diciembre' => 12
        ];
        
        $pagos_insertados = [];
        
        if ($tipo_operacion === 'completo' || $tipo_operacion === 'ambos') {
            $total_dolares = $monto;
            $total_bolivares = $tasa_cambio > 0 ? floor($monto * $tasa_cambio * 100) / 100 : 0;
            
            $mes_pago_json = json_encode([['mes' => $mes_pago, 'anio' => $anio_pago, 'monto' => $monto]]);
            
            $stmt = $condominioConn->prepare("
                INSERT INTO ingresos 
                (id_locatario, id_inmueble, id_gasto, monto_mes, total_pagar, total_dolares, total_bolivares, tipo_pago, tasa_cambio, referencia_op, mes_pago, fecha_pago, es_abono, captura_pago, pago_verificado, usuario_verifica)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 0, ?, FALSE, NULL)
            ");
            
            $stmt->execute([
                $user_id,
                $inmueble_id,
                $deuda_id,
                $monto,
                $monto,
                $total_dolares,
                $total_bolivares,
                $tipo_pago,
                $tasa_cambio,
                $referencia,
                $mes_pago_json,
                $comprobante_name
            ]);
            
            $pagos_insertados[] = $condominioConn->lastInsertId();
        }
        
        if ($tipo_operacion === 'abono' || $tipo_operacion === 'ambos') {
            $abono_dolares = $tipo_operacion === 'ambos' ? $monto_abono : $monto;
            $abono_bolivares = $tasa_cambio > 0 ? floor($abono_dolares * $tasa_cambio * 100) / 100 : 0;
            
            $mes_abono_json = json_encode([['mes' => $mes_pago, 'anio' => $anio_pago, 'monto' => $abono_dolares]]);
            
            $stmt = $condominioConn->prepare("
                INSERT INTO ingresos 
                (id_locatario, id_inmueble, id_gasto, monto_mes, total_pagar, total_dolares, total_bolivares, tipo_pago, tasa_cambio, referencia_op, mes_pago, fecha_pago, es_abono, captura_pago, pago_verificado, usuario_verifica)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 1, ?, FALSE, NULL)
            ");
            
            $stmt->execute([
                $user_id,
                $inmueble_id,
                $deuda_id,
                $abono_dolares,
                $abono_dolares,
                $abono_dolares,
                $abono_bolivares,
                $tipo_pago,
                $tasa_cambio,
                $referencia,
                $mes_abono_json,
                $comprobante_name
            ]);
            
            $pagos_insertados[] = $condominioConn->lastInsertId();
        }
        
        $mensaje = 'Pago(s) registrado(s) exitosamente. Pendiente(s) de verificación por el administrador.';
        if ($tipo_operacion === 'completo') {
            $mensaje = 'Pago completo registrado. Pendiente de verificación.';
        } elseif ($tipo_operacion === 'abono') {
            $mensaje = 'Abono registrado. Pendiente de verificación.';
        } elseif ($tipo_operacion === 'ambos') {
            $mensaje = 'Pago completo y abono registrados. Pendientes de verificación.';
        }
        
        echo json_encode([
            'success' => true,
            'pago_id' => implode(',', $pagos_insertados),
            'mensaje' => $mensaje
        ]);
        
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Acción no válida']);
