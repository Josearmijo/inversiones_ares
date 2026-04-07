<?php
require_once 'database.php';

header('Content-Type: application/json');

$condominio_db = $_GET['condominio_db'] ?? '';

if (empty($condominio_db)) {
    echo json_encode(['error' => 'Condominio no especificado']);
    exit;
}

$condominioConn = connectCondominioDB($condominio_db);
if (!$condominioConn) {
    echo json_encode(['error' => 'No se pudo conectar al condominio']);
    exit;
}

$seccion = $_GET['seccion'] ?? '';
$anio = $_GET['anio'] ?? date('Y');

try {
    switch ($seccion) {
        case 'locatarios':
            $stmt = $condominioConn->query("
                SELECT l.id, l.identificacion, l.nombre, l.apellido, l.email, l.telefono, l.activo
                FROM locatarios l
                ORDER BY l.nombre, l.apellido
                LIMIT 100
            ");
            $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'datos' => $datos, 'seccion' => 'locatarios']);
            break;
            
        case 'inmuebles':
            $stmt = $condominioConn->query("
                SELECT i.id, i.nombre, i.alicuota_porcentaje, i.estado
                FROM inmuebles i
                ORDER BY i.nombre
                LIMIT 100
            ");
            $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'datos' => $datos, 'seccion' => 'inmuebles']);
            break;
            
        case 'ingresos':
            $stmt = $condominioConn->prepare("
                SELECT i.id_pago as id, i.fecha_pago, i.monto_mes, i.total_pagar, i.tipo_pago, i.referencia_op,
                       l.nombre as locatario_nombre, inm.nombre as inmueble_nombre
                FROM ingresos i
                LEFT JOIN locatarios l ON i.id_locatario = l.id
                LEFT JOIN inmuebles inm ON i.id_inmueble = inm.id
                WHERE YEAR(i.fecha_pago) = ?
                ORDER BY i.fecha_pago DESC
                LIMIT 50
            ");
            $stmt->execute([$anio]);
            $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'datos' => $datos, 'seccion' => 'ingresos', 'anio' => $anio]);
            break;
            
        case 'egresos':
            $stmt = $condominioConn->prepare("
                SELECT e.id_egreso as id, e.fecha_pago, e.paga_a as concepto, e.monto_a_pagar as monto, e.tipo_pago
                FROM egresos e
                WHERE YEAR(e.fecha_pago) = ?
                ORDER BY e.fecha_pago DESC
                LIMIT 50
            ");
            $stmt->execute([$anio]);
            $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'datos' => $datos, 'seccion' => 'egresos', 'anio' => $anio]);
            break;
            
        case 'deudas':
            $stmt = $condominioConn->prepare("
                SELECT dm.id, dm.anio, 
                       (dm.enero + dm.febrero + dm.marzo + dm.abril + dm.mayo + dm.junio + dm.julio + dm.agosto + dm.septiembre + dm.octubre + dm.noviembre + dm.diciembre) as total_deuda,
                       l.nombre as locatario_nombre, inm.nombre as inmueble_nombre
                FROM deudas_mensuales dm
                LEFT JOIN locatarios l ON dm.id_locatario = l.id
                LEFT JOIN inmuebles inm ON dm.id_inmueble = inm.id
                WHERE dm.anio = ?
                ORDER BY dm.anio DESC
                LIMIT 50
            ");
            $stmt->execute([$anio]);
            $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'datos' => $datos, 'seccion' => 'deudas', 'anio' => $anio]);
            break;
            
        case 'balance':
            // Obtener totales
            $stmtIngresos = $condominioConn->prepare("
                SELECT COALESCE(SUM(monto_mes), 0) as total 
                FROM ingresos 
                WHERE YEAR(fecha_pago) = ? AND es_abono = FALSE
            ");
            $stmtIngresos->execute([$anio]);
            $ingresos = $stmtIngresos->fetch(PDO::FETCH_ASSOC);
            
            $stmtEgresos = $condominioConn->prepare("
                SELECT COALESCE(SUM(monto_a_pagar), 0) as total 
                FROM egresos 
                WHERE YEAR(fecha_pago) = ?
            ");
            $stmtEgresos->execute([$anio]);
            $egresos = $stmtEgresos->fetch(PDO::FETCH_ASSOC);
            
            $balance = floatval($ingresos['total']) - floatval($egresos['total']);
            
            echo json_encode([
                'success' => true, 
                'seccion' => 'balance',
                'anio' => $anio,
                'ingresos' => floatval($ingresos['total']),
                'egresos' => floatval($egresos['total']),
                'balance' => $balance
            ]);
            break;
            
        case 'empleados':
            try {
                $stmt = $condominioConn->query("
                    SELECT id, cedula, nombre, apellido, cargo, estado
                    FROM empleados
                    ORDER BY nombre, apellido
                    LIMIT 100
                ");
                $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'datos' => $datos, 'seccion' => 'empleados']);
            } catch (PDOException $e) {
                echo json_encode(['success' => true, 'datos' => [], 'seccion' => 'empleados', 'error' => 'Tabla empleados no existe']);
            }
            break;
            
        case 'nomina':
            try {
                $stmt = $condominioConn->query("
                    SELECT id, nombre, tipo_nomina, nomina_relacionada, fecha_creacion
                    FROM nomina
                    ORDER BY fecha_creacion DESC
                    LIMIT 50
                ");
                $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'datos' => $datos, 'seccion' => 'nomina']);
            } catch (PDOException $e) {
                echo json_encode(['success' => true, 'datos' => [], 'seccion' => 'nomina', 'error' => 'Tabla nomina no existe']);
            }
            break;
            
        case 'pago_nomina':
            try {
                $debug[] = "Consultando pago_nomina verificada...";
                $stmt = $condominioConn->query("
                    SELECT pn.id, pn.neto_pagar, pn.neto_pagar_bolivares, pn.fecha_pago, pn.tipo_pago, pn.nomina_verificada,
                           e.nombre as empleado_nombre, e.apellido
                    FROM pago_nomina pn
                    LEFT JOIN empleados e ON pn.id_empleado = e.id
                    WHERE pn.nomina_verificada = 1
                    ORDER BY pn.fecha_pago DESC
                    LIMIT 100
                ");
                $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $debug[] = "Registros encontrados: " . count($datos);
                echo json_encode(['success' => true, 'datos' => $datos, 'seccion' => 'pago_nomina', 'debug' => $debug]);
            } catch (PDOException $e) {
                $debug[] = "ERROR: " . $e->getMessage();
                echo json_encode(['success' => true, 'datos' => [], 'seccion' => 'pago_nomina', 'debug' => $debug]);
            }
            break;
            
        case 'verificar_pagos':
            try {
                $debug[] = "Consultando pagos pendientes de verificar...";
                $stmt = $condominioConn->query("
                    SELECT pn.id, pn.neto_pagar, pn.neto_pagar_bolivares, pn.fecha_pago, pn.tipo_pago, pn.nomina_verificada,
                           e.nombre as empleado_nombre, e.apellido, e.cedula
                    FROM pago_nomina pn
                    LEFT JOIN empleados e ON pn.id_empleado = e.id
                    WHERE pn.nomina_verificada = 0 AND pn.nomina_rechazada != 1
                    ORDER BY pn.fecha_pago DESC
                    LIMIT 50
                ");
                $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'datos' => $datos, 'seccion' => 'verificar_pagos', 'debug' => $debug]);
            } catch (PDOException $e) {
                echo json_encode(['success' => true, 'datos' => [], 'seccion' => 'verificar_pagos', 'error' => 'Error: ' . $e->getMessage()]);
            }
            break;
            
        case 'buzon':
            try {
                $stmt = $condominioConn->query("
                    SELECT id, remitente, asunto, mensaje, fecha, leido, prioridad
                    FROM buzon
                    ORDER BY fecha DESC
                    LIMIT 50
                ");
                $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'datos' => $datos, 'seccion' => 'buzon']);
            } catch (PDOException $e) {
                echo json_encode(['success' => true, 'datos' => [], 'seccion' => 'buzon', 'error' => 'Tabla buzon no existe']);
            }
            break;
            
        case 'gastos':
            $stmt = $condominioConn->prepare("
                SELECT g.id, g.mes, g.anio, g.total_a_pagar_mes, g.condominio, g.fondo_reserva, g.fecha_registro,
                       inm.nombre as inmueble_nombre
                FROM gastos g
                LEFT JOIN inmuebles inm ON g.inmueble_id = inm.id
                ORDER BY g.anio DESC, FIELD(g.mes, 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre') DESC
                LIMIT 50
            ");
            $stmt->execute([]);
            $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'datos' => $datos, 'seccion' => 'gastos']);
            break;
            
        case 'reportes':
            echo json_encode([
                'success' => true,
                'seccion' => 'reportes',
                'reportes' => [
                    ['id' => 'estado_cuenta', 'nombre' => 'Estado de Cuenta por Inmueble'],
                    ['id' => 'balance_mensual', 'nombre' => 'Balance Mensual'],
                    ['id' => 'deudas_pendientes', 'nombre' => 'Deudas Pendientes'],
                    ['id' => 'pagos_recibidos', 'nombre' => 'Pagos Recibidos'],
                    ['id' => 'egresos_mes', 'nombre' => 'Egresos del Mes'],
                    ['id' => 'resumen_anual', 'nombre' => 'Resumen Anual']
                ]
            ]);
            break;
            
        default:
            // Dashboard geral
            $stmt = $condominioConn->query("SELECT COUNT(*) as total FROM locatarios WHERE activo = 1");
            $locatarios = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $stmt = $condominioConn->query("SELECT COUNT(*) as total FROM inmuebles WHERE estado = 'ocupado'");
            $inmuebles = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $stmtIngresos = $condominioConn->query("SELECT COALESCE(SUM(monto_mes), 0) as total FROM ingresos WHERE YEAR(fecha_pago) = YEAR(CURDATE()) AND es_abono = FALSE");
            $ingresos = $stmtIngresos->fetch(PDO::FETCH_ASSOC);
            
            $stmtEgresos = $condominioConn->query("SELECT COALESCE(SUM(monto_a_pagar), 0) as total FROM egresos WHERE YEAR(fecha_pago) = YEAR(CURDATE())");
            $egresos = $stmtEgresos->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'seccion' => 'dashboard',
                'locatarios' => intval($locatarios['total']),
                'inmuebles' => intval($inmuebles['total']),
                'ingresos' => floatval($ingresos['total']),
                'egresos' => floatval($egresos['total']),
                'balance' => floatval($ingresos['total']) - floatval($egresos['total'])
            ]);
    }
} catch (PDOException $e) {
    echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
}
