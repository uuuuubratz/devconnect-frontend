<?php
include_once 'config.php';

$database = new Database();
$db = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    getDevelopers($db);
}

function getDevelopers($db) {
    $languages_filter = isset($_GET['languages']) ? explode(',', $_GET['languages']) : [];
    $areas_filter = isset($_GET['areas']) ? explode(',', $_GET['areas']) : [];
    
    try {
        $query = "
            SELECT 
                u.id,
                u.username,
                u.nombres,
                u.apellidos,
                u.fecha_nacimiento,
                GROUP_CONCAT(DISTINCT l.nombre) AS lenguajes,
                GROUP_CONCAT(DISTINCT a.nombre) AS areas
            FROM usuarios u
            JOIN tipo_usuario tu ON u.tipo_id = tu.id
            LEFT JOIN desarrollador_lenguajes dl ON u.id = dl.desarrollador_id
            LEFT JOIN lenguajes l ON dl.lenguaje_id = l.id
            LEFT JOIN desarrollador_areas da ON u.id = da.desarrollador_id
            LEFT JOIN areas_especializacion a ON da.area_id = a.id
            WHERE tu.nombre = 'desarrollador' AND u.activo = TRUE
        ";
        
        $conditions = [];
        $params = [];
        
        if (!empty($languages_filter)) {
            $placeholders = str_repeat('?,', count($languages_filter) - 1) . '?';
            $conditions[] = "l.nombre IN ($placeholders)";
            $params = array_merge($params, $languages_filter);
        }
        
        if (!empty($areas_filter)) {
            $placeholders = str_repeat('?,', count($areas_filter) - 1) . '?';
            $conditions[] = "a.nombre IN ($placeholders)";
            $params = array_merge($params, $areas_filter);
        }
        
        if (!empty($conditions)) {
            $query .= " AND (" . implode(" OR ", $conditions) . ")";
        }
        
        $query .= " GROUP BY u.id ORDER BY u.nombres, u.apellidos";
        
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        
        $developers = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $developers[] = [
                "id" => $row['id'],
                "username" => $row['username'],
                "firstName" => $row['nombres'],
                "lastName" => $row['apellidos'],
                "birthDate" => $row['fecha_nacimiento'],
                "languages" => $row['lenguajes'] ? explode(',', $row['lenguajes']) : [],
                "areas" => $row['areas'] ? explode(',', $row['areas']) : []
            ];
        }
        
        echo json_encode(["success" => true, "developers" => $developers]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["error" => "Error al obtener desarrolladores: " . $e->getMessage()]);
    }
}
?>