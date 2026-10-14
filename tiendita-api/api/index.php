<?php
/**
 * API RESTful - Tiendita (recurso: productos)
 * Rutas:
 *   GET    /api/v1/productos        -> lista completa
 *   GET    /api/v1/productos/{id}   -> un registro
 *   POST   /api/v1/productos        -> crear
 *   PUT    /api/v1/productos/{id}   -> actualizar
 *   DELETE /api/v1/productos/{id}   -> eliminar
 */

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

/* ---------- Utilidades ---------- */

function responder(int $codigo, array $payload): void
{
    http_response_code($codigo);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function error(int $codigo, string $mensaje, array $extra = []): void
{
    responder($codigo, array_merge(['status' => 'error', 'message' => $mensaje], $extra));
}

function formatear(array $fila): array
{
    return [
        'id'           => (int) $fila['id'],
        'nombre'       => $fila['nombre'],
        'precio'       => (float) $fila['precio'],
        'stock'        => (int) $fila['stock'],
        'categoria_id' => (int) $fila['categoria_id'],
        'categoria'    => $fila['categoria'],
    ];
}

/** Lee y valida el JSON del cuerpo de la petición. */
function leerJson(): array
{
    $crudo = file_get_contents('php://input');
    if ($crudo === false || trim($crudo) === '') {
        error(400, 'El cuerpo de la petición está vacío. Envía un JSON válido.');
    }
    $datos = json_decode($crudo, true);
    if (!is_array($datos) || json_last_error() !== JSON_ERROR_NONE) {
        error(400, 'El cuerpo de la petición no es un JSON válido.');
    }
    return $datos;
}

/**
 * Valida los campos del producto (POST y PUT exigen todos los campos).
 * Acepta "categoria_id" (número) o "categoria" (nombre de la categoría).
 */
function validarProducto(array $d): array
{
    $errores = [];

    $nombre = isset($d['nombre']) && is_string($d['nombre']) ? trim($d['nombre']) : '';
    if ($nombre === '' || mb_strlen($nombre) > 100) {
        $errores['nombre'] = 'Requerido, texto de 1 a 100 caracteres.';
    }

    if (!isset($d['precio']) || !is_numeric($d['precio']) || (float) $d['precio'] < 0) {
        $errores['precio'] = 'Requerido, número mayor o igual a 0.';
    }

    if (!isset($d['stock']) || filter_var($d['stock'], FILTER_VALIDATE_INT) === false || (int) $d['stock'] < 0) {
        $errores['stock'] = 'Requerido, entero mayor o igual a 0.';
    }

    $categoriaId = null;
    $categoriaNombre = null;
    if (isset($d['categoria_id'])) {
        if (filter_var($d['categoria_id'], FILTER_VALIDATE_INT) === false || (int) $d['categoria_id'] <= 0) {
            $errores['categoria_id'] = 'Debe ser un entero positivo.';
        } else {
            $categoriaId = (int) $d['categoria_id'];
        }
    } elseif (isset($d['categoria']) && is_string($d['categoria']) && trim($d['categoria']) !== '') {
        $categoriaNombre = trim($d['categoria']);
    } else {
        $errores['categoria'] = 'Envía "categoria" (nombre) o "categoria_id" (número).';
    }

    if ($errores) {
        error(400, 'Datos inválidos.', ['errors' => $errores]);
    }

    return [
        'nombre'           => $nombre,
        'precio'           => round((float) $d['precio'], 2),
        'stock'            => (int) $d['stock'],
        'categoria_id'     => $categoriaId,
        'categoria_nombre' => $categoriaNombre,
    ];
}

/** Devuelve el id de la categoría; si llega por nombre y no existe, la crea. */
function resolverCategoria(mysqli $conn, array $p): int
{
    if ($p['categoria_id'] !== null) {
        $stmt = $conn->prepare('SELECT id FROM categoria WHERE id = ?');
        $stmt->bind_param('i', $p['categoria_id']);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$fila) {
            error(400, 'Datos inválidos.', ['errors' => ['categoria_id' => 'La categoría indicada no existe.']]);
        }
        return (int) $fila['id'];
    }

    $stmt = $conn->prepare('SELECT id FROM categoria WHERE nombre = ?');
    $stmt->bind_param('s', $p['categoria_nombre']);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($fila) {
        return (int) $fila['id'];
    }

    $stmt = $conn->prepare('INSERT INTO categoria (nombre) VALUES (?)');
    $stmt->bind_param('s', $p['categoria_nombre']);
    $stmt->execute();
    $nuevo = (int) $conn->insert_id;
    $stmt->close();
    return $nuevo;
}

