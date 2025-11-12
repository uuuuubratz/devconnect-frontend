<?php
include_once 'config.php';

$database = new Database();
$db = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    $current_user = verifyToken($db);
    
    if (isset($_GET['action'])) {
        switch($_GET['action']) {
            case 'conversations':
                getConversations($db, $current_user);
                break;
            case 'messages':
                if (isset($_GET['conversation_id'])) {
                    getMessages($db, $_GET['conversation_id'], $current_user);
                } else {
                    http_response_code(400);
                    echo json_encode(["error" => "ID de conversación requerido"]);
                }
                break;
            default:
                http_response_code(400);
                echo json_encode(["error" => "Acción no válida"]);
        }
    } else {
        http_response_code(400);
        echo json_encode(["error" => "Acción no especificada"]);
    }
} else if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $current_user = verifyToken($db);
    $data = json_decode(file_get_contents("php://input"));
    
    if (isset($data->action)) {
        switch($data->action) {
            case 'send':
                sendMessage($db, $current_user, $data);
                break;
            case 'create':
                createConversation($db, $current_user, $data);
                break;
            default:
                http_response_code(400);
                echo json_encode(["error" => "Acción no válida"]);
        }
    } else {
        http_response_code(400);
        echo json_encode(["error" => "Acción no especificada"]);
    }
}

function getConversations($db, $user) {
    try {
        $query = "
            SELECT 
                c.id AS conversacion_id,
                c.oferta_id,
                COALESCE(o.titulo, 'Contacto Directo') AS oferta_titulo,
                COALESCE(o.empresa, '-') AS empresa,
                CASE 
                    WHEN c.usuario1_id = ? THEN c.usuario2_id
                    ELSE c.usuario1_id
                END AS otro_usuario_id,
                uo.nombres AS otro_usuario_nombres,
                uo.apellidos AS otro_usuario_apellidos,
                ultimo.mensaje AS ultimo_mensaje,
                ultimo.fecha_envio AS ultimo_mensaje_fecha,
                (SELECT COUNT(*) 
                 FROM mensajes m 
                 WHERE m.conversacion_id = c.id 
                 AND m.leido = FALSE 
                 AND m.remitente_id != ?) AS mensajes_no_leidos
            FROM conversaciones c
            LEFT JOIN ofertas_trabajo o ON c.oferta_id = o.id
            JOIN usuarios uo ON (
                CASE 
                    WHEN c.usuario1_id = ? THEN c.usuario2_id
                    ELSE c.usuario1_id
                END
            ) = uo.id
            LEFT JOIN (
                SELECT 
                    m1.conversacion_id,
                    m1.mensaje,
                    m1.fecha_envio
                FROM mensajes m1
                JOIN (
                    SELECT 
                        conversacion_id, 
                        MAX(fecha_envio) AS max_fecha
                    FROM mensajes 
                    GROUP BY conversacion_id
                ) m2 ON m1.conversacion_id = m2.conversacion_id AND m1.fecha_envio = m2.max_fecha
            ) ultimo ON c.id = ultimo.conversacion_id
            WHERE c.usuario1_id = ? OR c.usuario2_id = ?
            ORDER BY COALESCE(ultimo.fecha_envio, c.fecha_creacion) DESC
        ";
        
        $stmt = $db->prepare($query);
        $stmt->execute([$user['id'], $user['id'], $user['id'], $user['id'], $user['id']]);
        
        $conversations = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $conversations[] = [
                "id" => $row['conversacion_id'],
                "jobId" => $row['oferta_id'],
                "jobTitle" => $row['oferta_titulo'],
                "company" => $row['empresa'],
                "otherUserId" => $row['otro_usuario_id'],
                "otherUserName" => $row['otro_usuario_nombres'] . ' ' . $row['otro_usuario_apellidos'],
                "lastMessage" => $row['ultimo_mensaje'],
                "lastMessageDate" => $row['ultimo_mensaje_fecha'],
                "unreadMessages" => (int)$row['mensajes_no_leidos']
            ];
        }
        
        echo json_encode(["success" => true, "conversations" => $conversations]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["error" => "Error al obtener conversaciones: " . $e->getMessage()]);
    }
}

function getMessages($db, $conversation_id, $current_user) {
    try {
        $check_query = "SELECT id FROM conversaciones 
                       WHERE id = ? AND (usuario1_id = ? OR usuario2_id = ?)";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->execute([$conversation_id, $current_user['id'], $current_user['id']]);
        
        if ($check_stmt->rowCount() === 0) {
            http_response_code(403);
            echo json_encode(["error" => "No tienes acceso a esta conversación"]);
            return;
        }

        $query = "
            SELECT 
                m.id,
                m.remitente_id,
                u.nombres,
                u.apellidos,
                m.mensaje,
                m.fecha_envio,
                m.leido,
                DATE(m.fecha_envio) AS fecha_mensaje
            FROM mensajes m
            JOIN usuarios u ON m.remitente_id = u.id
            WHERE m.conversacion_id = ?
            ORDER BY m.fecha_envio ASC
        ";
        
        $stmt = $db->prepare($query);
        $stmt->execute([$conversation_id]);
        
        $messages = [];
        $last_date = null;
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $current_date = $row['fecha_mensaje'];
            
            if ($last_date !== $current_date) {
                $messages[] = [
                    "id" => "date_" . $current_date,
                    "type" => "date_separator",
                    "date" => $current_date,
                    "dateFormatted" => formatDateSeparator($current_date)
                ];
                $last_date = $current_date;
            }
            
            $messages[] = [
                "id" => $row['id'],
                "senderId" => $row['remitente_id'],
                "senderName" => $row['nombres'] . ' ' . $row['apellidos'],
                "message" => $row['mensaje'],
                "timestamp" => $row['fecha_envio'],
                "time" => substr($row['fecha_envio'], 11, 5),
                "read" => (bool)$row['leido'],
                "type" => "message"
            ];
        }
        
        markMessagesAsRead($db, $conversation_id, $current_user['id']);
        
        echo json_encode(["success" => true, "messages" => $messages]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["error" => "Error al obtener mensajes: " . $e->getMessage()]);
    }
}

