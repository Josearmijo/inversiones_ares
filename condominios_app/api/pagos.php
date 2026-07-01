<?php
require_once 'database.php';
require_once '../PHPMailer/src/PHPMailer.php';
require_once '../PHPMailer/src/SMTP.php';
require_once '../PHPMailer/src/Exception.php';

header('Content-Type: application/json');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

function enviarCorreoConfirmacion($para, $nombre, $monto, $meses, $tipo_operacion) {
    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host = 'mail.inversionesares2.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'notificaciones@inversionesares2.com';
        $mail->Password = 'mau250619***';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = 465;
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        
        $mail->setFrom('notificaciones@inversionesares2.com', 'Inversiones Ares');
        $mail->addAddress($para, $nombre);
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        
        $mail->Subject = 'Confirmacion de Pago - Inversiones Ares';
        
        $meses_formateados = is_array($meses) ? implode(', ', $meses) : $meses;
        
        $mail->Body = "
        <html>
        <body style='font-family: Arial, sans-serif; color: #333;'>
            <div style='max-width: 600px; margin:0 auto; padding: 20px;'>
                <div style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; text-align: center;'>
                    <h1 style='color: white; margin:0;'>Inversiones Ares</h1>
                    <p style='color: #f0f0f0; margin: 10px 0 0 0;'>Sistema de Pagos de Condominio</p>
                </div>
                <div style='padding: 30px; background: #f9f9f9;'>
                    <h2 style='color: #28a745; margin-top: 0;'>Transaccion Realizada</h2>
                    <p>Estimado/a <strong>$nombre</strong>,</p>
                    <p>Le informamos que su transaccion ha sido <strong>registrada exitosamente</strong>.</p>
                    <div style='background: white; padding: 20px; border-radius: 10px; margin: 20px 0; box-shadow: 0 2px 5px rgba(0,0,0,0.1);'>
                        <h3 style='margin-top: 0; color: #667eea;'>Detalles del Pago</h3>
                        <table style='width: 100%;'>
                            <tr><td style='padding: 10px 0; border-bottom: 1px solid #eee;'><strong>Tipo de Operacion:</strong></td><td style='padding: 10px 0; border-bottom: 1px solid #eee;'>" . ucfirst(str_replace('_', ' ', $tipo_operacion)) . "</td></tr>
                            <tr><td style='padding: 10px 0; border-bottom: 1px solid #eee;'><strong>Monto Pagado:</strong></td><td style='padding: 10px 0; border-bottom: 1px solid #eee; color: #28a745; font-weight: bold;'>$" . number_format($monto, 2) . " USD</td></tr>
                            <tr><td style='padding: 10px 0; border-bottom: 1px solid #eee;'><strong>Meses:</strong></td><td style='padding: 10px 0; border-bottom: 1px solid #eee;'>$meses_formateados</td></tr>
                            <tr><td style='padding: 10px 0;'><strong>Fecha:</strong></td><td style='padding: 10px 0;'>" . date('d/m/Y H:i:s') . "</td></tr>
                        </table>
                    </div>
                    <div style='background: #fff3cd; border: 1px solid #ffc107; padding: 20px; border-radius: 10px; margin: 20px 0;'>
                        <h3 style='margin-top: 0; color: #856404;'>Estatus: Verificacion Pendiente</h3>
                        <p style='margin-bottom: 0;'>Su pago se encuentra <strong>pendiente de verificacion</strong> por parte del administrador del condominio.</p>
                    </div>
                    <p style='color: #666; font-size: 14px;'>Si tiene alguna consulta, contacte al administrador.</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $mail->send();
        return array('success' => true, 'debug' => 'Correo enviado con SMTP');
        
    } catch (Exception $e) {
        return array('success' => false, 'error' => $mail->ErrorInfo . ' | Host: ' . $mail->Host . ' | Puerto: ' . $mail->Port);
    }
}

$action = $_POST['action'] ?? '';

