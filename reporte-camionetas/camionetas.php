<?php
declare(strict_types=1);
date_default_timezone_set('America/Monterrey');
setlocale(LC_TIME, 'es_MX.UTF-8', 'es_MX', 'Spanish_Mexico');
require_once __DIR__ . '/config.php';

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money($n): string { return number_format((float)$n, 0); }
function post($k, $d='') { return trim((string)($_POST[$k] ?? $d)); }
function redirect_self(): void { header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit; }
function camioneta_model_year(string $modelo): int {
    if (preg_match('/(19|20)\d{2}/', $modelo, $m)) return (int)$m[0];
    return 0;
}
function mantenimiento_intervalo_km(string $modelo): int {
    $year = camioneta_model_year($modelo);
    // Regla solicitada: 2014 y 2015 avisan cada 5,000 km; modelos más nuevos cada 10,000 km.
    return in_array($year, [2014, 2015], true) ? 5000 : 10000;
}
function mantenimiento_base_km(PDO $pdo, array $t): int {
    $id = (int)($t['id'] ?? 0);
    $actual = (int)($t['kilometraje_actual'] ?? 0);
    if ($id <= 0) return $actual;

    // Primero se toma el kilometraje del último mantenimiento guardado.
    $stmt = $pdo->prepare("SELECT kilometraje FROM camioneta_mantenimientos WHERE camioneta_id=? AND kilometraje IS NOT NULL ORDER BY fecha_mantenimiento DESC, id DESC LIMIT 1");
    $stmt->execute([$id]);
    $kmMant = $stmt->fetchColumn();
    if ($kmMant !== false && $kmMant !== null && (int)$kmMant >= 0) {
        return (int)$kmMant;
    }

    // Si todavía no hay mantenimiento registrado, el primer registro diario funciona como base.
    $stmt = $pdo->prepare("SELECT kilometraje FROM camioneta_indicadores_diarios WHERE camioneta_id=? ORDER BY fecha ASC, id ASC LIMIT 1");
    $stmt->execute([$id]);
    $kmInicial = $stmt->fetchColumn();
    if ($kmInicial !== false && $kmInicial !== null && (int)$kmInicial >= 0) {
        return (int)$kmInicial;
    }
    return $actual;
}
function mantenimiento_status(PDO $pdo, array $t): array {
    $intervalo = mantenimiento_intervalo_km((string)($t['modelo'] ?? ''));
    $actual = (int)($t['kilometraje_actual'] ?? 0);
    $base = mantenimiento_base_km($pdo, $t);
    $recorridos = max(0, $actual - $base);
    $proximo = $base + $intervalo;
    $restante = $proximo - $actual;
    return [
        'intervalo' => $intervalo,
        'base' => $base,
        'recorridos' => $recorridos,
        'proximo' => $proximo,
        'restante' => $restante,
        'alerta' => $restante <= 0,
    ];
}
function proximo_mantenimiento_km(array $t): int {
    $intervalo = mantenimiento_intervalo_km((string)($t['modelo'] ?? ''));
    $actual = (int)($t['kilometraje_actual'] ?? 0);
    return ((int)floor($actual / $intervalo) + 1) * $intervalo;
}

$errors = [];
$ok = '';
$rolSesion = strtolower(trim((string)($_SESSION['rol'] ?? '')));
$puestoSesion = '';
try {
    $userIdPerm = (int)($_SESSION['user_id'] ?? 0);
    if ($userIdPerm > 0) {
        $stmtPerm = $pdo->prepare("SELECT COALESCE(e.puesto, '') AS puesto FROM users u LEFT JOIN Empleados e ON e.id = u.empleado_id WHERE u.id = ? LIMIT 1");
        $stmtPerm->execute([$userIdPerm]);
        $puestoSesion = strtolower(trim((string)$stmtPerm->fetchColumn()));
    }
} catch (Throwable $e) {
    $puestoSesion = '';
}
$perfilPermisos = trim($rolSesion . ' ' . $puestoSesion);
$canManageTruck = false;
foreach (['admin','administrador','administrativo','supervisor','gerente','analista'] as $permitido) {
    if (str_contains($perfilPermisos, $permitido)) {
        $canManageTruck = true;
        break;
    }
}
// Modo demo/pruebas: acceso abierto para que puedan revisar el módulo.
// Cuando tu jefe lo apruebe, vuelve a activar permisos por rol aquí.
$canManageTruck = true;
$canUpdateDriver = true;
$canDeleteTruck = true;

