<?php
require_once 'database.php';

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$password = $_POST['password'] ?? '';

$debug = [];

if ($action === 'login') {
    $debug[] = "Iniciando login";
    
    if (empty($password)) {
        echo json_encode(['success' => false, 'error' => 'La contraseña es requerida', 'debug' => $debug]);
        exit;
    }
    
    try {
        // Buscar en las tablas de locatarios de cada condominio
        $stmtCond = $conn->query("SELECT id, nombre, db_name FROM condominios WHERE activo = 1");
        $condominios = $stmtCond->fetchAll(PDO::FETCH_ASSOC);
        
        $locatarioEncontrado = null;
        $condominioId = null;
        $condominioDbName = null;
        $condominioNombre = null;
        
        foreach ($condominios as $cond) {
            try {
                $condConn = connectCondominioDB($cond['db_name']);
                if ($condConn) {
                    $stmtLoc = $condConn->prepare("
                        SELECT id, nombre, email, password, activo 
                        FROM locatarios 
                        WHERE activo = 1
                    ");
                    $stmtLoc->execute();
                    $locatarios = $stmtLoc->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($locatarios as $locatario) {
                        $passwordValida = false;
                        $passwordLoc = $locatario['password'];
                        
                        if (substr($passwordLoc, 0, 2) === '$2') {
                            $passwordValida = password_verify($password, $passwordLoc);
                        } elseif (strlen($passwordLoc) === 32 && ctype_xdigit($passwordLoc)) {
                            $passwordValida = (md5($password) === $passwordLoc);
                        } else {
                            $passwordValida = ($password === $passwordLoc);
                        }
                        
                        if ($passwordValida) {
                            $debug[] = "Locatario encontrado en: " . $cond['nombre'];
                            $locatarioEncontrado = $locatario;
                            $condominioId = $cond['id'];
                            $condominioDbName = $cond['db_name'];
                            $condominioNombre = $cond['nombre'];
                            break 2;
                        }
                    }
                }
            } catch (Exception $e) {
                continue;
            }
        }
        
        if (!$locatarioEncontrado) {
            $debug[] = "Locatario NO encontrado en ningún condominio";
            echo json_encode([
                'success' => false, 
                'error' => 'Contraseña inválida',
                'debug' => $debug
            ]);
            exit;
        }
        
        // Guardar el id del locatario en la sesión para buscar después
        $_SESSION['locatario_db_id'] = $locatarioEncontrado['id'];
        $_SESSION['locatario_db_name'] = $condominioDbName;
        
        // Verificar si ya existe usuario en BD principal
        $stmt = $conn->prepare("SELECT id, nombre, email, password, tipo, condominio_id, activo FROM usuarios WHERE condominio_id = ? AND tipo = 'locatario'");
        $stmt->execute([$condominioId]);
        $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $user = null;
        
        // Buscar si hay un usuario que coincida con el locatario encontrado
        foreach ($usuarios as $u) {
            $passwordValida = false;
            if (substr($u['password'], 0, 2) === '$2') {
                $passwordValida = password_verify($password, $u['password']);
            } elseif (strlen($u['password']) === 32 && ctype_xdigit($u['password'])) {
                $passwordValida = (md5($password) === $u['password']);
            } else {
                $passwordValida = ($password === $u['password']);
            }
            if ($passwordValida) {
                $user = $u;
                break;
            }
        }
        
        if (!$user) {
            // Crear usuario en BD principal
            $debug[] = "Creando usuario en BD principal...";
            $stmtNewUser = $conn->prepare("
                INSERT INTO usuarios (nombre, email, password, tipo, condominio_id, activo, locatario_id) 
                VALUES (?, ?, ?, 'locatario', ?, 1, ?)
            ");
            $stmtNewUser->execute([
                $locatarioEncontrado['nombre'],
                $locatarioEncontrado['email'] ?? '',
                $locatarioEncontrado['password'],
                $condominioId,
                $locatarioEncontrado['id']
            ]);
            
            $userId = $conn->lastInsertId();
            $stmt = $conn->prepare("SELECT id, nombre, email, password, tipo, condominio_id, activo, locatario_id FROM usuarios WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            // Actualizar el locatario_id si no existe
            if (empty($user['locatario_id'])) {
                $stmtUpdate = $conn->prepare("UPDATE usuarios SET locatario_id = ? WHERE id = ?");
                $stmtUpdate->execute([$locatarioEncontrado['id'], $user['id']]);
                $user['locatario_id'] = $locatarioEncontrado['id'];
            }
        }
        
        if ($user && $user['activo'] != 1) {
            echo json_encode(['success' => false, 'error' => 'Usuario inactivo', 'debug' => $debug]);
            exit;
        }
        
        // Obtener info del condominio
        $stmt = $conn->prepare("SELECT id, nombre, db_name FROM condominios WHERE id = ?");
        $stmt->execute([$condominioId]);
        $condominio_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $token = bin2hex(random_bytes(32));
        
        echo json_encode([
            'success' => true,
            'user' => [
                'id' => $user['id'],
                'nombre' => $user['nombre'],
                'email' => $user['email'],
                'tipo' => $user['tipo'],
                'condominio_id' => $user['condominio_id'],
                'token' => $token
            ],
            'condominio' => $condominio_info,
            'debug' => $debug
        ]);
        
    } catch (PDOException $e) {
        $debug[] = "ERROR PDO: " . $e->getMessage();
        echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage(), 'debug' => $debug]);
    }
exit;
}

echo json_encode(['success' => false, 'error' => 'Acción no válida']);
