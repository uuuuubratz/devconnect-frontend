<?php

date_default_timezone_set('America/Mexico_City');
header('Content-Type: application/json');

// IMPORTANTE: Configurar CORS para permitir GitHub Pages
$allowed_origins = [
    'https://uuuuubratz.github.io',
    'http://localhost:8000',  // Para desarrollo local
    'http://127.0.0.1:8000'
];

$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
}

header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

class Database {
    // CAMBIA ESTOS VALORES con los de InfinityFree
    private $host = "sql100.infinityfree.com";
    private $db_name = "if0_40178006_db_name";
    private $username = "if0_40178006";
    private $password = "ANPAnm29zWz";
    public $conn;

    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4",
                $this->username,
                $this->password,
                array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4")
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $exception) {
            http_response_code(500);
            echo json_encode(["error" => "Error de conexión: " . $exception->getMessage()]);
            exit;
        }
        return $this->conn;
    }
}

function getAuthorizationHeader() {
    $headers = null;
    if (isset($_SERVER['Authorization'])) {
        $headers = trim($_SERVER['Authorization']);
    } else if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $headers = trim($_SERVER['HTTP_AUTHORIZATION']);
    } elseif (function_exists('apache_request_headers')) {
        $requestHeaders = apache_request_headers();
        $requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));
        if (isset($requestHeaders['Authorization'])) {
            $headers = trim($requestHeaders['Authorization']);
        }
    }
    return $headers;
}

function getBearerToken() {
    $headers = getAuthorizationHeader();
    if (!empty($headers)) {
        if (preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
            return $matches[1];
        }
    }
    return null;
}

function verifyToken($db) {
    $token = getBearerToken();
    if (!$token) {
        http_response_code(401);
        echo json_encode(["error" => "Token no proporcionado"]);
        exit;
    }

    try {
        $query = "SELECT u.*, tu.nombre as tipo 
                  FROM usuarios u 
                  JOIN tipo_usuario tu ON u.tipo_id = tu.id 
                  WHERE u.id = ? AND u.activo = TRUE";
        $stmt = $db->prepare($query);
        $stmt->execute([$token]);
        
        if ($stmt->rowCount() > 0) {
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            return $user;
        } else {
            http_response_code(401);
            echo json_encode(["error" => "Token inválido"]);
            exit;
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["error" => "Error al verificar token: " . $e->getMessage()]);
        exit;
    }
}

?>