// IMPORTANTE: Las tablas de camionetas NO se crean desde PHP.
// Primero importa camionetas_tablas.sql en phpMyAdmin dentro de tu base de datos.

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');
    try {
        if ($action === 'add_truck') {
            $eco = post('eco');
            if ($eco === '' || !ctype_digit($eco)) throw new RuntimeException('El número de eco solo debe llevar dígitos, sin letras ni símbolos.');
            $fechaIndicadores = post('fecha_indicadores') ?: date('Y-m-d');
            $modeloAnio = post('modelo_anio');
            $modeloTexto = post('modelo_texto');
            $modelo = trim(($modeloAnio !== '' ? $modeloAnio . ' ' : '') . $modeloTexto);
            if ($modelo === '') { $modelo = post('modelo'); }
            $serie = post('no_serie'); $matricula = post('matricula');
            $actividad = post('actividad', 'sin uso');
            $mant = post('fecha_ultimo_mantenimiento') ?: null;
            $km = max(0, (int)post('kilometraje_actual', '0'));
            $tanque = min(100, max(0, (int)post('tanque_gasolina', '0')));
            $nombre = post('nombre_conductor'); $tel = post('telefono_conductor'); $correo = post('correo_conductor'); $ruta = post('ruta_conductor'); $fechaUso = post('fecha_uso');
            if ($eco==='' || $modelo==='' || $serie==='' || $matricula==='') throw new RuntimeException('Llena Eco, modelo, número de serie y matrícula.');
            if (!in_array($actividad, ['en ruta','sin uso','mantenimiento'], true)) throw new RuntimeException('Actividad inválida.');
            if ($actividad === 'en ruta' && ($nombre==='' || $tel==='' || $fechaUso==='')) throw new RuntimeException('Si la camioneta está en ruta, el conductor, teléfono y fecha de uso son obligatorios.');
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO camionetas (eco,modelo,no_serie,matricula,actividad,fecha_ultimo_mantenimiento,kilometraje_actual,tanque_gasolina) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([$eco,$modelo,$serie,$matricula,$actividad,$mant,$km,$tanque]);
            $truckId = (int)$pdo->lastInsertId();
            $stmt = $pdo->prepare("INSERT INTO camioneta_indicadores_diarios (camioneta_id,fecha,kilometraje,tanque_gasolina) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE kilometraje=VALUES(kilometraje), tanque_gasolina=VALUES(tanque_gasolina)");
            $stmt->execute([$truckId,$fechaIndicadores,$km,$tanque]);
            if ($nombre !== '') {
                $stmt = $pdo->prepare("INSERT INTO camioneta_conductores (camioneta_id,nombre,telefono,correo,ruta,fecha_inicio,activo) VALUES (?,?,?,?,?,?,1)");
                $stmt->execute([$truckId,$nombre,$tel ?: null,$correo ?: null,$ruta ?: null,$fechaUso ?: date('Y-m-d')]);
            }
            $pdo->commit();
            redirect_self();
        }
        if ($action === 'update_plates') {
            $id=(int)post('id'); $mat=post('matricula'); if ($mat==='') throw new RuntimeException('La matrícula no puede ir vacía.');
            $pdo->prepare("UPDATE camionetas SET matricula=? WHERE id=?")->execute([$mat,$id]); redirect_self();
        }
        if ($action === 'update_serial') {
            $id = (int)post('id');
            $serie = post('no_serie');

        if ($id <= 0) {
        throw new RuntimeException('Camioneta inválida.');
        }

        if ($serie === '') {
        throw new RuntimeException('El número de serie no puede ir vacío.');
        }

        $pdo->prepare("UPDATE camionetas SET no_serie=? WHERE id=?")->execute([$serie, $id]);

        redirect_self();
        }
        if ($action === 'update_indicators') {
            $id = (int)post('id');
            $fechaIndicadores = post('fecha_indicadores') ?: date('Y-m-d');
            $km = max(0, (int)post('kilometraje_actual', '0'));
            $tanque = min(100, max(0, (int)post('tanque_gasolina', '0')));
            $depositado = post('gasolina_depositada') !== '' ? (float)post('gasolina_depositada') : 0;

            $pdo->prepare("UPDATE camionetas SET kilometraje_actual=?, tanque_gasolina=? WHERE id=?")
                ->execute([$km, $tanque, $id]);

            $pdo->prepare("INSERT INTO camioneta_indicadores_diarios (camioneta_id,fecha,kilometraje,tanque_gasolina,gasolina_depositada) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE kilometraje=VALUES(kilometraje), tanque_gasolina=VALUES(tanque_gasolina), gasolina_depositada=VALUES(gasolina_depositada)")
                ->execute([$id, $fechaIndicadores, $km, $tanque, $depositado]);

            redirect_self();
        }

        if ($action === 'update_arrival') {
            $id = (int)post('id');
            $fechaIndicadores = post('fecha_indicadores') ?: date('Y-m-d');
            $kmLlegada = post('kilometraje_llegada') !== '' ? max(0, (int)post('kilometraje_llegada')) : null;
            $gasLlegada = post('tanque_llegada_cedis') !== '' ? min(100, max(0, (int)post('tanque_llegada_cedis'))) : null;

            if ($id <= 0) {
                throw new RuntimeException('Camioneta inválida.');
            }

            $stmtActual = $pdo->prepare("SELECT kilometraje_actual, tanque_gasolina FROM camionetas WHERE id=? LIMIT 1");
            $stmtActual->execute([$id]);
            $actual = $stmtActual->fetch() ?: ['kilometraje_actual' => 0, 'tanque_gasolina' => 0];
            $kmSalida = (int)($actual['kilometraje_actual'] ?? 0);
            $gasSalida = (int)($actual['tanque_gasolina'] ?? 0);

            $pdo->prepare("INSERT INTO camioneta_indicadores_diarios (camioneta_id,fecha,kilometraje,tanque_gasolina,kilometraje_llegada,tanque_llegada_cedis) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE kilometraje_llegada=VALUES(kilometraje_llegada), tanque_llegada_cedis=VALUES(tanque_llegada_cedis)")
                ->execute([$id, $fechaIndicadores, $kmSalida, $gasSalida, $kmLlegada, $gasLlegada]);

            if ($kmLlegada !== null) {
                $pdo->prepare("UPDATE camionetas SET kilometraje_actual=? WHERE id=?")
                    ->execute([$kmLlegada, $id]);
            }

            redirect_self();
        }


        if ($action === 'link_route_eco') {
            if (!$canUpdateDriver) {
                throw new RuntimeException('No autorizado. Solo supervisor, administrativo, administrador, gerente o analista puede vincular rutas con ECO.');
            }

            $ruta = post('ruta_vincular');
            $ecoDestino = post('eco_destino');
            $fechaCambio = post('fecha_cambio') ?: date('Y-m-d');

            if ($ruta === '') {
                throw new RuntimeException('Selecciona la ruta que quieres mover.');
            }

            if ($ecoDestino === '' || !ctype_digit($ecoDestino)) {
                throw new RuntimeException('Selecciona la ECO que tomará la ruta.');
            }

            $stmtEcoDestino = $pdo->prepare("SELECT id, actividad FROM camionetas WHERE eco=? LIMIT 1");
            $stmtEcoDestino->execute([$ecoDestino]);
            $camionetaDestino = $stmtEcoDestino->fetch();

            if (!$camionetaDestino) {
                throw new RuntimeException('La ECO seleccionada no existe.');
            }

            $idDestino = (int)$camionetaDestino['id'];

            // Busca el conductor activo que actualmente trae la ruta seleccionada.
            // Ese conductor es el que se moverá a la ECO destino.
            $stmtOrigen = $pdo->prepare("
                SELECT
                    cc.id AS conductor_id,
                    cc.camioneta_id AS camioneta_origen_id,
                    cc.nombre,
                    cc.telefono,
                    cc.correo,
                    cc.ruta,
                    cc.fecha_inicio,
                    c.eco AS eco_origen
                FROM camioneta_conductores cc
                INNER JOIN camionetas c ON c.id = cc.camioneta_id
                WHERE cc.activo = 1
                  AND cc.ruta = ?
                ORDER BY cc.id DESC
                LIMIT 1
            ");
            $stmtOrigen->execute([$ruta]);
            $conductorOrigen = $stmtOrigen->fetch();

            if (!$conductorOrigen) {
                throw new RuntimeException('No se encontró un conductor activo vinculado a esa ruta. Primero asigna esa ruta a una ECO y después podrás moverla.');
            }

            $idOrigen = (int)$conductorOrigen['camioneta_origen_id'];
            $idConductorOrigen = (int)$conductorOrigen['conductor_id'];

            $pdo->beginTransaction();

            if ($idOrigen === $idDestino) {
                // Si la ruta ya está en esa misma ECO, solo asegura que siga activa y en ruta.
                $pdo->prepare("UPDATE camioneta_conductores SET ruta=? WHERE id=?")
                    ->execute([$ruta, $idConductorOrigen]);

                $pdo->prepare("UPDATE camionetas SET actividad='en ruta' WHERE id=?")
                    ->execute([$idDestino]);
            } else {
                // 1) Cierra cualquier conductor activo que tenga la ECO destino.
                // Esto evita que una camioneta tenga dos conductores activos al recibir la ruta.
                $pdo->prepare("UPDATE camioneta_conductores SET activo=0, fecha_fin=DATE_SUB(?, INTERVAL 1 DAY) WHERE camioneta_id=? AND activo=1")
                    ->execute([$fechaCambio, $idDestino]);

                // 2) Cierra el registro actual del conductor en la ECO origen.
                $pdo->prepare("UPDATE camioneta_conductores SET activo=0, fecha_fin=DATE_SUB(?, INTERVAL 1 DAY) WHERE id=?")
                    ->execute([$fechaCambio, $idConductorOrigen]);

                // 3) Crea el mismo conductor en la ECO destino con la misma ruta.
                $pdo->prepare("INSERT INTO camioneta_conductores (camioneta_id,nombre,telefono,correo,ruta,fecha_inicio,activo) VALUES (?,?,?,?,?,?,1)")
                    ->execute([
                        $idDestino,
                        $conductorOrigen['nombre'],
                        $conductorOrigen['telefono'] ?: null,
                        $conductorOrigen['correo'] ?: null,
                        $ruta,
                        $fechaCambio
                    ]);

                // 4) La ECO anterior queda sin uso y la ECO destino queda en ruta.
                $pdo->prepare("UPDATE camionetas SET actividad='sin uso' WHERE id=?")
                    ->execute([$idOrigen]);

                $pdo->prepare("UPDATE camionetas SET actividad='en ruta' WHERE id=?")
                    ->execute([$idDestino]);
            }

            $pdo->commit();
            redirect_self();
        }
        if ($action === 'update_driver_route') {
            if (!$canUpdateDriver) {
                throw new RuntimeException('No autorizado. Solo supervisor, administrativo, administrador, gerente o analista puede actualizar la ruta.');
            }

            $id = (int)post('id');
            $ruta = post('ruta_conductor');

            if ($id <= 0) {
                throw new RuntimeException('Camioneta inválida.');
            }

            $stmt = $pdo->prepare("UPDATE camioneta_conductores SET ruta=? WHERE camioneta_id=? AND activo=1");
            $stmt->execute([$ruta !== '' ? $ruta : null, $id]);

            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('No hay conductor activo para actualizar la ruta. Primero asigna un conductor.');
            }

            redirect_self();
        }
        if ($action === 'update_driver') {
            if (!$canUpdateDriver) {
                throw new RuntimeException('No autorizado. Solo supervisor, administrativo, administrador, gerente o analista puede actualizar el conductor.');
            }
            $id=(int)post('id'); $act=post('actividad','en ruta'); $nombre=post('nombre_conductor'); $tel=post('telefono_conductor'); $correo=post('correo_conductor'); $ruta=post('ruta_conductor'); $fecha=post('fecha_uso') ?: date('Y-m-d');
            if ($act === 'en ruta' && ($nombre==='' || $tel==='')) throw new RuntimeException('Para ponerla en ruta, conductor y teléfono son obligatorios.');
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE camioneta_conductores SET activo=0, fecha_fin=DATE_SUB(?, INTERVAL 1 DAY) WHERE camioneta_id=? AND activo=1")->execute([$fecha,$id]);
            if ($nombre !== '') $pdo->prepare("INSERT INTO camioneta_conductores (camioneta_id,nombre,telefono,correo,ruta,fecha_inicio,activo) VALUES (?,?,?,?,?,?,1)")->execute([$id,$nombre,$tel ?: null,$correo ?: null,$ruta ?: null,$fecha]);
            $pdo->prepare("UPDATE camionetas SET actividad=? WHERE id=?")->execute([$act,$id]);
            $pdo->commit(); redirect_self();
        }
        if ($action === 'update_maintenance') {
            $id=(int)post('id'); $fecha=post('fecha_mantenimiento') ?: date('Y-m-d'); $km=post('kilometraje') !== '' ? (int)post('kilometraje') : null; $notas=post('notas');
            if ($km === null) {
                $stmtKmActual = $pdo->prepare("SELECT kilometraje_actual FROM camionetas WHERE id=?");
                $stmtKmActual->execute([$id]);
                $km = (int)$stmtKmActual->fetchColumn();
            }
            $pdo->prepare("INSERT INTO camioneta_mantenimientos (camioneta_id,fecha_mantenimiento,kilometraje,notas) VALUES (?,?,?,?)")->execute([$id,$fecha,$km,$notas ?: null]);
            $pdo->prepare("UPDATE camionetas SET fecha_ultimo_mantenimiento=?, actividad='mantenimiento' WHERE id=?")->execute([$fecha,$id]);
            redirect_self();
        }
        if ($action === 'skip_maintenance_alert') {
            $id = (int)post('id');
            if ($id <= 0) {
                throw new RuntimeException('Camioneta inválida.');
            }

            $stmtKmActual = $pdo->prepare("SELECT kilometraje_actual FROM camionetas WHERE id=? LIMIT 1");
            $stmtKmActual->execute([$id]);
            $kmActual = $stmtKmActual->fetchColumn();

            if ($kmActual === false || $kmActual === null) {
                throw new RuntimeException('No se encontró kilometraje actual para esta camioneta.');
            }

            $pdo->prepare("INSERT INTO camioneta_mantenimientos (camioneta_id,fecha_mantenimiento,kilometraje,notas) VALUES (?,?,?,?)")
                ->execute([$id, date('Y-m-d'), (int)$kmActual, 'Aún no le toca mantenimiento. Se toma el kilometraje actual como nueva base para quitar la notificación.']);

            redirect_self();
        }
        if ($action === 'delete_truck') {
            if (!$canDeleteTruck) {
                throw new RuntimeException('No autorizado. Solo supervisor, administrativo, administrador, gerente o analista puede eliminar camionetas.');
            }
            $id = (int)post('id');
            if ($id <= 0) {
                throw new RuntimeException('Camioneta inválida.');
            }
            // Las tablas hijas se eliminan solas por ON DELETE CASCADE.
            $pdo->prepare("DELETE FROM camionetas WHERE id=?")->execute([$id]);
            redirect_self();
        }
        if ($action === 'add_purchase') {
            $titulo = post('titulo');
            $material = post('material');
            $cantidad = post('cantidad');
            $proveedor = post('proveedor');
            $costo = post('costo') !== '' ? (float)post('costo') : null;
            $estatus = post('estatus', 'orden');
            $notas = post('notas');
            if ($titulo === '') throw new RuntimeException('Escribe el nombre o motivo de la compra.');
            if ($material === '') throw new RuntimeException('Selecciona el material que se va a comprar.');
            if (!in_array($estatus, ['orden','pago','entrega','entregado'], true)) $estatus = 'orden';
            $pdo->prepare("INSERT INTO camioneta_compras (titulo,material,cantidad,proveedor,costo,estatus,notas) VALUES (?,?,?,?,?,?,?)")
                ->execute([$titulo,$material,$cantidad ?: null,$proveedor ?: null,$costo,$estatus,$notas ?: null]);
            redirect_self();
        }
        if ($action === 'update_purchase_status') {
            $id = (int)post('id');
            $estatus = post('estatus', 'orden');
            if ($id <= 0) throw new RuntimeException('Compra inválida.');
            if (!in_array($estatus, ['orden','pago','entrega','entregado'], true)) throw new RuntimeException('Estado de compra inválido.');
            $pdo->prepare("UPDATE camioneta_compras SET estatus=? WHERE id=?")->execute([$estatus, $id]);
            redirect_self();
        }
        if ($action === 'update_purchase') {
            $id = (int)post('id');
            $titulo = post('titulo');
            $material = post('material');
            $cantidad = post('cantidad');
            $proveedor = post('proveedor');
            $costo = post('costo') !== '' ? (float)post('costo') : null;
            $notas = post('notas');
            if ($id <= 0) throw new RuntimeException('Compra inválida.');
            if ($titulo === '') throw new RuntimeException('Escribe el nombre o motivo de la compra.');
            if ($material === '') throw new RuntimeException('Selecciona el material que se va a comprar.');
            $pdo->prepare("UPDATE camioneta_compras SET titulo=?, material=?, cantidad=?, proveedor=?, costo=?, notas=? WHERE id=?")
                ->execute([$titulo,$material,$cantidad ?: null,$proveedor ?: null,$costo,$notas ?: null,$id]);
            redirect_self();
        }
        if ($action === 'delete_purchase') {
            $id = (int)post('id');
            if ($id <= 0) throw new RuntimeException('Compra inválida.');
            $pdo->prepare("DELETE FROM camioneta_compras WHERE id=?")->execute([$id]);
            redirect_self();
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = str_contains($e->getMessage(), 'Duplicate') ? 'No se puede repetir Eco, matrícula o número de serie.' : $e->getMessage();
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        $errors[] = $e->getMessage();
    }
}

$vendors = [];
try {
    $vendors = $pdo->query("SELECT DISTINCT Vendedor FROM TClientesA WHERE Vendedor IS NOT NULL AND Vendedor<>'' ORDER BY Vendedor LIMIT 300")->fetchAll(PDO::FETCH_COLUMN);
} catch(Throwable $e) {}
$quitarVendedores = ['Cesar','Daniel','David','Gamaniel','Gerardo','Gerardo R11 Martes','Jacinto Guadalupe','Jonathan Arthuro','Jose Gilberto','Lorenzo','LUIS ANTONIO','Marco Antonio'];
$vendors = array_values(array_filter($vendors, fn($v) => !in_array(trim((string)$v), $quitarVendedores, true)));
if (!in_array('Jose Armando', $vendors, true)) $vendors[] = 'Jose Armando';
if (!in_array('Brayan', $vendors, true)) $vendors[] = 'Brayan';
sort($vendors, SORT_NATURAL | SORT_FLAG_CASE);

/* =========================================================
   FILTROS
   - graf_* solo afecta las gráficas superiores.
   - card_* solo afecta las tarjetas de camionetas.
========================================================= */
$grafFecha = trim((string)($_GET['graf_fecha'] ?? $_GET['fecha_indicadores'] ?? date('Y-m-d')));
$grafRuta  = trim((string)($_GET['graf_ruta'] ?? ''));

$cardEco   = trim((string)($_GET['card_eco'] ?? ''));
$cardRuta  = trim((string)($_GET['card_ruta'] ?? ''));

$rutasFiltro = [];
for ($r = 1; $r <= 14; $r++) {
    $rutasFiltro[] = 'Ruta ' . $r;
}

