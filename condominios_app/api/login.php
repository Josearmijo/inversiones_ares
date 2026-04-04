<?php
require_once 'database.php';

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

$debug = [];

if ($action === 'login') {
    $debug[] = "Iniciando login para: $email";
    
    if (empty($email) || empty($password)) {
        echo json_encode(['success' => false, 'error' => 'Email y contraseña son requeridos', 'debug' => $debug]);
        exit;
    }
    
    try {
        // Buscar usuario en la base de datos principal
        $debug[] = "Buscando usuario en BD...";
        $stmt = $conn->prepare("
            SELECT id, nombre, email, password, tipo, condominio_id, activo 
            FROM usuarios 
            WHERE email = ?
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            $debug[] = "Usuario NO encontrado";
            // Ver si hay usuarios en la BD
            $stmtCount = $conn->query("SELECT COUNT(*) as total FROM usuarios");
            $count = $stmtCount->fetch(PDO::FETCH_ASSOC);
            $debug[] = "Total usuarios en BD: " . $count['total'];
            
            echo json_encode([
                'success' => false, 
                'error' => 'Credenciales inválidas',
                'debug' => $debug
            ]);
            exit;
        }
        
        $debug[] = "Usuario encontrado: " . $user['nombre'] . " (tipo: " . $user['tipo'] . ", activo: " . $user['activo'] . ")";
        
        // Verificar si está activo
        if ($user['activo'] != 1) {
            $debug[] = "Usuario INACTIVO";
            echo json_encode([
                'success' => false, 
                'error' => 'Usuario inactivo',
                'debug' => $debug
            ]);
            exit;
        }
        
        // Verificar contraseña - puede ser MD5, password_hash o texto plano
        $debug[] = "Verificando contraseña...";
        $debug[] = "Hash en BD: " . substr($user['password'], 0, 30) . "...";
        
        $passwordValida = false;
        
        // Verificar tipo de hash
        if (substr($user['password'], 0, 2) === '$2') {
            // Es password_hash
            $debug[] = "Tipo: password_hash";
            $passwordValida = password_verify($password, $user['password']);
        } elseif (strlen($user['password']) === 32 && ctype_xdigit($user['password'])) {
            // Es MD5 (32 caracteres hex)
            $debug[] = "Tipo: MD5";
            $debug[] = "MD5 entrada: " . md5($password);
            $passwordValida = (md5($password) === $user['password']);
        } elseif (substr($user['password'], 0, 4) === 'Admin' || substr($user['password'], 0, 4) === 'user') {
            // Es texto plano
            $debug[] = "Tipo: TEXTO PLANO";
            $debug[] = "Password BD: " . $user['password'];
            $passwordValida = ($password === $user['password']);
        } else {
            // Probar texto plano primero
            $debug[] = "Tipo: TEXTO PLANO (intento 1)";
            if ($password === $user['password']) {
                $passwordValida = true;
            } else {
                // Probar MD5
                $debug[] = "Tipo: MD5 (intento 2)";
                $passwordValida = (md5($password) === $user['password']);
            }
        }
        
        $debug[] = "Password válida: " . ($passwordValida ? 'SI' : 'NO');
        
        if (!$passwordValida) {
            echo json_encode([
                'success' => false, 
                'error' => 'Credenciales inválidas',
                'debug' => $debug
            ]);
            exit;
        }
        
        // Si es locatario, verificar que tenga condominio asignado
        $condominio_info = null;
        if ($user['tipo'] === 'locatario' && !empty($user['condominio_id'])) {
            $debug[] = "Buscando condominio para locatario...";
            $stmt = $conn->prepare("SELECT id, nombre, db_name FROM condominios WHERE id = ?");
            $stmt->execute([$user['condominio_id']]);
            $condominio_info = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($condominio_info) {
                $debug[] = "Condominio: " . $condominio_info['nombre'];
            } else {
                $debug[] = "ADVERTENCIA: Locatario sin condominio asignado";
            }
        }
        
        // Generar token simple
        $token = bin2hex(random_bytes(32));
        $debug[] = "Login exitoso!";
        
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