function formatDateSeparator($date) {
    $fecha = new DateTime($date);
    $hoy = new DateTime();
    $ayer = clone $hoy;
    $ayer->modify('-1 day');
    
    if ($fecha->format('Y-m-d') === $hoy->format('Y-m-d')) {
        return 'Hoy';
    } elseif ($fecha->format('Y-m-d') === $ayer->format('Y-m-d')) {
        return 'Ayer';
    } else {
        return $fecha->format('d/m/Y');
    }
}

function markMessagesAsRead($db, $conversation_id, $current_user_id) {
    try {
        $update_query = "UPDATE mensajes SET leido = TRUE 
                        WHERE conversacion_id = ? 
                        AND remitente_id != ? 
                        AND leido = FALSE";
        $update_stmt = $db->prepare($update_query);
        $update_stmt->execute([$conversation_id, $current_user_id]);
    } catch (PDOException $e) {
        // No hacemos nada si falla
    }
}

function sendMessage($db, $user, $data) {
    if (!isset($data->conversation_id) || !isset($data->message) || empty(trim($data->message))) {
        http_response_code(400);
        echo json_encode(["error" => "ID de conversación y mensaje válido requeridos"]);
        return;
    }

    try {
        $check_query = "SELECT id FROM conversaciones 
                       WHERE id = ? AND (usuario1_id = ? OR usuario2_id = ?)";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->execute([$data->conversation_id, $user['id'], $user['id']]);
        
        if ($check_stmt->rowCount() === 0) {
            http_response_code(403);
            echo json_encode(["error" => "No tienes acceso a esta conversación"]);
            return;
        }

        $query = "INSERT INTO mensajes (conversacion_id, remitente_id, mensaje) VALUES (?, ?, ?)";
        $stmt = $db->prepare($query);
        $stmt->execute([$data->conversation_id, $user['id'], trim($data->message)]);
        
        echo json_encode([
            "success" => true,
            "message" => "Mensaje enviado",
            "message_id" => $db->lastInsertId()
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["error" => "Error al enviar mensaje: " . $e->getMessage()]);
    }
}

// ===== FUNCIÓN CORREGIDA: createConversation =====
function createConversation($db, $user, $data) {
    if (!isset($data->other_user_id)) {
        http_response_code(400);
        echo json_encode(["error" => "Otro usuario requerido"]);
        return;
    }

    try {
        // Validar el otro usuario
        $user_query = "SELECT u.id, tu.nombre as tipo 
                      FROM usuarios u 
                      JOIN tipo_usuario tu ON u.tipo_id = tu.id 
                      WHERE u.id = ? AND u.activo = TRUE";
        $user_stmt = $db->prepare($user_query);
        $user_stmt->execute([$data->other_user_id]);
        
        if ($user_stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(["error" => "Usuario no encontrado"]);
            return;
        }
        
        $other_user = $user_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Validar que sean de diferente tipo (empleador ↔ desarrollador)
        if ($user['tipo'] === $other_user['tipo']) {
            http_response_code(400);
            echo json_encode(["error" => "Solo se pueden crear conversaciones entre empleadores y desarrolladores"]);
            return;
        }

        // CAMBIO: Si no hay oferta_id, permitir conversación sin oferta
        $job_id = isset($data->job_id) ? $data->job_id : null;
        
        // Si hay job_id, validar que exista
        if ($job_id) {
            $job_query = "SELECT id FROM ofertas_trabajo WHERE id = ? AND activa = TRUE";
            $job_stmt = $db->prepare($job_query);
            $job_stmt->execute([$job_id]);
            
            if ($job_stmt->rowCount() === 0) {
                $job_id = null;  // Si no existe, continuar sin oferta
            }
        }
        
        // Buscar conversación existente
        if ($job_id) {
            // Buscar con oferta específica
            $check_query = "SELECT id FROM conversaciones 
                           WHERE oferta_id = ? 
                           AND ((usuario1_id = ? AND usuario2_id = ?) 
                                OR (usuario1_id = ? AND usuario2_id = ?))";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->execute([$job_id, $user['id'], $data->other_user_id, $data->other_user_id, $user['id']]);
        } else {
            // Buscar sin oferta (contacto directo)
            $check_query = "SELECT id FROM conversaciones 
                           WHERE oferta_id IS NULL
                           AND ((usuario1_id = ? AND usuario2_id = ?) 
                                OR (usuario1_id = ? AND usuario2_id = ?))";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->execute([$user['id'], $data->other_user_id, $data->other_user_id, $user['id']]);
        }
        
        if ($check_stmt->rowCount() > 0) {
            // Conversación ya existe
            $conversation = $check_stmt->fetch(PDO::FETCH_ASSOC);
            $conversation_id = $conversation['id'];
        } else {
            // Crear nueva conversación
            $insert_query = "INSERT INTO conversaciones (oferta_id, usuario1_id, usuario2_id) 
                           VALUES (?, ?, ?)";
            $insert_stmt = $db->prepare($insert_query);
            $insert_stmt->execute([
                $job_id,  // Puede ser NULL
                min($user['id'], $data->other_user_id), 
                max($user['id'], $data->other_user_id)
            ]);
            $conversation_id = $db->lastInsertId();
        }
        
        echo json_encode([
            "success" => true,
            "conversation_id" => $conversation_id
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["error" => "Error al crear conversación: " . $e->getMessage()]);
    }
}
?>