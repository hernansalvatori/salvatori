<?php
// casos_api.php
header('Content-Type: application/json; charset=utf-8');

// Ruta del archivo JSON donde se guardan los casos
const CASOS_FILE = __DIR__ . '/casos_data.json';

// Carpeta para subir imágenes
const UPLOAD_DIR = __DIR__ . '/uploads/casos';

// Asegurar que exista la carpeta de uploads
if (!is_dir(UPLOAD_DIR)) {
    @mkdir(UPLOAD_DIR, 0775, true);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function leer_casos() {
    if (!file_exists(CASOS_FILE)) {
        return [];
    }
    $json = file_get_contents(CASOS_FILE);
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return [];
    }
    return $data;
}

function guardar_casos($casos) {
    $fp = fopen(CASOS_FILE, 'c+');
    if (!$fp) return false;

    // bloquear archivo
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($casos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

function guardar_imagen($campo) {
    if (!isset($_FILES[$campo]) || $_FILES[$campo]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $tmp  = $_FILES[$campo]['tmp_name'];
    $name = basename($_FILES[$campo]['name']);

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp','gif'])) {
        return null;
    }

    $nuevoNombre = uniqid($campo . '_', true) . '.' . $ext;
    $destino = UPLOAD_DIR . '/' . $nuevoNombre;

    if (!move_uploaded_file($tmp, $destino)) {
        return null;
    }

    // ruta relativa para usar desde el navegador
    $rutaWeb = 'uploads/casos/' . $nuevoNombre;
    return $rutaWeb;
}

// ==== RUTEO SIMPLE POR ACCIÓN ====
switch ($action) {

    case 'list':
        $casos = leer_casos();
        echo json_encode(['ok' => true, 'casos' => array_values($casos)]);
        break;

    case 'create':
        $casos = leer_casos();

        $id = uniqid('caso_', true);
        $titulo      = $_POST['titulo']      ?? '';
        $tipo        = $_POST['tipo']        ?? '';
        $problema    = $_POST['problema']    ?? '';
        $solucion    = $_POST['solucion']    ?? '';
        $resultado   = $_POST['resultado']   ?? '';
        $cliente     = $_POST['cliente']     ?? '';
        $fecha       = $_POST['fecha']       ?? date('Y-m-d');

        $fotoAntes   = guardar_imagen('fotoAntes');
        $fotoDespues = guardar_imagen('fotoDespues');

        $nuevo = [
            'id'          => $id,
            'titulo'      => $titulo,
            'tipo'        => $tipo,
            'problema'    => $problema,
            'solucion'    => $solucion,
            'resultado'   => $resultado,
            'cliente'     => $cliente,
            'fecha'       => $fecha,
            'fotoAntes'   => $fotoAntes,
            'fotoDespues' => $fotoDespues
        ];

        $casos[$id] = $nuevo;

        if (guardar_casos($casos)) {
            echo json_encode(['ok' => true, 'caso' => $nuevo]);
        } else {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'No se pudo guardar el caso.']);
        }
        break;

    case 'update':
        $casos = leer_casos();
        $id = $_POST['id'] ?? '';

        if (!$id || !isset($casos[$id])) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Caso no encontrado.']);
            break;
        }

        $caso = $casos[$id];

        $caso['titulo']    = $_POST['titulo']    ?? $caso['titulo'];
        $caso['tipo']      = $_POST['tipo']      ?? $caso['tipo'];
        $caso['problema']  = $_POST['problema']  ?? $caso['problema'];
        $caso['solucion']  = $_POST['solucion']  ?? $caso['solucion'];
        $caso['resultado'] = $_POST['resultado'] ?? $caso['resultado'];
        $caso['cliente']   = $_POST['cliente']   ?? $caso['cliente'];
        $caso['fecha']     = $_POST['fecha']     ?? $caso['fecha'];

        // si se sube nueva foto, la sustituimos
        $nuevaAntes = guardar_imagen('fotoAntes');
        $nuevaDesp  = guardar_imagen('fotoDespues');

        if ($nuevaAntes) {
            $caso['fotoAntes'] = $nuevaAntes;
        }
        if ($nuevaDesp) {
            $caso['fotoDespues'] = $nuevaDesp;
        }

        $casos[$id] = $caso;

        if (guardar_casos($casos)) {
            echo json_encode(['ok' => true, 'caso' => $caso]);
        } else {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'No se pudo actualizar el caso.']);
        }
        break;

    case 'delete':
        $casos = leer_casos();
        $id = $_POST['id'] ?? '';

        if (!$id || !isset($casos[$id])) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Caso no encontrado.']);
            break;
        }

        // opcional: borrar imágenes asociadas
        $c = $casos[$id];
        foreach (['fotoAntes','fotoDespues'] as $campo) {
            if (!empty($c[$campo])) {
                $ruta = __DIR__ . '/' . $c[$campo];
                if (file_exists($ruta)) {
                    @unlink($ruta);
                }
            }
        }

        unset($casos[$id]);
        if (guardar_casos($casos)) {
            echo json_encode(['ok' => true]);
        } else {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'No se pudo eliminar el caso.']);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Acción no válida.']);
        break;
}