if ($action === 'pagar') {
    $user_id = $_POST['user_id'] ?? null;
    $user_type = $_POST['user_type'] ?? '';
    $condominio_db = $_POST['condominio_db'] ?? '';
    $inmueble_id = $_POST['inmueble_id'] ?? null;
    $deuda_id = $_POST['deuda_id'] ?? null;
    
    $monto_completo = floatval($_POST['monto_completo'] ?? $_POST['monto'] ?? 0);
    $monto_abono = floatval($_POST['monto_abono'] ?? 0);
    $tipo_pago = $_POST['tipo_pago'] ?? '';
    $tasa_cambio = floatval($_POST['tasa_cambio'] ?? 0);
    $referencia = $_POST['referencia'] ?? '';
    $mes_pago = $_POST['mes_pago'] ?? '';
    $anio_pago = $_POST['anio_pago'] ?? date('Y');
    $banco = $_POST['banco'] ?? '';
    $tipo_operacion = $_POST['tipo_operacion'] ?? 'completo';
    $mes_abono = $_POST['mes_abono'] ?? '';
    $anio_abono = $_POST['anio_abono'] ?? '';
    
    if (!$user_id || !$condominio_db || !$tipo_pago || !$referencia) {
        echo json_encode(array('success' => false, 'error' => 'Datos incompletos'));
        exit;
    }
    
    $condominioConn = connectCondominioDB($condominio_db);
    if (!$condominioConn) {
        echo json_encode(array('success' => false, 'error' => 'No se pudo conectar al condominio'));
        exit;
    }
    
    $comprobante_name = null;
    if (!empty($_FILES['comprobante']['name'])) {
        $document_root = $_SERVER['DOCUMENT_ROOT'];
        $upload_dir_absoluta = $document_root . '/uploads/pagos/';
        
        if (!file_exists($upload_dir_absoluta)) {
            mkdir($upload_dir_absoluta, 0777, true);
        }
        
        $file_ext = strtolower(pathinfo($_FILES['comprobante']['name'], PATHINFO_EXTENSION));
        $comprobante_name = "comprobante_" . time() . "_" . $user_id . "." . $file_ext;
        $file_path_absoluto = $upload_dir_absoluta . $comprobante_name;
        
        if (!move_uploaded_file($_FILES['comprobante']['tmp_name'], $file_path_absoluto)) {
            echo json_encode(array('success' => false, 'error' => 'Error al subir el comprobante'));
            exit;
        }
    }
    
    try {
        $id_gasto = null;
        if ($deuda_id && is_numeric($deuda_id)) {
            $stmtCheck = $condominioConn->prepare("SELECT id FROM gastos WHERE id = ?");
            $stmtCheck->execute([$deuda_id]);
            if ($stmtCheck->fetch()) {
                $id_gasto = intval($deuda_id);
            }
        }
        
        $stmtLocatario = $condominioConn->prepare("SELECT nombre, apellido, email FROM locatarios WHERE id = ?");
        $stmtLocatario->execute([$user_id]);
        $locatario = $stmtLocatario->fetch(PDO::FETCH_ASSOC);
        $nombre_locatario = ($locatario['nombre'] ?? '') . ' ' . ($locatario['apellido'] ?? '');
        $email_locatario = $locatario['email'] ?? '';
        
        $pagos_insertados = [];
        $id_pago_base = null;
        $id_abono = null;
        $debug_info = [];
        $meses_pagados = [];
        
        $debug_info['datos_recibidos'] = array(
            'tipo_operacion' => $tipo_operacion,
            'monto_completo' => $monto_completo,
            'monto_abono' => $monto_abono,
            'mes_pago' => $mes_pago,
            'anio_pago' => $anio_pago,
            'mes_abono' => $mes_abono,
            'anio_abono' => $anio_abono,
            'email_locatario' => $email_locatario
        );
        
        if ($tipo_operacion === 'completo') {
            $total_dolares = $monto_completo;
            $total_bolivares = $tasa_cambio > 0 ? floor($monto_completo * $tasa_cambio * 100) / 100 : 0;
            
            $mes_pago_json = json_encode(array(array('mes' => $mes_pago, 'anio' => intval($anio_pago), 'monto' => $monto_completo)));
            $meses_pagados[] = $mes_pago . ' ' . $anio_pago;
            
            $stmt = $condominioConn->prepare("
                INSERT INTO ingresos 
                (id_locatario, id_inmueble, id_gasto, monto_mes, total_pagar, total_dolares, total_bolivares, tipo_pago, tasa_cambio, referencia_op, mes_pago, fecha_pago, es_abono, captura_pago, pago_verificado, usuario_verifica)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 0, ?, 0, NULL)
            ");
            
            $stmt->execute(array(
                $user_id, $inmueble_id, $id_gasto, $monto_completo, $monto_completo,
                $total_dolares, $total_bolivares, $tipo_pago, $tasa_cambio, $referencia,
                $mes_pago_json, $comprobante_name
            ));
            
            $id_insertado = $condominioConn->lastInsertId();
            $pagos_insertados[] = $id_insertado;
            $debug_info['pago_completo'] = array('id_insertado' => $id_insertado);
            
            $mensaje = 'Pago completo registrado correctamente.';
        } 
        elseif ($tipo_operacion === 'abono') {
            $total_dolares = $monto_abono;
            $total_bolivares = $tasa_cambio > 0 ? floor($monto_abono * $tasa_cambio * 100) / 100 : 0;
            
            $mes_abono_json = json_encode(array(array('mes' => $mes_abono, 'anio' => intval($anio_abono), 'monto' => $monto_abono)));
            $meses_pagados[] = $mes_abono . ' ' . $anio_abono;
            
            $stmt = $condominioConn->prepare("
                INSERT INTO ingresos 
                (id_locatario, id_inmueble, id_gasto, monto_mes, total_pagar, total_dolares, total_bolivares, tipo_pago, tasa_cambio, referencia_op, mes_pago, fecha_pago, es_abono, captura_pago, pago_verificado, usuario_verifica)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 1, ?, 0, NULL)
            ");
            
            $stmt->execute(array(
                $user_id, $inmueble_id, $id_gasto, $monto_abono, $monto_abono,
                $total_dolares, $total_bolivares, $tipo_pago, $tasa_cambio, $referencia,
                $mes_abono_json, $comprobante_name
            ));
            
            $id_insertado = $condominioConn->lastInsertId();
            $pagos_insertados[] = $id_insertado;
            $debug_info['pago_abono'] = array('id_insertado' => $id_insertado);
            
            $mensaje = 'Abono registrado correctamente.';
        } 
        elseif ($tipo_operacion === 'ambos') {
            $total_dolares_completo = $monto_completo;
            $total_bolivares_completo = $tasa_cambio > 0 ? floor($monto_completo * $tasa_cambio * 100) / 100 : 0;
            
            $mes_pago_json = json_encode(array(array('mes' => $mes_pago, 'anio' => intval($anio_pago), 'monto' => $monto_completo)));
            $meses_pagados[] = $mes_pago . ' ' . $anio_pago;
            
            $stmtCompleto = $condominioConn->prepare("
                INSERT INTO ingresos 
                (id_locatario, id_inmueble, id_gasto, monto_mes, total_pagar, total_dolares, total_bolivares, tipo_pago, tasa_cambio, referencia_op, mes_pago, fecha_pago, es_abono, captura_pago, pago_verificado, usuario_verifica)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 0, ?, 0, NULL)
            ");
            
            $stmtCompleto->execute(array(
                $user_id, $inmueble_id, $id_gasto, $monto_completo, $monto_completo,
                $total_dolares_completo, $total_bolivares_completo, $tipo_pago, $tasa_cambio, $referencia,
                $mes_pago_json, $comprobante_name
            ));
            
            $id_pago_base = $condominioConn->lastInsertId();
            $pagos_insertados[] = $id_pago_base;
            
            $total_dolares_abono = $monto_abono;
            $total_bolivares_abono = $tasa_cambio > 0 ? floor($monto_abono * $tasa_cambio * 100) / 100 : 0;
            
            $mes_abono_json = json_encode(array(array('mes' => $mes_abono, 'anio' => intval($anio_abono), 'monto' => $monto_abono)));
            $meses_pagados[] = $mes_abono . ' ' . $anio_abono;
            
            $stmtAbono = $condominioConn->prepare("
                INSERT INTO ingresos 
                (id_locatario, id_inmueble, id_gasto, monto_mes, total_pagar, total_dolares, total_bolivares, tipo_pago, tasa_cambio, referencia_op, mes_pago, fecha_pago, es_abono, captura_pago, pago_verificado, usuario_verifica)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 1, ?, 0, NULL)
            ");
            
            $stmtAbono->execute(array(
                $user_id, $inmueble_id, $id_gasto, $monto_abono, $monto_abono,
                $total_dolares_abono, $total_bolivares_abono, $tipo_pago, $tasa_cambio, $referencia,
                $mes_abono_json, $comprobante_name
            ));
            
            $id_abono = $condominioConn->lastInsertId();
            $pagos_insertados[] = $id_abono;
            
            $stmtUpdate = $condominioConn->prepare("UPDATE ingresos SET id_pago_base = ?, id_pago_relacionado = ? WHERE id_pago = ?");
            $stmtUpdate->execute(array($id_pago_base, $id_abono, $id_pago_base));
            
            $stmtUpdate2 = $condominioConn->prepare("UPDATE ingresos SET id_pago_base = ?, id_pago_relacionado = ? WHERE id_pago = ?");
            $stmtUpdate2->execute(array($id_pago_base, $id_pago_base, $id_abono));
            
            $debug_info['pago_completo'] = array('id_insertado' => $id_pago_base, 'monto' => $monto_completo);
            $debug_info['pago_abono'] = array('id_insertado' => $id_abono, 'monto' => $monto_abono);
            
            $mensaje = 'Pago completo y abono registrados correctamente.';
        }
        
        $total_monto = $monto_completo + $monto_abono;
        
        $resultado_correo = array('success' => false, 'error' => 'Email no verificado');
        if (!empty($email_locatario)) {
            $resultado_correo = enviarCorreoConfirmacion($email_locatario, $nombre_locatario, $total_monto, $meses_pagados, $tipo_operacion);
            $debug_info['correo'] = array(
                'enviado' => $resultado_correo['success'],
                'para' => $email_locatario,
                'nombre' => $nombre_locatario,
                'error' => $resultado_correo['error'] ?? null
            );
        } else {
            $debug_info['correo'] = array(
                'enviado' => false,
                'error' => 'No hay email registrado para el locatario'
            );
        }
        
        echo json_encode(array(
            'success' => true,
            'pago_id' => implode(',', $pagos_insertados),
            'mensaje' => $mensaje,
            'mostrar_sweetalert' => true,
            'sweetalert_title' => 'Transaccion Realizada',
            'sweetalert_text' => 'La transaccion fue realizada satisfactoriamente.\n\nSu pago se encuentra en verificacion.',
            'sweetalert_icon' => 'success',
            'debug' => $debug_info
        ));
        
    } catch (PDOException $e) {
        echo json_encode(array('success' => false, 'error' => 'Error: ' . $e->getMessage()));
    }
    exit;
}

echo json_encode(array('success' => false, 'error' => 'Accion no valida'));