function buscarProducto(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare(
        'SELECT p.id, p.nombre, p.precio, p.stock, p.categoria_id, c.nombre AS categoria
           FROM producto p
           JOIN categoria c ON p.categoria_id = c.id
          WHERE p.id = ?'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $fila ? formatear($fila) : null;
}

/* ---------- Enrutamiento ---------- */

$metodo = $_SERVER['REQUEST_METHOD'];

if ($metodo === 'OPTIONS') {          // pre-flight CORS
    http_response_code(204);
    exit;
}

$ruta = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!preg_match('#/api/v1/([a-z]+)(?:/([^/]+))?/?$#i', $ruta, $m)) {
    error(404, 'Ruta no encontrada. Usa /api/v1/productos');
}
$recurso = strtolower($m[1]);
$idCrudo = $m[2] ?? null;

if ($recurso !== 'productos') {
    error(404, "El recurso '{$recurso}' no existe. Usa /api/v1/productos");
}

$id = null;
if ($idCrudo !== null) {
    if (!ctype_digit($idCrudo) || (int) $idCrudo <= 0) {
        error(400, 'El ID debe ser un número entero positivo.');
    }
    $id = (int) $idCrudo;
}

/* ---------- Conexión ---------- */

try {
    $cfg = require __DIR__ . '/config.php';
    $conn = new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['name']);
    $conn->set_charset('utf8mb4');
} catch (Throwable $e) {
    error_log('[API tiendita] Conexión BD: ' . $e->getMessage());
    error(500, 'Error interno del servidor: no se pudo conectar a la base de datos.');
}

/* ---------- Controladores ---------- */

try {
    switch ($metodo) {

        case 'GET':
            if ($id === null) {
                $res = $conn->query(
                    'SELECT p.id, p.nombre, p.precio, p.stock, p.categoria_id, c.nombre AS categoria
                       FROM producto p
                       JOIN categoria c ON p.categoria_id = c.id
                      ORDER BY p.id ASC'
                );
                $datos = [];
                while ($fila = $res->fetch_assoc()) {
                    $datos[] = formatear($fila);
                }
                responder(200, ['status' => 'success', 'total' => count($datos), 'data' => $datos]);
            }
            $producto = buscarProducto($conn, $id);
            if (!$producto) {
                error(404, "No existe un producto con id {$id}.");
            }
            responder(200, ['status' => 'success', 'data' => $producto]);
            break;

        case 'POST':
            if ($id !== null) {
                error(405, 'POST no se permite sobre un ID. Usa POST /api/v1/productos');
            }
            $p = validarProducto(leerJson());
            $catId = resolverCategoria($conn, $p);
            $stmt = $conn->prepare('INSERT INTO producto (nombre, precio, stock, categoria_id) VALUES (?, ?, ?, ?)');
            $stmt->bind_param('sdii', $p['nombre'], $p['precio'], $p['stock'], $catId);
            $stmt->execute();
            $nuevoId = (int) $conn->insert_id;
            $stmt->close();
            responder(201, [
                'status'  => 'success',
                'message' => 'Registro creado exitosamente',
                'id'      => $nuevoId,
            ]);
            break;

        case 'PUT':
            if ($id === null) {
                error(400, 'Falta el ID en la URL. Usa PUT /api/v1/productos/{id}');
            }
            $p = validarProducto(leerJson());
            if (!buscarProducto($conn, $id)) {
                error(404, "No existe un producto con id {$id}.");
            }
            $catId = resolverCategoria($conn, $p);
            $stmt = $conn->prepare('UPDATE producto SET nombre = ?, precio = ?, stock = ?, categoria_id = ? WHERE id = ?');
            $stmt->bind_param('sdiii', $p['nombre'], $p['precio'], $p['stock'], $catId, $id);
            $stmt->execute();
            $stmt->close();
            responder(200, ['status' => 'success', 'message' => 'Registro actualizado correctamente']);
            break;

        case 'DELETE':
            if ($id === null) {
                error(400, 'Falta el ID en la URL. Usa DELETE /api/v1/productos/{id}');
            }
            if (!buscarProducto($conn, $id)) {
                error(404, "No existe un producto con id {$id}.");
            }
            $stmt = $conn->prepare('DELETE FROM producto WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            responder(200, ['status' => 'success', 'message' => 'Registro eliminado correctamente']);
            break;

        default:
            header('Allow: GET, POST, PUT, DELETE, OPTIONS');
            error(405, "Método {$metodo} no permitido.");
    }
} catch (Throwable $e) {
    error_log('[API tiendita] ' . $e->getMessage());
    error(500, 'Error interno del servidor.');
}