/* =========================================================
   CONSULTA PRINCIPAL
   Esta consulta NO se filtra por fecha/ruta de gráficas.
   Sirve para estadísticas generales y tarjetas de camionetas.
========================================================= */
$trucks = $pdo->query("
    SELECT 
        c.*,
        cc.nombre AS conductor,
        cc.telefono,
        cc.correo,
        cc.ruta,
        cc.fecha_inicio,
        COALESCE(ult.gasolina_depositada, 0) AS gasolina_depositada,
        ult.kilometraje_llegada,
        ult.tanque_llegada_cedis
    FROM camionetas c
    LEFT JOIN camioneta_conductores cc 
        ON cc.camioneta_id = c.id 
        AND cc.activo = 1
    LEFT JOIN (
        SELECT i1.*
        FROM camioneta_indicadores_diarios i1
        INNER JOIN (
            SELECT camioneta_id, MAX(fecha) AS fecha_max
            FROM camioneta_indicadores_diarios
            GROUP BY camioneta_id
        ) i2 
            ON i2.camioneta_id = i1.camioneta_id 
            AND i2.fecha_max = i1.fecha
    ) ult 
        ON ult.camioneta_id = c.id
    ORDER BY FIELD(c.actividad,'en ruta','sin uso','mantenimiento'), c.eco
")->fetchAll();

/* =========================================================
   CONSULTA PARA GRÁFICAS
   Esta consulta SÍ se filtra por fecha y ruta.
   No afecta las tarjetas de camionetas.
========================================================= */
$whereGraf = [];
$paramsGraf = [
    ':graf_fecha' => $grafFecha,
];

if ($grafRuta !== '') {
    $whereGraf[] = 'cc.ruta = :graf_ruta';
    $paramsGraf[':graf_ruta'] = $grafRuta;
}

$whereGrafSql = $whereGraf ? 'WHERE ' . implode(' AND ', $whereGraf) : '';

$sqlGraf = "
    SELECT 
        c.*,
        cc.nombre AS conductor,
        cc.telefono,
        cc.correo,
        cc.ruta,
        cc.fecha_inicio,
        COALESCE(ind.kilometraje, c.kilometraje_actual) AS kilometraje_actual,
        COALESCE(ind.tanque_gasolina, c.tanque_gasolina) AS tanque_gasolina,
        COALESCE(ind.gasolina_depositada, 0) AS gasolina_depositada,
        ind.kilometraje_llegada,
        ind.tanque_llegada_cedis
    FROM camionetas c
    LEFT JOIN camioneta_conductores cc
        ON cc.camioneta_id = c.id
        AND cc.activo = 1
    LEFT JOIN camioneta_indicadores_diarios ind
        ON ind.camioneta_id = c.id
        AND ind.fecha = :graf_fecha
    $whereGrafSql
    ORDER BY FIELD(c.actividad,'en ruta','sin uso','mantenimiento'), c.eco
";

$stmtGraf = $pdo->prepare($sqlGraf);
$stmtGraf->execute($paramsGraf);
$trucksGraf = $stmtGraf->fetchAll();

/* =========================================================
   FILTRO DE TARJETAS DE CAMIONETAS
   Solo afecta camionetas activas/inactivas.
   Usa la ruta actual registrada del conductor activo.
========================================================= */
$trucksCards = $trucks;

if ($cardEco !== '') {
    $trucksCards = array_values(array_filter(
        $trucksCards,
        fn($t) => (string)$t['eco'] === (string)$cardEco
    ));
}

if ($cardRuta !== '') {
    $trucksCards = array_values(array_filter(
        $trucksCards,
        fn($t) => (string)($t['ruta'] ?? '') === (string)$cardRuta
    ));
}

$active = array_values(array_filter($trucksCards, fn($t)=>$t['actividad']==='en ruta'));
$inactive = array_values(array_filter($trucksCards, fn($t)=>$t['actividad']!=='en ruta'));

/* Estadísticas generales: no se filtran por tarjetas ni gráficas */
$stats = [
    'total' => count($trucks),
    'activas' => count(array_filter($trucks, fn($t)=>$t['actividad']==='en ruta')),
    'mantenimiento' => count(array_filter($trucks, fn($t)=>$t['actividad']==='mantenimiento')),
    'inactivas' => count(array_filter($trucks, fn($t)=>$t['actividad']==='sin uso')),
];

/* =========================================================
   SALIDAS PENDIENTES DEL DÍA
   Solo revisa camionetas activas/en ruta.
========================================================= */
$fechaSalidaActual = date('Y-m-d');
$salidasPendientes = [];
try {
    $stmtSalidas = $pdo->prepare("
        SELECT
            c.id,
            c.eco,
            cc.nombre AS conductor,
            cc.ruta
        FROM camionetas c
        LEFT JOIN camioneta_conductores cc
            ON cc.camioneta_id = c.id
            AND cc.activo = 1
        LEFT JOIN camioneta_indicadores_diarios ind
            ON ind.camioneta_id = c.id
            AND ind.fecha = ?
        WHERE c.actividad = 'en ruta'
          AND ind.id IS NULL
        ORDER BY c.eco
    "
    );
    $stmtSalidas->execute([$fechaSalidaActual]);
    $salidasPendientes = $stmtSalidas->fetchAll();
} catch (Throwable $e) {
    $salidasPendientes = [];
}

/* Gráficas: usan la consulta filtrada por fecha/ruta */
$kmChart = $trucksGraf;
$gasChart = $trucksGraf;

$maxKmValue = 1;
foreach ($kmChart as $truckMetric) {
    $maxKmValue = max($maxKmValue, (float)($truckMetric['kilometraje_actual'] ?? 0));
}

$maintCards = [];
foreach ($trucksGraf as $truckMetric) {
    $statusMetric = mantenimiento_status($pdo, $truckMetric);
    $restanteMetric = max(0, (float)$statusMetric['proximo'] - (float)($truckMetric['kilometraje_actual'] ?? 0));
    $avanceMetric = $statusMetric['intervalo'] > 0 ? min(100, (int)round(((float)$statusMetric['recorridos'] / (float)$statusMetric['intervalo']) * 100)) : 0;
    $maintCards[] = [
        'truck' => $truckMetric,
        'status' => $statusMetric,
        'restante' => $restanteMetric,
        'avance' => $avanceMetric,
    ];
}
usort($maintCards, fn($a, $b) => $a['restante'] <=> $b['restante']);

$materialesAfinacion = [
    'Aceite de motor', 'Filtro de aceite', 'Filtro de aire', 'Filtro de gasolina', 'Filtro de cabina',
    'Bujías', 'Anticongelante / refrigerante', 'Líquido de frenos', 'Aceite de transmisión',
    'Limpiador de inyectores', 'Limpiador de cuerpo de aceleración', 'Balatas', 'Discos de freno',
    'Bandas', 'Mangueras', 'Batería', 'Focos', 'Limpiaparabrisas', 'Grasa / lubricante',
    'Empaque', 'Tornillería', 'Otro / escribir en notas de mantenimiento'
];
$compras = [];
try {
    $compras = $pdo->query("SELECT * FROM camioneta_compras ORDER BY FIELD(estatus,'orden','pago','entrega','entregado'), fecha_creacion DESC LIMIT 100")->fetchAll();
} catch (Throwable $e) { $compras = []; }

$comprasEnProceso = array_values(array_filter($compras, fn($c) => ($c['estatus'] ?? '') !== 'entregado'));
$etiquetasCompra = [
    'orden' => 'Orden de compra',
    'pago' => 'Espera de pago',
    'entrega' => 'Espera de entrega',
];

$usuarioNav = trim((string)($_SESSION['nombre'] ?? 'Usuario'));
$avatarNav = trim((string)($_SESSION['avatar_url'] ?? ''));
if ($avatarNav === '') {
    $avatarNav = 'img/users/default.png';
}

?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Control de Unidades</title>
<style>
/* =========================================================
   VARIABLES GENERALES / COLORES DEL SISTEMA
========================================================= */
:root {
    --bg: #dfe8f4;
    --panel: #eef3fa;
    --card: #f7faff;
    --ink: #0b162e;
    --muted: #65738a;
    --nav: #061737;
    --blue: #2f64b5;
    --orange: #ff9a3d;
    --line: rgba(11,22,46,.12);
    --green: #22a363;
    --red: #e53e3e;
}

/* =========================================================
   RESET BÁSICO / BODY
========================================================= */
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Inter, system-ui, Segoe UI, Arial, sans-serif;
    background: linear-gradient(135deg, #eef3fa, #cfdbea);
    color: var(--ink);
}

.wrap {
    max-width: 1780px;
    margin: 0 auto;
    padding: 26px 42px 60px;
}

/* =========================================================
   NAVBAR / MENÚ SUPERIOR
   En PC aparece arriba. En teléfono se mueve abajo.
========================================================= */
.nav {
    display: flex;
    align-items: center;
    gap: 28px;
    background: var(--nav);
    border-radius: 26px;
    padding: 12px 24px;
    min-height: 72px;
    color: white;
    box-shadow: 0 18px 42px rgba(2,10,30,.24);
    position: sticky;
    top: 10px;
    z-index: 5;
}

.logo {
    width: 150px;
    height: 52px;
    object-fit: contain;
    object-position: center;
    filter: drop-shadow(0 4px 8px rgba(0,0,0,.18));
}

.nav a {
    color: #dce7ff;
    text-decoration: none;
    font-weight: 800;
    font-size: 16px;
    padding: 12px 18px;
    border-radius: 12px;
    transition: .18s ease;
}

.nav a:hover,
.nav a.active {
    color: #fff;
    background: rgba(255,255,255,.13);
}

.hello {
    margin-left: auto;
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 900;
    font-size: 17px;
    color: #fff;
    white-space: nowrap;
}

.hello .greet-muted {
    color: #c9d7f4;
    font-weight: 800;
}

.nav-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid rgba(255,255,255,.75);
    box-shadow:
        0 0 0 4px rgba(47,100,181,.45),
        0 8px 16px rgba(0,0,0,.22);
    background: white;
}

/* =========================================================
   ENCABEZADO / TÍTULO Y FECHA
========================================================= */
.hero {
    display: flex;
    justify-content: space-between;
    align-items: end;
    margin: 45px 10px 25px;
}

.hero h1 {
    font-size: 46px;
    margin: 0;
    font-weight: 950;
}

.date {
    text-align: right;
    font-weight: 900;
    color: var(--muted);
    font-size: 20px;
}

/* =========================================================
   GRID SUPERIOR DEL DASHBOARD
========================================================= */
.grid {
    display: grid;
    grid-template-columns: 1.05fr 1.4fr 1.35fr 1.15fr;
    gap: 20px;
    margin-bottom: 36px;
    align-items: start;
}

/* Tarjetas generales */
.stat,
.truck,
.box,
.modal-card {
    background: rgba(247,250,255,.74);
    border: 1px solid rgba(255,255,255,.96);
    border-radius: 20px;
    box-shadow:
        inset 0 1px rgba(255,255,255,.88),
        0 14px 38px rgba(20,40,80,.08);
}

/* Tarjetas superiores */
.stat {
    padding: 18px 20px;
    display: flex;
    flex-direction: column;
    gap: 16px;
    min-height: 430px;
    overflow: hidden;
}

.stat h3 {
    margin: 0;
    font-size: 19px;
    line-height: 1.2;
}

.stat-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
}

.stat-header .eyebrow {
    display: block;
    font-size: 12px;
    font-weight: 900;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: .04em;
    margin-bottom: 6px;
}

.metric-badge {
    padding: 8px 12px;
    border-radius: 999px;
    background: rgba(47,100,181,.1);
    color: var(--blue);
    font-size: 12px;
    font-weight: 900;
    white-space: nowrap;
}

/* =========================================================
   FILTROS ESTÉTICOS
   Filtros de gráficas y filtros de tarjetas.
   Se mantienen compactos para no verse toscos.
========================================================= */
.filter-card {
    margin: 0 0 24px;
    padding: 14px 16px;
    border-radius: 22px;
    background: transparent;
}

.filter-card.compact {
   display: flex;
    align-items: center;
    justify-content: flex-end;
    margin: 26px 4px 18px;
    width: 100%;
}

.filter-form {
    display: grid;
    grid-template-columns: 190px 190px auto;
    gap: 10px;
    align-items: end;
}

.filter-card .field span,
.cards-filter .field span {
    font-size: 11px;
    color: var(--muted);
    font-weight: 950;
    margin-bottom: 5px;
}

.filter-card input,
.filter-card select,
.cards-filter select {
    height: 42px;
    padding: 9px 12px;
    border-radius: 14px;
    background: rgba(255,255,255,.82);
    border: 1px solid rgba(11,22,46,.10);
    font-size: 14px;
}

.filter-card .btn,
.cards-filter .btn,
.cards-filter a.btn {
    height: 42px;
    padding: 10px 15px;
    border-radius: 14px;
    font-size: 13px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    white-space: nowrap;
}

/* Acciones al lado del título Camionetas Activas */
.section-actions {
    display: flex;
    align-items: end;
    justify-content: flex-end;
    gap: 12px;
    flex-wrap: wrap;
}

.cards-filter {
    display: flex;
    align-items: end;
    gap: 10px;
    flex-wrap: wrap;
    padding: 10px 12px;
    border-radius: 18px;
    background: transparent;
}

.cards-filter .field {
    width: 145px;
}

/* =========================================================
   VINCULAR RUTAS CON ECO
   Permite cambiar qué camioneta trae cada ruta sin editar conductor.
========================================================= */
.route-link-card {
    margin: 0 4px 22px auto;
    padding: 16px;
    border-radius: 18px;
    background: transparent;
    border: 0;
    box-shadow: none;
    max-width: 980px;
}

.route-link-card h3 {
    margin: 0 0 6px;
    font-size: 20px;
}

.route-link-card .hint {
    margin: 0 0 14px;
}

.route-link-form {
    display: flex;
    align-items: end;
    justify-content: flex-end;
    gap: 10px;
    flex-wrap: wrap;
}

.route-link-form .field {
    width: 190px;
}

.route-link-form .field.big {
    width: 280px;
}

.route-link-form select,
.route-link-form input {
    height: 42px;
    padding: 9px 12px;
    border-radius: 14px;
    background: rgba(255,255,255,.82);
    border: 1px solid rgba(11,22,46,.10);
    font-size: 14px;
}

.route-link-form .btn {
    height: 42px;
    padding: 10px 15px;
    border-radius: 14px;
    font-size: 13px;
}

/* =========================================================
   RESUMEN DE UNIDADES
========================================================= */
.summary-section {
    display: grid;
    gap: 16px;
}

.summary-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

