<?php
require_once 'database.php';

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

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
        $debug[] = "Buscando usuario en BD principal...";
        $stmt = $conn->prepare("
            SELECT id, nombre, email, password, tipo, condominio_id, activo 
            FROM usuarios 
            WHERE email = ?
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            $debug[] = "Usuario NO encontrado en BD principal, buscando en condominios...";
            
            // Buscar en las tablas de locatarios de cada condominio
            $stmtCond = $conn->query("SELECT id, nombre, db_name FROM condominios WHERE activo = 1");
            $condominios = $stmtCond->fetchAll(PDO::FETCH_ASSOC);
            
            $locatarioEncontrado = null;
            $condominioDbName = null;
            
            foreach ($condominios as $cond) {
                try {
                    $condConn = connectCondominioDB($cond['db_name']);
                    if ($condConn) {
                        $stmtLoc = $condConn->prepare("
                            SELECT id, nombre, email, password, activo 
                            FROM locatarios 
                            WHERE email = ? AND activo = 1
                            LIMIT 1
                        ");
                        $stmtLoc->execute([$email]);
                        $locatario = $stmtLoc->fetch(PDO::FETCH_ASSOC);
                        
                        if ($locatario) {
                            $debug[] = "Locatario encontrado en: " . $cond['nombre'];
                            $locatarioEncontrado = $locatario;
                            $condominioDbName = $cond['db_name'];
                            $condominioId = $cond['id'];
                            $condominioNombre = $cond['nombre'];
                            break;
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
                    'error' => 'Credenciales inválidas',
                    'debug' => $debug
                ]);
                exit;
            }
            
            // Verificar contraseña del locatario
            $debug[] = "Verificando contraseña del locatario...";
            $passwordValida = false;
            $passwordLoc = $locatarioEncontrado['password'];
            
            if (substr($passwordLoc, 0, 2) === '$2') {
                $passwordValida = password_verify($password, $passwordLoc);
            } elseif (strlen($passwordLoc) === 32 && ctype_xdigit($passwordLoc)) {
                $passwordValida = (md5($password) === $passwordLoc);
            } else {
                $passwordValida = ($password === $passwordLoc);
            }
            
            if (!$passwordValida) {
                $debug[] = "Contraseña inválida";
                echo json_encode([
                    'success' => false, 
                    'error' => 'Credenciales inválidas',
                    'debug' => $debug
                ]);
                exit;
            }
            
            // Crear usuario en la BD principal si no existe
            $debug[] = "Creando usuario en BD principal...";
            $stmtNewUser = $conn->prepare("
                INSERT INTO usuarios (nombre, email, password, tipo, condominio_id, activo) 
                VALUES (?, ?, ?, 'locatario', ?, 1)
                ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), password = VALUES(password), condominio_id = VALUES(condominio_id)
            ");
            $stmtNewUser->execute([
                $locatarioEncontrado['nombre'],
                $email,
                $passwordLoc,
                $condominioId
            ]);
            
            // Obtener el usuario creado
            $stmt = $conn->prepare("SELECT id, nombre, email, password, tipo, condominio_id, activo FROM usuarios WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $debug[] = "Usuario creado/encontrado: " . $user['nombre'];
            
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
            exit;
        }
        
        $debug[] = "Usuario encontrado: " . $user['nombre'] . " (tipo: " . $user['tipo'] . ", activo: " . $user['activo'] . ")";
        
        if ($user['activo'] != 1) {
            $debug[] = "Usuario INACTIVO";
            echo json_encode([
                'success' => false, 
                'error' => 'Usuario inactivo',
                'debug' => $debug
            ]);
            exit;
        }
        
        // Verificar contraseña
        $debug[] = "Verificando contraseña...";
        $passwordValida = false;
        
        if (substr($user['password'], 0, 2) === '$2') {
            $passwordValida = password_verify($password, $user['password']);
        } elseif (strlen($user['password']) === 32 && ctype_xdigit($user['password'])) {
            $passwordValida = (md5($password) === $user['password']);
        } else {
            $passwordValida = ($password === $user['password']);
        }
        
        if (!$passwordValida) {
            echo json_encode([
                'success' => false, 
                'error' => 'Credenciales inválidas',
                'debug' => $debug
            ]);
            exit;
        }
        
        // Si es locatario, obtener info del condominio
        $condominio_info = null;
        if ($user['tipo'] === 'locatario' && !empty($user['condominio_id'])) {
            $stmt = $conn->prepare("SELECT id, nombre, db_name FROM condominios WHERE id = ?");
            $stmt->execute([$user['condominio_id']]);
            $condominio_info = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
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
