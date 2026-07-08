<?php
require_once 'database.php';

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$user_id = $_POST['user_id'] ?? $_GET['user_id'] ?? null;
$condominio_db = $_POST['condominio_db'] ?? $_GET['condominio_db'] ?? '';

$debug = [];

// Acción para obtener perfil
if ($action === 'get_perfil') {
    if (!$user_id || !$condominio_db) {
        echo json_encode(['success' => false, 'error' => 'Parámetros incompletos']);
        exit;
    }
    
    try {
        $stmtUsuario = $conn->prepare("SELECT locatario_id, nombre, email FROM usuarios WHERE id = ?");
        $stmtUsuario->execute([$user_id]);
        $usuario = $stmtUsuario->fetch(PDO::FETCH_ASSOC);
        
        if (!$usuario) {
            echo json_encode(['success' => false, 'error' => 'Usuario no encontrado']);
            exit;
        }
        
        $locatario_id = $usuario['locatario_id'] ?? $user_id;
        
        $condominioConn = connectCondominioDB($condominio_db);
        if (!$condominioConn) {
            echo json_encode(['success' => false, 'error' => 'No se pudo conectar al condominio']);
            exit;
        }
        
        $stmt = $condominioConn->prepare("SELECT id, nombre, apellido, identificacion, email, telefono, password FROM locatarios WHERE id = ?");
        $stmt->execute([$locatario_id]);
        $locatario = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$locatario) {
            echo json_encode(['success' => false, 'error' => 'Locatario no encontrado']);
            exit;
        }
        
        echo json_encode(['success' => true, 'locatario' => $locatario]);
        
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// Acción para actualizar perfil
if ($action === 'actualizar_perfil') {
    $nombre = $_POST['nombre'] ?? '';
    $apellido = $_POST['apellido'] ?? '';
    $identificacion = $_POST['identificacion'] ?? '';
    $email = $_POST['email'] ?? '';
    $telefono = $_POST['telefono'] ?? '';
    $password = md5($_POST['password'] ?? '');
    $password_usuario = md5($_POST['password_usuario'] ?? '');
    
    if (!$user_id || !$condominio_db) {
        echo json_encode(['success' => false, 'error' => 'Parámetros incompletos']);
        exit;
    }
    
    try {
        $stmtUsuario = $conn->prepare("SELECT locatario_id FROM usuarios WHERE id = ?");
        $stmtUsuario->execute([$user_id]);
        $usuario = $stmtUsuario->fetch(PDO::FETCH_ASSOC);
        
        if (!$usuario) {
            echo json_encode(['success' => false, 'error' => 'Usuario no encontrado']);
            exit;
        }
        
        $locatario_id = $usuario['locatario_id'] ?? $user_id;
        
        $condominioConn = connectCondominioDB($condominio_db);
        if (!$condominioConn) {
            echo json_encode(['success' => false, 'error' => 'No se pudo conectar al condominio']);
            exit;
        }
        
        if (!empty($password)) {
            $stmt = $condominioConn->prepare("UPDATE locatarios SET nombre = ?, apellido = ?, identificacion = ?, email = ?, telefono = ?, password = ? WHERE id = ?");
            $stmt->execute([$nombre, $apellido, $identificacion, $email, $telefono, $password, $locatario_id]);
        } else {
            $stmt = $condominioConn->prepare("UPDATE locatarios SET nombre = ?, apellido = ?, identificacion = ?, email = ?, telefono = ? WHERE id = ?");
            $stmt->execute([$nombre, $apellido, $identificacion, $email, $telefono, $locatario_id]);
        }
        
        if (!empty($password_usuario)) {
            $stmtUser = $conn->prepare("UPDATE usuarios SET nombre = ?, email = ?, telefono = ?, password = ? WHERE id = ?");
            $stmtUser->execute([$nombre . ' ' . $apellido, $email, $telefono, $password_usuario, $user_id]);
        } else {
            $stmtUser = $conn->prepare("UPDATE usuarios SET nombre = ?, email = ?, telefono = ? WHERE id = ?");
            $stmtUser->execute([$nombre . ' ' . $apellido, $email, $telefono, $user_id]);
        }
        
        echo json_encode(['success' => true, 'mensaje' => 'Perfil actualizado correctamente']);
        
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Acción no válida']);