.summary-tile {
    background: rgba(255,255,255,.72);
    border: 1px solid rgba(11,22,46,.08);
    border-radius: 14px;
    padding: 14px 16px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.summary-tile span {
    display: block;
    color: var(--muted);
    font-size: 13px;
    font-weight: 900;
}

.summary-tile strong {
    font-size: 20px;
    line-height: 1;
    font-weight: 950;
    color: var(--ink);
}

/* =========================================================
   SCROLL PERSONALIZADO
========================================================= */
.scroll-area {
    overflow: auto;
    padding-right: 6px;
    scrollbar-color: #7c3aed rgba(117,85,204,.14);
    scrollbar-width: thin;
}

.scroll-area::-webkit-scrollbar {
    width: 10px;
    height: 10px;
}

.scroll-area::-webkit-scrollbar-track {
    background: rgba(117,85,204,.12);
    border-radius: 999px;
}

.scroll-area::-webkit-scrollbar-thumb {
    background: linear-gradient(180deg,#8b5cf6,#6d28d9);
    border-radius: 999px;
}

/* =========================================================
   GRÁFICA DE KILOMETRAJE
========================================================= */
.chart-card {
    justify-content: flex-start;
}

.chart-scroll {
    overflow-x: auto;
    overflow-y: hidden;
    padding-bottom: 8px;
}

.chart-scroll::-webkit-scrollbar {
    height: 10px;
}

.chart-scroll::-webkit-scrollbar-track {
    background: rgba(117,85,204,.12);
    border-radius: 999px;
}

.chart-scroll::-webkit-scrollbar-thumb {
    background: linear-gradient(90deg,#8b5cf6,#6d28d9);
    border-radius: 999px;
}

.chart {
    flex: 0 0 auto;
    display: flex;
    align-items: flex-end;
    gap: 14px;
    padding: 6px 4px 0;
    min-height: 150px;
    min-width: max-content;
}

.chart-col {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
}

.chart-value {
    font-size: 12px;
    font-weight: 900;
    color: var(--muted);
    text-align: center;
    line-height: 1.15;
    min-height: 28px;
}

.bar {
    width: 100%;
    max-width: 78px;
    background: #0d2555;
    border-radius: 12px 12px 4px 4px;
    box-shadow: inset 0 1px rgba(255,255,255,.22);
}

.bar.o {
    background: #ff9a3d;
}

.chart-label {
    font-size: 13px;
    font-weight: 900;
    color: var(--ink);
    text-align: center;
}

.chart-note {
    font-size: 12px;
    color: var(--muted);
    font-weight: 800;
}

/* =========================================================
   GASOLINA REGISTRADA
========================================================= */
.fuel-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 12px;
}

.fuel-scroll {
    max-height: 310px;
}

.fuel-card {
    background: rgba(255,255,255,.72);
    border: 1px solid rgba(11,22,46,.08);
    border-radius: 14px;
    padding: 12px 14px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.fuel-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
}

.fuel-top strong {
    font-size: 15px;
}

.fuel-top span {
    font-size: 18px;
    font-weight: 950;
    color: var(--orange);
}

.fuel-meta {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    flex-wrap: wrap;
    font-size: 12px;
    font-weight: 900;
    color: var(--muted);
}

/* =========================================================
   BARRAS DE PROGRESO
========================================================= */
.progress-track {
    width: 100%;
    height: 12px;
    background: #dbe4f4;
    border-radius: 999px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    border-radius: 999px;
    background: linear-gradient(90deg,#244a93,#3c72ca);
}

.fuel-fill {
    background: linear-gradient(90deg,#ffbd72,#ff9a3d);
}

/* =========================================================
   PRÓXIMOS MANTENIMIENTOS
========================================================= */
.maint-list {
    display: grid;
    gap: 14px;
}

.maint-scroll {
    max-height: 310px;
}

.maint-item {
    background: rgba(255,255,255,.72);
    border: 1px solid rgba(11,22,46,.08);
    border-radius: 14px;
    padding: 12px 14px;
    display: grid;
    gap: 8px;
}

.maint-line,
.maint-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
}

.maint-line strong {
    font-size: 15px;
}

.maint-line span,
.maint-meta span {
    font-size: 12px;
    font-weight: 900;
    color: var(--muted);
}

.maint-item.alerta .progress-fill {
    background: linear-gradient(90deg,#ff9a3d,#e53e3e);
}

/* =========================================================
   COMPRAS EN PROCESO MINI
========================================================= */
.summary-purchases {
    max-height: 280px;
}

.purchase-mini-list {
    display: grid;
    gap: 10px;
}

.purchase-mini {
    background: rgba(255,255,255,.72);
    border: 1px solid rgba(11,22,46,.08);
    border-radius: 14px;
    padding: 12px 14px;
    display: grid;
    gap: 6px;
}

.purchase-mini-top,
.purchase-mini-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
}

.purchase-mini-top strong {
    font-size: 14px;
}

.purchase-mini small,
.purchase-mini-meta span {
    font-size: 12px;
    font-weight: 800;
    color: var(--muted);
}

.purchase-mini .compra-tag {
    margin-top: 0;
    justify-self: start;
}

/* =========================================================
   ENCABEZADOS DE SECCIÓN
========================================================= */
.section-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin: 26px 4px 18px;
}

.section-head h2 {
    font-size: 30px;
    margin: 0;
}

/* =========================================================
   BOTONES
========================================================= */
.btn {
    border: 0;
    border-radius: 10px;
    background: var(--nav);
    color: white;
    padding: 12px 22px;
    font-weight: 900;
    cursor: pointer;
}

.btn.blue {
    background: var(--blue);
}

.btn.light {
    background: #fff;
    color: var(--nav);
    border: 1px solid var(--line);
}

.btn.danger {
    background: #dc2626;
    color: white;
}

.small {
    padding: 8px 12px;
    font-size: 13px;
}

/* =========================================================
   TARJETAS DE CAMIONETAS
========================================================= */
.cards {
    display: grid;
    grid-template-columns: repeat(3,1fr);
    gap: 18px;
}

.truck {
    display: grid;
    grid-template-columns: 150px minmax(0,1fr);
    align-items: center;
    gap: 20px;
    padding: 22px 22px 20px;
    min-height: 252px;
}

.truck img {
    width: 150px;
    height: 118px;
    object-fit: contain;
    align-self: center;
    justify-self: center;
    filter: drop-shadow(0 10px 14px rgba(11,22,46,.14));
}

.truck-body {
    display: flex;
    flex-direction: column;
    gap: 10px;
    min-width: 0;
}

.truck-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 10px;
}

.truck h3 {
    font-size: 24px;
    line-height: 1;
    margin: 0;
}

.truck-km {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 12px;
    align-items: center;
}

.truck-km strong {
    font-size: 17px;
    color: var(--ink);
}

.truck-km span {
    color: #36507a;
    font-weight: 900;
}

.truck-fuel-extra {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
}

.truck-fuel-box {
    background: rgba(255,255,255,.66);
    border: 1px solid rgba(11,22,46,.08);
    border-radius: 12px;
    padding: 8px 10px;
}

.truck-fuel-box span {
    display: block;
    font-size: 11px;
    color: var(--muted);
    font-weight: 900;
}

.truck-fuel-box strong {
    display: block;
    font-size: 14px;
    color: var(--ink);
    font-weight: 950;
}

.truck-info {
    display: grid;
    gap: 5px;
}

.truck p {
    margin: 0;
    color: #465872;
    font-weight: 700;
    line-height: 1.33;
}

.truck p b {
    color: var(--ink);
}

.truck .actions {
    margin-top: auto;
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

/* =========================================================
   ETIQUETAS DE ESTADO
========================================================= */
.tag {
    display: inline-block;
    border-radius: 999px;
    padding: 7px 12px;
    font-weight: 900;
    font-size: 13px;
    white-space: nowrap;
}

.tag.ruta {
    background: #d7f8e4;
    color: #147a45;
    border: 1px solid #90ddb2;
}

.tag.sin {
    background: #ffdede;
    color: #a41919;
    border: 1px solid #ff9a9a;
}

.tag.mant {
    background: #fff0c2;
    color: #8a6100;
    border: 1px solid #f3ca52;
}

/* =========================================================
   CAJAS / FORMULARIOS
========================================================= */
.box {
    padding: 18px;
    margin-top: 16px;
}

.hidden {
    display: none !important;
}

.add-title {
    display: flex;
    align-items: center;
    gap: 14px;
}

.add-title img {
    width: 74px;
    height: auto;
    object-fit: contain;
}

.maintenance-note {
    font-size: 13px;
    color: var(--muted);
    font-weight: 800;
    margin-top: 6px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(4,1fr);
    gap: 12px;
}

input,
select,
textarea {
    width: 100%;
    padding: 13px 14px;
    border-radius: 12px;
    border: 1px solid var(--line);
    background: #fff;
    color: var(--ink);
    font-weight: 800;
    font-size: 15px;
}

.field {
    display: block;
}

.field span {
    display: block;
    font-size: 12px;
    font-weight: 950;
    color: var(--muted);
    margin: 0 0 6px 2px;
}

textarea {
    min-height: 90px;
}

.full {
    grid-column: 1 / -1;
}

/* =========================================================
   MENSAJES / ALERTAS
========================================================= */
.hint {
    color: var(--muted);
    font-weight: 800;
}

.alert {
    background: #ffe4e4;
    color: #9a1111;
    border: 1px solid #ffa1a1;
    padding: 14px;
    border-radius: 14px;
    margin: 14px 0;
    font-weight: 900;
}

.maintenance-alert {
    background: #fff1d6;
    color: #8a4b00;
    border: 1px solid #ffc56b;
    padding: 10px 12px;
    border-radius: 12px;
    margin: 10px 0;
    font-weight: 950;
}

.maintenance-alert-row,
.daily-exit-alert-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}

.maintenance-alert-row form {
    margin: 0;
}

.daily-exit-alert {
    background: #eaf2ff;
    color: #123f82;
    border: 1px solid #9fc2ff;
    padding: 10px 12px;
    border-radius: 12px;
    margin: 10px 0;
    font-weight: 950;
}

.daily-exit-alert ul {
    margin: 8px 0 0 18px;
    padding: 0;
}

/* =========================================================
   FORMULARIO ELIMINAR CAMIONETA
========================================================= */
.delete-form {
    margin-top: 14px;
}

.delete-form .btn {
    width: 100%;
}

/* =========================================================
   MODALES
========================================================= */
.modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.56);
    z-index: 20;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.modal:target {
    display: flex;
}

.modal-card {
    background: #f8fafc;
    width: min(1250px,96vw);
    max-height: 90vh;
    overflow: auto;
}

.modal-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid var(--line);
    padding: 18px 26px;
}

.modal-body {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
    padding: 24px;
    align-items: start;
}

.modal-col {
    display: flex;
    flex-direction: column;
    gap: 18px;
    min-width: 0;
}

.close {
    font-size: 26px;
    text-decoration: none;
    color: var(--ink);
    font-weight: 950;
}

/* =========================================================
   MODAL: TÍTULOS Y ACTUALIZACIONES OCULTAS
========================================================= */
.box-title-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 8px;
}

.box-title-row h3 {
    margin: 0;
}

.tiny-note {
    color: var(--muted);
    font-size: 11px;
    font-weight: 900;
}

.update-panel {
    margin: 4px 0 10px;
}

.update-panel .update-link {
    display: inline-block;
    color: var(--blue);
    font-size: 12px;
    font-weight: 950;
    cursor: pointer;
    text-decoration: underline;
    text-underline-offset: 3px;
    user-select: none;
}

.update-panel .update-link::-webkit-details-marker {
    display: none;
}

.update-panel .update-link::marker {
    content: '';
}

.update-panel[open] .update-link {
    color: #174f9c;
}

.update-panel form {
    margin-top: 10px;
    padding: 12px;
    border: 1px solid rgba(11,22,46,.10);
    background: rgba(255,255,255,.58);
    border-radius: 14px;
}

.danger-panel .danger-link {
    color: #dc2626;
}

.update-form-grid {
    grid-template-columns: repeat(3,1fr);
}

/* =========================================================
   FORMULARIOS PEQUEÑOS DE ACTUALIZACIÓN
   Serie, placas y ruta dentro del modal
========================================================= */
.mini-update {
    display: grid;
    grid-template-columns: 1fr 140px;
    gap: 10px;
    align-items: end;
    margin: 10px 0 12px;
}

.mini-update input,
.mini-update select {
    height: 48px;
    padding: 10px 14px;
}

.mini-update .btn {
    height: 48px;
    padding: 0 14px;
    border-radius: 12px;
    font-size: 14px;
    line-height: 1.1;
}

.truck-detail-data p,
.driver-detail-data p {
    margin: 8px 0;
}

.detail-separator {
    border: 0;
    border-top: 1px solid rgba(11,22,46,.14);
    margin: 14px 0;
}

/* =========================================================
   DATOS DE SALIDA Y LLEGADA
========================================================= */
.trip-data-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-top: 12px;
}

.trip-data-box {
    background: rgba(255,255,255,.66);
    border: 1px solid rgba(11,22,46,.08);
    border-radius: 14px;
    padding: 12px 14px;
}

.trip-data-box h4 {
    margin: 0 0 10px;
    font-size: 16px;
}

.trip-data-box p {
    margin: 6px 0;
    color: #465872;
    font-weight: 800;
}

.trip-data-box p b {
    color: var(--ink);
}

.trip-data-box .update-panel form {
    display: grid;
    grid-template-columns: 1fr;
    gap: 10px;
    width: 100%;
    min-width: 0;
}

.trip-data-box .update-form-grid {
    grid-template-columns: 1fr;
}

.trip-data-box .field,
.trip-data-box input,
.trip-data-box button {
    min-width: 0;
}

.trip-data-box .btn.full {
    grid-column: 1 / -1;
}

/* =========================================================
   HISTORIALES
========================================================= */
.history {
    border-top: 1px solid var(--line);
    padding-top: 12px;
    margin-top: 12px;
}

.history-row {
    display: flex;
    justify-content: space-between;
    padding: 10px 0;
    border-bottom: 1px solid var(--line);
    font-weight: 800;
    color: #394960;
    gap: 14px;
}

/* =========================================================
   COMPRAS
========================================================= */
.compras-grid {
    display: grid;
    grid-template-columns: repeat(3,1fr);
    gap: 16px;
}

.compra-col {
    background: rgba(255,255,255,.42);
    border: 1px solid var(--line);
    border-radius: 16px;
    overflow: hidden;
}

.compra-col h3 {
    margin: 0;
    padding: 14px 18px;
    border-bottom: 1px solid var(--line);
    font-size: 18px;
}

.compra-card {
    margin: 14px;
    padding: 16px;
    border-radius: 14px;
    background: rgba(255,255,255,.72);
    border: 1px solid rgba(255,255,255,.9);
    font-weight: 800;
}

.compra-card h4 {
    margin: 0 0 8px;
    font-size: 18px;
}

.compra-meta {
    color: var(--muted);
    font-size: 14px;
    line-height: 1.5;
}

.compra-tag {
    display: inline-block;
    margin-top: 8px;
    border-radius: 999px;
    background: #e9f1ff;
    color: #0f4fa9;
    padding: 5px 10px;
    font-size: 12px;
    font-weight: 900;
}

.compra-actions {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 8px;
    margin-top: 12px;
}

.compra-actions select {
    padding: 9px 10px;
    font-size: 13px;
}

.compra-edit {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 10px;
}

.compra-edit .btn {
    padding: 8px 10px;
    font-size: 12px;
}

.historial-compras {
    margin-top: 16px;
}

.historial-compras h3 {
    margin: 0 0 12px;
    font-size: 20px;
}

.modal-add .modal-card {
    width: min(1500px,96vw);
}

/* =========================================================
   RESPONSIVE TABLET / LAPTOP CHICA
========================================================= */
@media(max-width:1200px) {
    .grid,
    .compras-grid {
        grid-template-columns: repeat(2,1fr);
    }

    .cards {
        grid-template-columns: repeat(2,1fr);
    }

    .form-grid {
        grid-template-columns: repeat(2,1fr);
    }
}

/* =========================================================
   RESPONSIVE TABLET CHICA
========================================================= */
@media(max-width:980px) {
    .nav {
        gap: 12px;
        padding: 10px 14px;
        overflow-x: auto;
    }

    .logo {
        width: 118px;
        height: 44px;
    }

    .nav a {
        font-size: 14px;
        padding: 10px 12px;
    }

    .hello {
        font-size: 14px;
    }

    .hello .greet-user {
        display: none;
    }

    .nav-avatar {
        width: 42px;
        height: 42px;
    }
}

/* =========================================================
   RESPONSIVE TELÉFONO
   Menú inferior tipo app
========================================================= */
@media(max-width:760px) {
    body {
        padding-bottom: 82px;
    }

    .wrap {
        padding: 16px;
        padding-bottom: 95px;
    }

    .nav {
        position: fixed;
        left: 0;
        right: 0;
        bottom: 0;
        top: auto;
        z-index: 999;
        width: 100%;
        min-height: 72px;
        border-radius: 22px 22px 0 0;
        padding: 10px 12px;
        justify-content: space-around;
        gap: 6px;
        overflow: visible;
    }

    .nav .logo,
    .nav .hello,
    .nav-avatar {
        display: none;
    }

    .nav a {
        flex: 1;
        text-align: center;
        font-size: 12px;
        padding: 10px 6px;
        border-radius: 16px;
        white-space: nowrap;
    }

    .nav a.active {
        background: rgba(255,255,255,.16);
        color: #fff;
    }

    .grid,
    .cards,
    .form-grid,
    .modal-body,
    .compras-grid,
    .summary-grid {
        grid-template-columns: 1fr;
    }

    .filter-form {
        grid-template-columns: 1fr;
    }

    .section-head {
        align-items: flex-start;
        flex-direction: column;
        gap: 12px;
    }

    .section-actions {
        width: 100%;
    }

    .cards-filter {
        width: 100%;
        display: grid;
        grid-template-columns: 1fr 1fr;
    }

    .cards-filter .field {
        width: 100%;
    }

    .cards-filter .btn,
    .cards-filter a.btn {
        width: 100%;
        text-align: center;
    }

    .truck {
        grid-template-columns: 1fr;
    }

    .truck img {
        width: 180px;
        height: auto;
        justify-self: center;
    }

    .hero {
        margin-top: 24px;
    }

    .chart {
        min-height: 130px;
    }

    .stat {
        min-height: auto;
        height: auto;
    }

    .modal-col {
        gap: 16px;
    }

    .update-form-grid {
        grid-template-columns: 1fr;
    }

    .mini-update {
        grid-template-columns: 1fr;
    }

    .mini-update .btn {
        width: 100%;
    }

    .trip-data-grid {
        grid-template-columns: 1fr;
    }
}
</style></head>

<body><div class="wrap">
<nav class="nav">
  <img class="logo" src="img/Lazo-San-Miguel.png" alt="San Miguel">
  <a href="Corte-Diario.php">Cortes</a>
  <a href="usuarios.php">Administracion</a>
  <a class="active" href="camionetas.php">Consultas</a>
  <a href="resultados-clientes.php">Rutas</a>
  <div class="hello">
    <span id="greetingText">Buenos días ☀️ | 24°C en Monterrey</span>
    <span class="greet-muted">, Hola</span>
    <span class="greet-user"><?=h($usuarioNav)?></span>
  </div>
  <img class="nav-avatar" src="<?=h($avatarNav)?>" alt="Perfil" onerror="this.src='img/users/default.png'">
</nav>
<section class="hero"><h1>Control de Unidades</h1><div class="date">Fecha Actual<br><?php
$dias = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
$meses = [1=>'enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
$hoy = new DateTime('now', new DateTimeZone('America/Monterrey'));
echo h(ucfirst($dias[(int)$hoy->format('w')]) . ', ' . $hoy->format('j') . ' de ' . $meses[(int)$hoy->format('n')] . ' de ' . $hoy->format('Y'));
?></div></section>
<?php foreach($errors as $e): ?><div class="alert"><?=h($e)?></div><?php endforeach; ?>
<?php $alertsMant = []; foreach($trucks as $tAlert){ $stAlert = mantenimiento_status($pdo, $tAlert); if($stAlert['alerta']) $alertsMant[] = [$tAlert, $stAlert]; } ?>
<?php foreach($alertsMant as [$ta,$sa]): ?>
    <div class="maintenance-alert maintenance-alert-row">
        <span>
            ⚠️ La camioneta <?=h($ta['eco'])?> ya ocupa mantenimiento.
            Modelo <?=h($ta['modelo'])?>, intervalo <?=h(number_format($sa['intervalo']))?> km,
            recorrido desde la base <?=h(number_format($sa['recorridos']))?> km.
        </span>
        <form method="post" onsubmit="return confirm('¿Quitar esta notificación de mantenimiento?');">
            <input type="hidden" name="action" value="skip_maintenance_alert">
            <input type="hidden" name="id" value="<?=h($ta['id'])?>">
            <button class="btn danger small" type="submit">Aún no le toca</button>
        </form>
    </div>
<?php endforeach; ?>

<?php if($salidasPendientes): ?>
    <div class="daily-exit-alert">
        <div class="daily-exit-alert-row">
            <span>⚠️ Falta registrar salida del día actual en estas camionetas activas:</span>
            <span><?=h(date('d/m/Y', strtotime($fechaSalidaActual)))?></span>
        </div>
        <ul>
            <?php foreach($salidasPendientes as $sp): ?>
                <li>
                    <a class="open-salida-link" href="#detalle<?=h($sp['id'])?>" data-salida-id="<?=h($sp['id'])?>">
                        ECO <?=h($sp['eco'])?><?=($sp['ruta'] ?? '') !== '' ? ' · ' . h($sp['ruta']) : ''?><?=($sp['conductor'] ?? '') !== '' ? ' · ' . h($sp['conductor']) : ''?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- =========================================================
     FILTROS DE GRÁFICAS
     Solo afectan indicadores superiores.
========================================================== -->
<section class="filter-card compact">
    <form method="get" class="filter-form">
        <input type="hidden" name="card_eco" value="<?=h($cardEco)?>">
        <input type="hidden" name="card_ruta" value="<?=h($cardRuta)?>">

        <label class="field">
            <span>Fecha</span>
            <input 
                type="date" 
                name="graf_fecha" 
                value="<?=h($grafFecha)?>"
                onchange="this.form.submit()"
            >
        </label>

        <label class="field">
            <span>Ruta gráficas</span>
            <select name="graf_ruta" onchange="this.form.submit()">
                <option value="">Todas</option>
                <?php foreach($rutasFiltro as $rutaOpt): ?>
                    <option value="<?=h($rutaOpt)?>" <?=$grafRuta===$rutaOpt?'selected':''?>>
                        <?=h($rutaOpt)?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>


        <a 
            class="btn light" 
            href="camionetas.php?card_eco=<?=urlencode($cardEco)?>&card_ruta=<?=urlencode($cardRuta)?>"
        >
            Limpiar
        </a>
    </form>
</section>

<section class="grid">
  <div class="stat stat-summary">
    <div class="stat-header">
      <div>
        <span class="eyebrow">Vista general</span>
        <h3>Resumen de Unidades</h3>
      </div>
      <div class="metric-badge"><?=h($stats['total'])?> unidades</div>
    </div>
    <div class="summary-section">
      <div class="summary-grid">
        <div class="summary-tile"><span>Total</span><strong><?=h($stats['total'])?></strong></div>
        <div class="summary-tile"><span>Activas</span><strong><?=h($stats['activas'])?></strong></div>
        <div class="summary-tile"><span>Mantenimiento</span><strong><?=h($stats['mantenimiento'])?></strong></div>
        <div class="summary-tile"><span>Sin uso</span><strong><?=h($stats['inactivas'])?></strong></div>
      </div>
      <div>
        <div class="stat-header" style="margin-bottom:8px">
          <div>
            <span class="eyebrow">Compras</span>
            <h3 style="font-size:18px">Compras en proceso</h3>
          </div>
          <div class="metric-badge"><?=h(count($comprasEnProceso))?> activas</div>
        </div>
        <div class="purchase-mini-list scroll-area summary-purchases">
          <?php if($comprasEnProceso): foreach($comprasEnProceso as $compra): ?>
            <div class="purchase-mini">
              <div class="purchase-mini-top">
                <strong><?=h($compra['titulo'])?></strong>
                <span class="compra-tag"><?=h($etiquetasCompra[$compra['estatus']] ?? ucfirst((string)$compra['estatus']))?></span>
              </div>
              <small>Material: <?=h($compra['material'] ?: 'No especificado')?></small>
              <div class="purchase-mini-meta">
                <span>Cant.: <?=h($compra['cantidad'] ?: '—')?></span>
                <span>$<?=h(number_format((float)($compra['costo'] ?? 0),2))?></span>
              </div>
            </div>
          <?php endforeach; else: ?>
            <p class="hint">No hay compras en proceso.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="stat chart-card">
    <div class="stat-header">
      <div>
        <span class="eyebrow">Indicadores</span>
        <h3>Km por camioneta</h3>
      </div>
      
    </div>
    <div class="chart-scroll"><div class="chart">
      <?php if($kmChart): foreach($kmChart as $t):
        $barHeight = max(18, min(128, ((float)($t['kilometraje_actual'] ?? 0) / $maxKmValue) * 128)); ?>
        <div class="chart-col">
          <div class="chart-value"><?=h(number_format((float)($t['kilometraje_actual'] ?? 0)))?> km</div>
          <div title="<?=h($t['eco'])?>" class="bar" style="height:<?=$barHeight?>px"></div>
          <div class="chart-label">ECO <?=h($t['eco'])?></div>
        </div>
      <?php endforeach; else: ?>
        <p class="hint">Sin datos de kilometraje.</p>
      <?php endif; ?></div></div>
    <div class="chart-note">Comparativo visual del kilometraje registrado por unidad.</div>
  </div>

  <div class="stat">
    <div class="stat-header">
      <div>
        <span class="eyebrow">Combustible</span>
        <h3>Gasolina registrada</h3>
      </div>
      <div class="metric-badge"><?=h(date('d/m/Y', strtotime($grafFecha)))?></div>
    </div>
    <div class="fuel-grid scroll-area fuel-scroll">
      <?php if($gasChart): foreach($gasChart as $t): ?>
        <div class="fuel-card">
          <div class="fuel-top">
            <strong>ECO <?=h($t['eco'])?></strong>
            <span><?=h((string)($t['tanque_gasolina'] ?? 0))?>%</span>
          </div>
          <div class="progress-track"><div class="progress-fill fuel-fill" style="width:<?=max(0,min(100,(int)($t['tanque_gasolina'] ?? 0)))?>%"></div></div>
          <div class="fuel-meta">
            <span>Depositado: $<?=h(number_format((float)($t['gasolina_depositada'] ?? 0),2))?></span>
            <span>Llegó CEDIS: <?=($t['tanque_llegada_cedis'] ?? '')!=='' ? h((string)$t['tanque_llegada_cedis']).'%' : '—'?></span>
          </div>
        </div>
      <?php endforeach; else: ?>
        <p class="hint">Sin datos de gasolina registrados.</p>
      <?php endif; ?>
    </div>
  </div>

  <div class="stat">
    <div class="stat-header">
      <div>
        <span class="eyebrow">Seguimiento</span>
        <h3>Próximos mantenimientos</h3>
      </div>
      <div class="metric-badge"><?=h($grafRuta ?: 'Todas las rutas')?></div>
    </div>
    <div class="maint-list scroll-area maint-scroll">
      <?php if($maintCards): foreach($maintCards as $item): $truckMaint=$item['truck']; $st=$item['status']; ?>
        <div class="maint-item <?=$st['alerta'] ? 'alerta' : ''?>">
          <div class="maint-line">
            <strong>ECO <?=h($truckMaint['eco'])?></strong>
            <span><?=h(number_format($item['restante']))?> km restantes</span>
          </div>
          <div class="progress-track"><div class="progress-fill" style="width:<?=$item['avance']?>%"></div></div>
          <div class="maint-meta">
            <span>Próx: <?=h(number_format($st['proximo']))?> km</span>
            <span>Cada <?=h(number_format($st['intervalo']))?> km</span>
          </div>
        </div>
      <?php endforeach; else: ?>
        <p class="hint">Sin mantenimientos calculados.</p>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- =========================================================
     FILTROS DE TARJETAS
     Solo afectan las tarjetas de camionetas activas/inactivas.
========================================================== -->
<div class="section-head">
    <h2>Camionetas Activas</h2>

    <div class="section-actions">
        <form method="get" class="cards-filter">
            <input type="hidden" name="graf_fecha" value="<?=h($grafFecha)?>">
            <input type="hidden" name="graf_ruta" value="<?=h($grafRuta)?>">

            <label class="field">
                <span>Ruta actual</span>
                <select name="card_ruta" onchange="this.form.submit()">
                    <option value="">Todas</option>
                    <?php foreach($rutasFiltro as $rutaOpt): ?>
                        <option value="<?=h($rutaOpt)?>" <?=$cardRuta===$rutaOpt?'selected':''?>>
                            <?=h($rutaOpt)?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>


            <a 
                class="btn light" 
                href="camionetas.php?graf_fecha=<?=urlencode($grafFecha)?>&graf_ruta=<?=urlencode($grafRuta)?>"
            >
                Limpiar
            </a>
        </form>

        <a class="btn light" href="#vincularRutaEco" style="text-decoration:none">
            Vincular ruta/ECO
        </a>

        <a class="btn" href="#nuevaCamioneta" style="text-decoration:none">
            + Agregar camioneta
        </a>
    </div>
</div>
<div class="cards"><?php foreach($active as $t): truck_card($t); endforeach; if(!$active) echo '<p class="hint">Todavía no hay camionetas activas.</p>'; ?></div>
<div class="section-head"><h2>Camionetas Inactivas</h2></div>
<div class="cards"><?php foreach($inactive as $t): truck_card($t); endforeach; if(!$inactive) echo '<p class="hint">Todavía no hay camionetas inactivas.</p>'; ?></div>

<section class="box" id="compras">
  <div class="section-head" style="margin-top:0">
    <h2>Compras</h2>
    <a class="btn" href="#nuevaCompra" style="text-decoration:none">Nueva compra</a>
  </div>
  <div class="compras-grid"><?php
$cols = ['orden'=>'Espera de Orden de Compra','pago'=>'Espera de Pago','entrega'=>'Espera de Entrega'];
foreach($cols as $key=>$label): ?>
    <div class="compra-col"><h3><?=h($label)?></h3><?php $items=array_values(array_filter($compras, fn($c)=>$c['estatus']===$key)); if(!$items): ?><p class="hint" style="padding:0 16px 16px">Sin compras registradas.</p><?php endif; foreach($items as $c): ?>
      <div class="compra-card">
        <h4><?=h($c['titulo'])?></h4>
        <div class="compra-meta">Creado: <?=h(date('d/m/y, H:i', strtotime($c['fecha_creacion'])))?><?php if($c['costo'] !== null): ?> · $<?=h(number_format((float)$c['costo'],2))?><?php endif; ?><br>Material: <?=h($c['material'])?><?php if($c['cantidad']): ?> · Cant: <?=h($c['cantidad'])?><?php endif; ?><?php if($c['proveedor']): ?><br>Proveedor: <?=h($c['proveedor'])?><?php endif; ?><?php if($c['notas']): ?><br>Notas: <?=h($c['notas'])?><?php endif; ?></div>
        <span class="compra-tag"><?=h($label)?></span>
        <form method="post" class="compra-actions">
          <input type="hidden" name="action" value="update_purchase_status">
          <input type="hidden" name="id" value="<?=h($c['id'])?>">
          <select name="estatus" aria-label="Actualizar estado de compra">
            <option value="orden" <?=$c['estatus']==='orden'?'selected':''?>>Orden de compra</option>
            <option value="pago" <?=$c['estatus']==='pago'?'selected':''?>>Espera de pago</option>
            <option value="entrega" <?=$c['estatus']==='entrega'?'selected':''?>>Espera de entrega</option>
            <option value="entregado" <?=$c['estatus']==='entregado'?'selected':''?>>Entregado / historial</option>
          </select>
          <button class="btn blue small">Actualizar estado</button>
        </form>
        <div class="compra-edit">
          <a class="btn light small" href="#editarCompra<?=h($c['id'])?>" style="text-decoration:none">Editar</a>
          <form method="post" onsubmit="return confirm('¿Eliminar esta compra?');">
            <input type="hidden" name="action" value="delete_purchase">
            <input type="hidden" name="id" value="<?=h($c['id'])?>">
            <button class="btn danger small">Eliminar</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?></div>
<?php endforeach; ?>
  </div>
  <div class="historial-compras">
    <h3>Historial de compras entregadas</h3>
    <?php $entregadas=array_values(array_filter($compras, fn($c)=>$c['estatus']==='entregado')); if(!$entregadas): ?><p class="hint">Todavía no hay compras entregadas.</p><?php endif; ?>
    <?php foreach($entregadas as $c): ?>
      <div class="compra-card">
        <h4><?=h($c['titulo'])?></h4>
        <div class="compra-meta">Creado: <?=h(date('d/m/y, H:i', strtotime($c['fecha_creacion'])))?><?php if($c['costo'] !== null): ?> · $<?=h(number_format((float)$c['costo'],2))?><?php endif; ?><br>Material: <?=h($c['material'])?><?php if($c['cantidad']): ?> · Cant: <?=h($c['cantidad'])?><?php endif; ?><?php if($c['proveedor']): ?><br>Proveedor: <?=h($c['proveedor'])?><?php endif; ?><?php if($c['notas']): ?><br>Notas: <?=h($c['notas'])?><?php endif; ?></div>
        <span class="compra-tag">Entregado</span>
        <div class="compra-edit">
          <a class="btn light small" href="#editarCompra<?=h($c['id'])?>" style="text-decoration:none">Editar</a>
          <form method="post" onsubmit="return confirm('¿Eliminar esta compra?');">
            <input type="hidden" name="action" value="delete_purchase">
            <input type="hidden" name="id" value="<?=h($c['id'])?>">
            <button class="btn danger small">Eliminar</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<?php foreach($compras as $c): ?>
<div class="modal modal-add" id="editarCompra<?=h($c['id'])?>"><div class="modal-card"><div class="modal-head"><h2>Editar compra</h2><a class="close" href="#">×</a></div><div class="modal-body" style="grid-template-columns:1fr"><section class="box" style="margin-top:0"><form method="post" class="form-grid"><input type="hidden" name="action" value="update_purchase"><input type="hidden" name="id" value="<?=h($c['id'])?>"><input name="titulo" value="<?=h($c['titulo'])?>" placeholder="Nombre o motivo de la compra *" required><select name="material" required><option value="">Material para afinación</option><?php foreach($materialesAfinacion as $m): ?><option value="<?=h($m)?>" <?=$c['material']===$m?'selected':''?>><?=h($m)?></option><?php endforeach; ?></select><input name="cantidad" value="<?=h($c['cantidad'])?>" placeholder="Cantidad / presentación"><input name="proveedor" value="<?=h($c['proveedor'])?>" placeholder="Proveedor"><input name="costo" type="number" min="0" step="0.01" value="<?=h($c['costo'])?>" placeholder="Costo estimado"><textarea name="notas" class="full" placeholder="Notas de mantenimiento o material no listado"><?=h($c['notas'])?></textarea><button class="btn blue full">Guardar cambios de compra</button></form></section></div></div></div>
<?php endforeach; ?>



<!-- =========================================================
     MODAL: VINCULAR RUTA CON ECO
     Cambia la ruta de una ECO a otra conservando los conductores.
========================================================== -->
<div class="modal modal-add" id="vincularRutaEco">
    <div class="modal-card">
        <div class="modal-head">
            <h2>Vincular ruta con ECO</h2>
            <a class="close" href="#">×</a>
        </div>

        <div class="modal-body" style="grid-template-columns:1fr">
            <section class="box" style="margin-top:0">
                <h3>Cambiar la camioneta que trae una ruta</h3>
                <p class="hint">
                    Ejemplo: si la ECO 10 traía Ruta 11 y ahora la traerá la ECO 12, selecciona Ruta 11 y ECO 12. 
                    El sistema quitará la Ruta 11 de la ECO anterior y se la pondrá a la ECO nueva sin borrar los datos del conductor.
                </p>

                <form method="post" class="form-grid">
                    <input type="hidden" name="action" value="link_route_eco">

                    <label class="field">
                        <span>Ruta que se va a mover</span>
                        <select name="ruta_vincular" required>
                            <option value="">Selecciona ruta</option>
                            <?php foreach($rutasFiltro as $rutaOpt): ?>
                                <option value="<?=h($rutaOpt)?>"><?=h($rutaOpt)?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="field">
                        <span>ECO que tomará la ruta</span>
                        <select name="eco_destino" required>
                            <option value="">Selecciona ECO</option>
                            <?php foreach($trucks as $ecoOpt): ?>
                                <option value="<?=h($ecoOpt['eco'])?>">
                                    ECO <?=h($ecoOpt['eco'])?> · <?=h($ecoOpt['actividad'])?> · <?=h($ecoOpt['conductor'] ?: 'Sin conductor')?> · <?=h($ecoOpt['ruta'] ?: 'Sin ruta')?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="field">
                        <span>Fecha del cambio</span>
                        <input type="date" name="fecha_cambio" value="<?=h(date('Y-m-d'))?>">
                    </label>

                    <button class="btn blue full" onclick="return confirm('¿Confirmas vincular esta ruta con la ECO seleccionada? La ECO anterior quedará sin ruta.');">
                        Guardar vinculación
                    </button>
                </form>
            </section>
        </div>
    </div>
</div>

<div class="modal modal-add" id="nuevaCamioneta"><div class="modal-card"><div class="modal-head"><h2>Nueva camioneta</h2><a class="close" href="#">×</a></div><div class="modal-body" style="grid-template-columns:1fr"><section class="box" style="margin-top:0"><div class="add-title"><img src="img/NP-300.png" alt="Camioneta"><h2>Registrar camioneta</h2></div><p class="hint">Eco, matrícula y número de serie no se pueden repetir. Si la actividad es “en ruta”, el conductor es obligatorio. Mantenimiento: modelos 2014 y 2015 cada 5,000 km; modelos más nuevos cada 10,000 km.</p><form method="post" class="form-grid"><input type="hidden" name="action" value="add_truck"><label class="field"><span>Número de eco *</span><input name="eco" type="number" inputmode="numeric" pattern="[0-9]*" min="0" step="1" placeholder="Solo dígitos" required></label><label class="field"><span>Año del modelo *</span><select name="modelo_anio" required><option value="">Año *</option><?php for($y=(int)date('Y')+1;$y>=2010;$y--): ?><option value="<?=h($y)?>"><?=h($y)?></option><?php endfor; ?></select></label><label class="field"><span>Modelo de camioneta *</span><input name="modelo_texto" placeholder="Ej. NP300 / Frontier" required></label><label class="field"><span>No. de serie *</span><input name="no_serie" placeholder="No. de serie" required></label><label class="field"><span>Matrícula / placas *</span><input name="matricula" placeholder="Matrícula / placas" required></label><label class="field"><span>Fecha último mantenimiento</span><input name="fecha_ultimo_mantenimiento" type="date"></label><label class="field"><span>Fecha de kilometraje y gasolina</span><input name="fecha_indicadores" type="date" value="<?=h(date('Y-m-d'))?>"></label><label class="field"><span>Kilometraje registrado</span><input name="kilometraje_actual" type="number" min="0" placeholder="Kilometraje"></label><label class="field"><span>% tanque gasolina</span><input name="tanque_gasolina" type="number" min="0" max="100" placeholder="0 a 100"></label><label class="field"><span>Actividad</span><select name="actividad" required><option value="sin uso">Sin uso</option><option value="en ruta">En ruta</option><option value="mantenimiento">Mantenimiento</option></select></label><label class="field"><span>Nombre del vendedor</span><input list="vendedores" name="nombre_conductor" placeholder="Nombre del vendedor"></label><label class="field"><span>Ruta del vendedor</span><select name="ruta_conductor"><option value="">Sin ruta</option><?php for($r=1;$r<=14;$r++): ?><option value="Ruta <?=h($r)?>">Ruta <?=h($r)?></option><?php endfor; ?></select></label><label class="field"><span>Teléfono vendedor</span><input name="telefono_conductor" placeholder="Teléfono vendedor"></label><label class="field"><span>Correo vendedor</span><input name="correo_conductor" type="email" placeholder="Correo vendedor"></label><label class="field"><span>Fecha en que empezó a usarla el vendedor</span><input name="fecha_uso" type="date"></label><button class="btn blue full">Guardar camioneta</button></form></section></div></div></div>

<div class="modal modal-add" id="nuevaCompra"><div class="modal-card"><div class="modal-head"><h2>Nueva compra de mantenimiento</h2><a class="close" href="#">×</a></div><div class="modal-body" style="grid-template-columns:1fr"><section class="box" style="margin-top:0"><p class="hint">Selecciona el material de afinación. Si ocupas algo que no aparece, elige “Otro” y escríbelo en notas de mantenimiento.</p><form method="post" class="form-grid"><input type="hidden" name="action" value="add_purchase"><input name="titulo" placeholder="Ej. Pedido de aceites / Compra de filtros *" required><select name="material" required><option value="">Material para afinación</option><?php foreach($materialesAfinacion as $m): ?><option value="<?=h($m)?>"><?=h($m)?></option><?php endforeach; ?></select><input name="cantidad" placeholder="Cantidad / presentación"><input name="proveedor" placeholder="Proveedor"><input name="costo" type="number" min="0" step="0.01" placeholder="Costo estimado"><select name="estatus"><option value="orden">Espera de Orden de Compra</option><option value="pago">Espera de Pago</option><option value="entrega">Espera de Entrega</option><option value="entregado">Entregado</option></select><textarea name="notas" class="full" placeholder="Notas de mantenimiento o material no listado"></textarea><button class="btn blue full">Guardar compra</button></form></section></div></div></div>
<datalist id="vendedores"><?php foreach($vendors as $v): ?><option value="<?=h($v)?>"><?php endforeach; ?></datalist>
</div>
<?php
function truck_card(array $t): void {
    global $pdo, $canUpdateDriver, $canDeleteTruck;

    $tag = $t['actividad'] === 'en ruta' ? 'ruta' : ($t['actividad'] === 'mantenimiento' ? 'mant' : 'sin');
    $mant = mantenimiento_status($pdo, $t);
?>

<!-- =========================================================
     TARJETA PRINCIPAL DE CAMIONETA
========================================================= -->
<div class="truck">
    <img src="img/NP-300.png" onerror="this.src='img/truck.png'" alt="camioneta">

    <div class="truck-body">
        <div class="truck-top">
            <h3><?=h($t['eco'])?></h3>
            <span class="tag <?=$tag?>"><?=h(ucfirst($t['actividad']))?></span>
        </div>

        <div class="truck-km">
            <strong><?=h(number_format((float)$t['kilometraje_actual']))?> km</strong>
            <span>Gasolina <?=h($t['tanque_gasolina'])?>%</span>
        </div>

        <div class="truck-fuel-extra">
            <div class="truck-fuel-box">
                <span>Depositado</span>
                <strong>$<?=h(number_format((float)($t['gasolina_depositada'] ?? 0), 2))?></strong>
            </div>

            <div class="truck-fuel-box">
                <span>Llegó CEDIS</span>
                <strong><?=(($t['tanque_llegada_cedis'] ?? '') !== '') ? h((string)$t['tanque_llegada_cedis']).'%' : '—'?></strong>
            </div>
        </div>

        <div class="truck-info">
            <p><b>Conductor:</b> <?=h($t['conductor'] ?: 'Sin asignar')?></p>
            <p><b>Ruta:</b> <?=h($t['ruta'] ?: '—')?></p>
            <p><b>Placas:</b> <?=h($t['matricula'])?></p>
            <p><b>Último Mant:</b> <?=h($t['fecha_ultimo_mantenimiento'] ?: '—')?></p>

            <?php if($mant['alerta']): ?>
                <div class="maintenance-alert maintenance-alert-row">
                    <span>⚠️ Ya ocupa mantenimiento</span>
                    <form method="post" onsubmit="return confirm('¿Quitar esta notificación de mantenimiento?');">
                        <input type="hidden" name="action" value="skip_maintenance_alert">
                        <input type="hidden" name="id" value="<?=h($t['id'])?>">
                        <button class="btn danger small" type="submit">Aún no le toca</button>
                    </form>
                </div>
            <?php endif; ?>

            <p><b>Próx Mant:</b> <?=h(number_format($mant['proximo']))?> km</p>
            <p class="maintenance-note">
                Intervalo: <?=h(number_format($mant['intervalo']))?> km ·
                Recorrido desde base: <?=h(number_format($mant['recorridos']))?> km
            </p>
        </div>

        <div class="actions">
            <a class="btn light small" href="#detalle<?=h($t['id'])?>">Ver más</a>
        </div>
    </div>
</div>


<!-- =========================================================
     MODAL: DETALLE DE CAMIONETA
========================================================= -->
<div class="modal" id="detalle<?=h($t['id'])?>">
    <div class="modal-card">

        <!-- ENCABEZADO DEL MODAL -->
        <div class="modal-head">
            <h2>Detalles de la Unidad: <?=h($t['eco'])?></h2>
            <a class="close" href="#">×</a>
        </div>

        <!-- CUERPO DEL MODAL CON DOS COLUMNAS INTERNAS
             Esto evita los espacios en blanco entre historiales. -->
        <div class="modal-body">

            <!-- =====================================================
                 COLUMNA IZQUIERDA
                 Datos de camioneta + historial de mantenimientos
            ====================================================== -->
            <div class="modal-col">

                <!-- =========================
                     DATOS DE CAMIONETA
                ========================== -->
                <section class="box truck-detail-data">
                    <div class="box-title-row">
                        <h3>Datos de camioneta</h3>
                    </div>

                    <?php if($mant['alerta']): ?>
                        <div class="maintenance-alert maintenance-alert-row">
                            <span>
                                ⚠️ Esta unidad ya debe entrar a mantenimiento.
                                Lleva <?=h(number_format($mant['recorridos']))?> km desde la base de <?=h(number_format($mant['base']))?> km.
                            </span>
                            <form method="post" onsubmit="return confirm('¿Quitar esta notificación de mantenimiento?');">
                                <input type="hidden" name="action" value="skip_maintenance_alert">
                                <input type="hidden" name="id" value="<?=h($t['id'])?>">
                                <button class="btn danger small" type="submit">Aún no le toca</button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <p><b>Modelo:</b> <?=h($t['modelo'])?></p>
                    <p><b>No. de serie:</b> <?=h($t['no_serie'])?></p>

                    <!-- ACTUALIZAR NO. DE SERIE -->
                    <details class="update-panel">
                        <summary class="update-link">Actualizar No. de serie</summary>
                        <form method="post" class="mini-update">
                            <input type="hidden" name="action" value="update_serial">
                            <input type="hidden" name="id" value="<?=h($t['id'])?>">
                            <label class="field">
                                <span>No. de serie</span>
                                <input name="no_serie" value="<?=h($t['no_serie'])?>" placeholder="No. de serie">
                            </label>
                            <button class="btn blue">Guardar</button>
                        </form>
                    </details>

                    <p><b>Matrícula:</b> <?=h($t['matricula'])?></p>

                    <!-- ACTUALIZAR PLACAS -->
                    <details class="update-panel">
                        <summary class="update-link">Actualizar placas</summary>
                        <form method="post" class="mini-update">
                            <input type="hidden" name="action" value="update_plates">
                            <input type="hidden" name="id" value="<?=h($t['id'])?>">
                            <label class="field">
                                <span>Placas</span>
                                <input name="matricula" value="<?=h($t['matricula'])?>" placeholder="Placas">
                            </label>
                            <button class="btn blue">Guardar</button>
                        </form>
                    </details>

                    <p><b>Kilometraje:</b> <?=h(number_format((float)$t['kilometraje_actual']))?> km</p>
                    <p><b>Tanque:</b> <?=h($t['tanque_gasolina'])?>%</p>
                    <p><b>Intervalo mantenimiento:</b> <?=h(number_format($mant['intervalo']))?> km</p>
                    <p><b>Base para mantenimiento:</b> <?=h(number_format($mant['base']))?> km</p>
                    <p><b>Próximo mantenimiento:</b> <?=h(number_format($mant['proximo']))?> km</p>

                    <!-- ACTUALIZAR KILOMETRAJE Y GASOLINA -->
                    <details class="update-panel">
                        <summary class="update-link">Actualizar km y gasolina</summary>
                        <form method="post" class="form-grid update-form-grid">
                            <input type="hidden" name="action" value="update_indicators">
                            <input type="hidden" name="id" value="<?=h($t['id'])?>">

                            <label class="field">
                                <span>Fecha de kilometraje y gasolina</span>
                                <input name="fecha_indicadores" type="date" value="<?=h(date('Y-m-d'))?>">
                            </label>

                            <label class="field">
                                <span>Kilometraje registrado</span>
                                <input name="kilometraje_actual" type="number" min="0" value="<?=h((string)($t['kilometraje_salida'] ?? $t['kilometraje_actual']))?>">
                            </label>

                            <label class="field">
                                <span>% tanque gasolina</span>
                                <input name="tanque_gasolina" type="number" min="0" max="100" value="<?=h((string)($t['gasolina_salida_hist'] ?? $t['tanque_gasolina']))?>">
                            </label>

                            <button class="btn blue full">Guardar km y gasolina</button>
                        </form>
                    </details>

                    <?php if($canDeleteTruck): ?>
                        <!-- ELIMINAR CAMIONETA -->
                        <details class="update-panel danger-panel">
                            <summary class="update-link danger-link">Eliminar camioneta</summary>
                            <form method="post" class="delete-form" onsubmit="return confirm('¿Seguro que quieres eliminar esta camioneta? Se borrará también su historial.');">
                                <input type="hidden" name="action" value="delete_truck">
                                <input type="hidden" name="id" value="<?=h($t['id'])?>">
                                <button class="btn danger">Confirmar eliminación</button>
                            </form>
                        </details>
                    <?php endif; ?>
                </section>

                <!-- =========================
                     DATOS DE SALIDA Y LLEGADA
                     Solo muestra los indicadores ya guardados.
                ========================== -->
                <section class="box">
                    <h3>Datos de salida y llegada</h3>
                    <div class="trip-data-grid">
                        <div class="trip-data-box">
                            <h4>Salida</h4>
                            <p><b>Kilometraje salida:</b> <?=h(number_format((float)($t['kilometraje_salida'] ?? $t['kilometraje_actual'] ?? 0)))?> km</p>
                            <p><b>Gasolina salida:</b> <?=h((string)($t['gasolina_salida_hist'] ?? $t['tanque_gasolina'] ?? 0))?>%</p>
                            <p><b>Depositado:</b> $<?=h(number_format((float)($t['gasolina_depositada'] ?? 0),2))?></p>

                            <!-- BOTÓN OCULTO: REGISTRAR SALIDA -->
                            <details class="update-panel salida-panel" id="registrarSalida<?=h($t['id'])?>">
                                <summary class="update-link">Registrar salida</summary>
                                <form method="post" class="form-grid update-form-grid">
                                    <input type="hidden" name="action" value="update_indicators">
                                    <input type="hidden" name="id" value="<?=h($t['id'])?>">

                                    <label class="field">
                                        <span>Fecha de salida</span>
                                        <input name="fecha_indicadores" type="date" value="<?=h(date('Y-m-d'))?>">
                                    </label>

                                    <label class="field">
                                        <span>Kilometraje salida</span>
                                        <input name="kilometraje_actual" type="number" min="0" value="<?=h((string)($t['kilometraje_salida'] ?? $t['kilometraje_actual']))?>">
                                    </label>

                                    <label class="field">
                                        <span>% gasolina salida</span>
                                        <input name="tanque_gasolina" type="number" min="0" max="100" value="<?=h((string)($t['gasolina_salida_hist'] ?? $t['tanque_gasolina']))?>">
                                    </label>

                                    <label class="field">
                                        <span>$ gasolina depositada</span>
                                        <input name="gasolina_depositada" type="number" min="0" step="0.01" value="<?=h((string)($t['gasolina_depositada'] ?? 0))?>">
                                    </label>

                                    <button class="btn blue full">Guardar salida</button>
                                </form>
                            </details>
                        </div>
                        <div class="trip-data-box">
                            <h4>Llegada CEDIS</h4>
                            <p><b>Kilometraje llegada:</b> <?=isset($t['kilometraje_llegada']) && $t['kilometraje_llegada'] !== null && $t['kilometraje_llegada'] !== '' ? h(number_format((float)$t['kilometraje_llegada'])).' km' : '—'?></p>
                            <p><b>Gasolina llegada:</b> <?=($t['tanque_llegada_cedis'] ?? '') !== '' ? h((string)$t['tanque_llegada_cedis']).'%' : '—'?></p>

                            <!-- BOTÓN OCULTO: REGISTRAR LLEGADA -->
                            <details class="update-panel">
                                <summary class="update-link">Registrar llegada</summary>
                                <form method="post" class="form-grid update-form-grid">
                                    <input type="hidden" name="action" value="update_arrival">
                                    <input type="hidden" name="id" value="<?=h($t['id'])?>">

                                    <label class="field">
                                        <span>Fecha de llegada</span>
                                        <input name="fecha_indicadores" type="date" value="<?=h(date('Y-m-d'))?>">
                                    </label>

                                    <label class="field">
                                        <span>Kilometraje llegada</span>
                                        <input name="kilometraje_llegada" type="number" min="0" value="<?=h((string)($t['kilometraje_llegada'] ?? ''))?>" placeholder="Kilometraje llegada">
                                    </label>

                                    <label class="field">
                                        <span>% gasolina llegada CEDIS</span>
                                        <input name="tanque_llegada_cedis" type="number" min="0" max="100" value="<?=h((string)($t['tanque_llegada_cedis'] ?? ''))?>" placeholder="0 a 100">
                                    </label>

                                    <button class="btn blue full">Guardar llegada</button>
                                </form>
                            </details>
                        </div>
                    </div>
                </section>

                <!-- =========================
                     HISTORIAL DE MANTENIMIENTOS
                     Se queda debajo para no dejar espacios en blanco arriba.
                ========================== -->
                <section class="box">
                    <h3>Historial de mantenimientos</h3>

                    <?php
                        $mantHist = $pdo->prepare('SELECT * FROM camioneta_mantenimientos WHERE camioneta_id=? ORDER BY fecha_mantenimiento DESC, id DESC LIMIT 20');
                        $mantHist->execute([$t['id']]);
                        $hayMant = false;
                    ?>

                    <?php foreach($mantHist as $m): $hayMant = true; ?>
                        <div class="history-row">
                            <span>
                                <?=h($m['fecha_mantenimiento'])?>
                                <?php if($m['notas']): ?>
                                    <br><small><?=h($m['notas'])?></small>
                                <?php endif; ?>
                            </span>
                            <strong>
                                <?=h($m['kilometraje'] !== null ? number_format((float)$m['kilometraje']).' km' : '—')?>
                            </strong>
                        </div>
                    <?php endforeach; ?>

                    <?php if(!$hayMant): ?>
                        <p class="hint">Sin mantenimientos registrados.</p>
                    <?php endif; ?>
                </section>

            </div>


            <!-- =====================================================
                 COLUMNA DERECHA
                 Datos de conductor + mantenimiento + historiales
            ====================================================== -->
            <div class="modal-col">

                <!-- =========================
                     DATOS DE CONDUCTOR
                ========================== -->
                <section class="box driver-detail-data">
                    <div class="box-title-row">
                        <h3>Datos de conductor</h3>
                    </div>

                    <p><b>Nombre:</b> <?=h($t['conductor'] ?: 'Sin asignar')?></p>
                    <p><b>Teléfono:</b> <?=h($t['telefono'] ?: '—')?></p>
                    <p><b>Correo:</b> <?=h($t['correo'] ?: '—')?></p>
                    <p><b>Ruta:</b> <?=h($t['ruta'] ?: '—')?></p>

                    <?php if($canUpdateDriver): ?>
                        <!-- ACTUALIZAR SOLO RUTA -->
                        <details class="update-panel">
                            <summary class="update-link">Actualizar ruta</summary>
                            <form method="post" class="mini-update">
                                <input type="hidden" name="action" value="update_driver_route">
                                <input type="hidden" name="id" value="<?=h($t['id'])?>">
                                <label class="field">
                                    <span>Ruta</span>
                                    <select name="ruta_conductor">
                                        <option value="">Sin ruta</option>
                                        <?php for($r=1; $r<=14; $r++): ?>
                                            <option value="Ruta <?=h($r)?>" <?=($t['ruta'] ?? '') === 'Ruta '.$r ? 'selected' : ''?>>Ruta <?=h($r)?></option>
                                        <?php endfor; ?>
                                    </select>
                                </label>
                                <button class="btn blue">Guardar</button>
                            </form>
                        </details>
                    <?php endif; ?>

                    <p><b>Desde:</b> <?=h($t['fecha_inicio'] ?: '—')?></p>

                    <?php if($canUpdateDriver): ?>
                        <!-- CAMBIAR CONDUCTOR / ACTIVIDAD -->
                        <details class="update-panel">
                            <summary class="update-link">Cambiar conductor / actividad</summary>
                            <form method="post" class="form-grid update-form-grid">
                                <input type="hidden" name="action" value="update_driver">
                                <input type="hidden" name="id" value="<?=h($t['id'])?>">

                                <label class="field">
                                    <span>Actividad</span>
                                    <select name="actividad">
                                        <option value="en ruta" <?=$t['actividad']==='en ruta'?'selected':''?>>En ruta</option>
                                        <option value="sin uso" <?=$t['actividad']==='sin uso'?'selected':''?>>Sin uso</option>
                                        <option value="mantenimiento" <?=$t['actividad']==='mantenimiento'?'selected':''?>>Mantenimiento</option>
                                    </select>
                                </label>

                                <label class="field">
                                    <span>Nuevo conductor</span>
                                    <input list="vendedores" name="nombre_conductor" placeholder="Nuevo conductor">
                                </label>

                                <label class="field">
                                    <span>Teléfono</span>
                                    <input name="telefono_conductor" placeholder="Teléfono">
                                </label>

                                <label class="field">
                                    <span>Ruta</span>
                                    <select name="ruta_conductor">
                                        <option value="">Sin ruta</option>
                                        <?php for($r=1; $r<=14; $r++): ?>
                                            <option value="Ruta <?=h($r)?>">Ruta <?=h($r)?></option>
                                        <?php endfor; ?>
                                    </select>
                                </label>

                                <label class="field">
                                    <span>Correo</span>
                                    <input name="correo_conductor" type="email" placeholder="Correo">
                                </label>

                                <label class="field">
                                    <span>Fecha en que empezó a usarla el vendedor</span>
                                    <input name="fecha_uso" type="date" value="<?=h(date('Y-m-d'))?>">
                                </label>

                                <button class="btn blue full">Guardar conductor / actividad</button>
                            </form>
                        </details>
                    <?php else: ?>
                        <p class="hint">
                            Solo supervisor, administrativo, administrador, gerente o analista puede actualizar el conductor,
                            actividad o eliminar camionetas. Los vendedores solo actualizan kilometraje y gasolina.
                        </p>
                    <?php endif; ?>

                    <hr class="detail-separator">

                    <!-- ACTUALIZAR MANTENIMIENTO -->
                    <h3>Actualizar mantenimiento</h3>
                    <form method="post" class="form-grid update-form-grid">
                        <input type="hidden" name="action" value="update_maintenance">
                        <input type="hidden" name="id" value="<?=h($t['id'])?>">

                        <label class="field">
                            <span>Fecha de mantenimiento</span>
                            <input name="fecha_mantenimiento" type="date" value="<?=h(date('Y-m-d'))?>">
                        </label>

                        <label class="field">
                            <span>Kilometraje al mantenimiento</span>
                            <input name="kilometraje" type="number" placeholder="Kilometraje" value="<?=h($t['kilometraje_actual'])?>">
                        </label>

                        <label class="field full">
                            <span>Notas de mantenimiento</span>
                            <textarea name="notas" placeholder="Notas de mantenimiento"></textarea>
                        </label>

                        <button class="btn full">Guardar mantenimiento</button>
                    </form>
                </section>

                <!-- =========================
                     HISTORIAL DE CONDUCTORES + HISTORIAL DIARIO
                ========================== -->
                <section class="box">
                    <h3>Historial de conductores</h3>

                    <?php
                        $hist = $pdo->prepare('SELECT * FROM camioneta_conductores WHERE camioneta_id=? ORDER BY fecha_inicio DESC');
                        $hist->execute([$t['id']]);
                    ?>

                    <?php foreach($hist as $r): ?>
                        <div class="history-row">
                            <span>
                                <?=h($r['nombre'])?>
                                <br><small><?=h($r['telefono'])?> · <?=h($r['correo'])?> · <?=h($r['ruta'] ?: 'Sin ruta')?></small>
                            </span>
                            <strong><?=h($r['fecha_inicio'])?> a <?=h($r['fecha_fin'] ?: 'Actual')?></strong>
                        </div>
                    <?php endforeach; ?>

                    <h3 style="margin-top:24px">Historial diario</h3>

                    <?php
                        $ind = $pdo->prepare('SELECT * FROM camioneta_indicadores_diarios WHERE camioneta_id=? ORDER BY fecha DESC LIMIT 15');
                        $ind->execute([$t['id']]);
                    ?>

                    <?php foreach($ind as $r): ?>
                        <?php
                            $kmSalidaHist = (float)($r['kilometraje'] ?? 0);
                            $kmLlegadaHist = ($r['kilometraje_llegada'] ?? '') !== '' ? (float)$r['kilometraje_llegada'] : null;
                            $kmRecorridosHist = $kmLlegadaHist !== null ? max(0, $kmLlegadaHist - $kmSalidaHist) : null;
                        ?>
                        <div class="history-row history-row-stacked">
                            <span><?=h($r['fecha'])?></span>
                            <strong>
                                Km salida: <?=h(number_format($kmSalidaHist))?> km · Gasolina salida: <?=h($r['tanque_gasolina'])?>%
                                <?php if($kmLlegadaHist !== null): ?>
                                    <br>Km llegada: <?=h(number_format($kmLlegadaHist))?> km · Km recorridos: <?=h(number_format($kmRecorridosHist))?> km
                                <?php endif; ?>
                                <?php if(($r['tanque_llegada_cedis'] ?? '') !== ''): ?>
                                    <br>Gasolina llegada CEDIS: <?=h($r['tanque_llegada_cedis'])?>%
                                <?php endif; ?>
                            </strong>
                        </div>
                    <?php endforeach; ?>
                </section>

            </div>

        </div>
    </div>
</div>
<?php } ?>

<script>
(function(){
  const el = document.getElementById('greetingText');
  if(!el) return;
  const h = new Date().getHours();
  const saludo = (h >= 5 && h < 12) ? 'Buenos días' : (h >= 12 && h < 18 ? 'Buenas tardes' : 'Buenas noches');
  const emoji = (h >= 5 && h < 18) ? '☀️' : '🌛';
  fetch('https://api.openweathermap.org/data/2.5/weather?q=Monterrey,MX&appid=5d4a9551bb1b14e873f2c9e5e2d8b3d8&units=metric&lang=es')
    .then(r => r.ok ? r.json() : null)
    .then(d => {
      const temp = d && d.main ? Math.round(d.main.temp) + '°C en ' + (d.name || 'Monterrey') : '24°C en Monterrey';
      el.textContent = saludo + ' ' + emoji + ' | ' + temp;
    })
    .catch(() => { el.textContent = saludo + ' ' + emoji + ' | 24°C en Monterrey'; });


  document.querySelectorAll('.trip-data-grid').forEach((grid) => {
    grid.querySelectorAll('details.update-panel').forEach((panel) => {
      panel.addEventListener('toggle', () => {
        if (!panel.open) return;
        grid.querySelectorAll('details.update-panel').forEach((other) => {
          if (other !== panel) other.open = false;
        });
      });
    });
  });

  document.querySelectorAll('.open-salida-link').forEach((link) => {
    link.addEventListener('click', () => {
      const id = link.getAttribute('data-salida-id');
      setTimeout(() => {
        const panel = document.getElementById('registrarSalida' + id);
        if (panel) {
          panel.open = true;
          panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
      }, 80);
    });
  });

  function cerrarModalActual() {
    document.querySelectorAll('.modal').forEach((modal) => {
      modal.style.display = 'none';
    });

    history.pushState('', document.title, window.location.pathname + window.location.search);
  }

  window.addEventListener('hashchange', () => {
    document.querySelectorAll('.modal').forEach((modal) => {
      modal.style.display = '';
    });
  });

  document.querySelectorAll('.modal').forEach((modal) => {
    modal.addEventListener('click', (event) => {
      if (event.target === modal) {
        cerrarModalActual();
      }
    });
  });

  document.querySelectorAll('.close').forEach((btnCerrar) => {
    btnCerrar.addEventListener('click', (event) => {
      event.preventDefault();
      cerrarModalActual();
    });
  });
})();
</script>
</body></html>
