#!/usr/bin/php
<?php
/**
 * Bootstrap no interactivo de Open XDMoD.
 *
 * Existe porque `xdmod-setup` es un asistente de menú interactivo: no se
 * puede ejecutar desde un playbook sin simular entradas de teclado. Este
 * script hace exactamente lo mismo que sus pasos relevantes, tomando los
 * valores de /etc/xdmod/portal_settings.ini y de la línea de comandos:
 *
 *   1. Pipelines ETL de bootstrap   (equivale a DatabaseSetup::handle)
 *   2. Tablas de periodos de tiempo (día/mes/trimestre/año, 2000-2038)
 *   3. acl-config                   (carga las ACLs del RPM en la base)
 *   4. Usuario administrador        (equivale a AdminUserSetup::handle)
 *
 * Idempotente: los pipelines no se vuelven a correr si la base ya tiene las
 * tablas del data warehouse, y el usuario admin solo se crea si no existe.
 * Se puede forzar con --force.
 *
 * Uso:
 *   xdmod_bootstrap.php --admin-user=u --admin-pass=p --admin-email=e \
 *                       [--admin-first=N] [--admin-last=A] [--force]
 */

require_once '/usr/share/xdmod/configuration/linker.php';

/**
 * linker.php instala un handler global de excepciones que responde en formato
 * JSON pensado para el navegador: desde la línea de comandos imprime un
 * {"success":false,...} y sale con código 0. Con eso un bootstrap fallido
 * parece exitoso y Ansible no se entera. Se reemplaza por uno que muestre el
 * error real y termine distinto de cero.
 */
set_exception_handler(function ($e) {
    fwrite(STDERR, "[xdmod-bootstrap] EXCEPCION: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
});

use CCR\DB;
use CCR\Log;
use ETL\Utilities;

// Los pipelines de bootstrap cargan volúmenes grandes en memoria.
ini_set('memory_limit', -1);

const STATE_DIR = '/var/lib/xdmod';
const DONE_MARKER = '/var/lib/xdmod/bootstrap.done';

// Rango que usa xdmod-setup por defecto para las tablas de periodos.
const TP_MIN = '2000-01-01';
const TP_MAX = '2038-01-18';

/**
 * Imprime un paso en un formato reconocible, para que Ansible pueda
 * distinguir qué se hizo de qué ya estaba.
 */
function step($msg)
{
    fwrite(STDOUT, "[xdmod-bootstrap] $msg\n");
}

function fatal($msg)
{
    fwrite(STDERR, "[xdmod-bootstrap] FATAL: $msg\n");
    exit(1);
}

$opts = getopt('', [
    'admin-user:',
    'admin-pass:',
    'admin-email:',
    'admin-first:',
    'admin-last:',
    'force',
]);

$adminUser   = isset($opts['admin-user']) ? $opts['admin-user'] : null;
$adminPass   = isset($opts['admin-pass']) ? $opts['admin-pass'] : null;
$adminEmail  = isset($opts['admin-email']) ? $opts['admin-email'] : null;
$adminFirst  = isset($opts['admin-first']) ? $opts['admin-first'] : 'XDMoD';
$adminLast   = isset($opts['admin-last']) ? $opts['admin-last'] : 'Admin';
$force       = array_key_exists('force', $opts);

if (empty($adminUser) || empty($adminPass) || empty($adminEmail)) {
    fatal('--admin-user, --admin-pass y --admin-email son obligatorios');
}

// XDMoD rechaza passwords con comillas en portal_settings.ini; si se cuela una,
// el portal entero queda caído, así que se corta acá y no en producción.
if (strpos($adminPass, "'") !== false || strpos($adminPass, '"') !== false) {
    fatal('El password de la base no puede contener comillas simples ni dobles');
}

$logger = Log::factory(
    'xdmod-bootstrap',
    array('console' => true, 'consoleLogLevel' => Log::WARNING, 'db' => false)
);

/**
 * Devuelve el primer valor de la primera fila.
 *
 * CCR\DB\MySQLDB no tiene fetchOne(); su API es query($sql, $params), que
 * devuelve las filas como arrays asociativos.
 */
function scalar($db, $sql, array $params = array())
{
    $rows = $db->query($sql, $params);
    if (empty($rows)) {
        return 0;
    }

    $first = array_values($rows[0]);

    return (int)$first[0];
}

/**
 * ¿Hay ACLs cargadas? Es una comprobación aparte de la del data warehouse
 * porque las carga acl-config y no los pipelines ETL: un bootstrap que
 * corrió pero se cayó antes de esa última llamada deja el data warehouse
 * listo y las ACLs vacías.
 */
function aclsLoaded()
{
    $db = DB::factory('database');
    return scalar($db, 'SELECT COUNT(*) FROM moddb.acl_types') > 0;
}

/**
 * El bootstrap del data warehouse ya corrió si modw tiene las tablas que
 * crean sus pipelines. Se consulta information_schema en vez de contar filas
 * porque el contenido cambia con la ingesta, pero la existencia de las tablas
 * no.
 *
 * Los nombres son los de XDMoD 11: las tablas de periodos de tiempo se llaman
 * days/months/quarters/years, no "time_periods", y no existe una tabla
 * "job_storage". Usar nombres que no existen haría que esta función diera
 * false siempre y el playbook reintentara el bootstrap en cada corrida.
 */
function dataWarehouseBootstrapped()
{
    $db = DB::factory('datawarehouse');
    $tables = scalar(
        $db,
        "SELECT COUNT(*) FROM information_schema.tables "
        . "WHERE table_schema = 'modw' AND table_name IN ('days','months','quarters','years')"
    );

    return $tables >= 4;
}

/**
 * Comprueba que el esquema quedó realmente creado.
 *
 * Esto existe por una razón concreta: ETL\Utilities::runEtlPipeline() registra
 * los errores en el log pero NO lanza excepción. Con una base faltando, el
 * script seguía derecho y reportaba "bootstrap completo" con moddb vacía, y
 * el portal después devolvía 500 con un 42S02 (tabla inexistente) que nadie
 * relacionaba con el bootstrap. Verificar el resultado evita eso.
 */
function assertSchemaCreated()
{
    $db = DB::factory('database');

    $moddbTables = scalar(
        $db,
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'moddb'"
    );
    if ($moddbTables === 0) {
        fatal('moddb quedó sin tablas: el bootstrap no creó el esquema');
    }

    // Users es la tabla de cuentas del portal (con U mayúscula en XDMoD 11).
    $userTables = scalar(
        $db,
        "SELECT COUNT(*) FROM information_schema.tables "
        . "WHERE table_schema = 'moddb' AND table_name = 'Users'"
    );
    if ($userTables === 0) {
        fatal('moddb.Users no existe: el portal no va a poder autenticar');
    }

    step("esquema verificado: $moddbTables tablas en moddb");
}

try {
    if (!is_dir(STATE_DIR)) {
        mkdir(STATE_DIR, 0755, true);
    }

    // ---------------------------------------------------------------------
    // 1. Pipelines ETL de bootstrap
    // ---------------------------------------------------------------------
    if ($force || !dataWarehouseBootstrapped()) {
        step('ejecutando pipelines ETL de bootstrap');
        Utilities::runEtlPipeline(
            array(
                'xdb-bootstrap',
                'jobs-xdw-bootstrap',
                'xdw-bootstrap-storage',
                'shredder-bootstrap',
                'staging-bootstrap',
                'hpcdb-bootstrap',
                'acls-xdmod-management',
                'logger-bootstrap',
            ),
            $logger
        );
        step('pipelines ETL de bootstrap: OK');
        $etlRan = true;
    } else {
        step('data warehouse ya inicializado, se omiten los pipelines ETL');
        $etlRan = false;
    }

    // ---------------------------------------------------------------------
    // 2. Tablas de periodos de tiempo
    // ---------------------------------------------------------------------
    // Se verifica el esquema ANTES de seguir: si el bootstrap falló en
    // silencio, esto falla con un error mucho más claro.
    assertSchemaCreated();

    // generateMainTable() hace TRUNCATE + INSERT, así que se puede repetir
    // sin riesgo. Es la fuente de datos de todos los reportes por fecha.
    step('generando tablas de periodos de tiempo (2000-01-01 a 2038-01-18)');
    foreach (array('day', 'month', 'quarter', 'year') as $aggUnit) {
        $tpg = TimePeriodGenerator::getGeneratorForUnit($aggUnit);
        $tpg->generateMainTable(
            DB::factory('datawarehouse'),
            new \DateTime(TP_MIN),
            new \DateTime(TP_MAX)
        );
    }
    step('tablas de periodos de tiempo: OK');

    // ---------------------------------------------------------------------
    // 3. acl-config
    // ---------------------------------------------------------------------
    // Traduce los archivos de ACL del RPM a la tabla de permisos. Se comprueba
    // el estado real en vez de confiar en si el ETL corrió, porque son dos
    // pasos independientes y este es el último de la cadena.
    if ($force || !aclsLoaded()) {
        step('cargando ACLs con acl-config');
        $aclstatus = 0;
        passthru(BIN_DIR . '/acl-config', $aclstatus);
        if ($aclstatus !== 0) {
            fatal("acl-config devolvió código $aclstatus");
        }
        if (!aclsLoaded()) {
            fatal('acl-config terminó bien pero las ACLs siguen vacías');
        }
        step('acl-config: OK');
    } else {
        step('ACLs ya cargadas, se omite acl-config');
    }

    // ---------------------------------------------------------------------
    // 4. Usuario administrador
    // ---------------------------------------------------------------------
    // El INSERT es directo y no usa XDUser::saveUser() a propósito.
    //
    // saveUser() arma el INSERT con un parámetro llamado password_last_updated
    // al que le pasa el texto literal 'NOW()' (XDUser.php:966). Al ser un
    // valor bindeado y no una expresión SQL, MariaDB lo rechaza con:
    //   SQLSTATE[22007] Incorrect datetime value: 'NOW()'
    // Es un bug de XDMoD 11.0.4 que rompe justamente la creación de usuarios
    // con password, que es el paso "Create Admin User" de xdmod-setup.
    //
    // El hash se genera con password_hash(), que es exactamente lo que hace
    // XDUser.php:963, así que el login del portal valida sin cambios.
    $db = DB::factory('database');
    $existing = scalar(
        $db,
        "SELECT COUNT(*) FROM moddb.Users WHERE username = :u",
        array(':u' => $adminUser)
    );

    if ($existing > 0) {
        step("el usuario $adminUser ya existe, no se modifica");
    } else {
        step("creando usuario administrador $adminUser");

        $now = date('Y-m-d H:i:s');
        $db->execute(
            "INSERT INTO moddb.Users "
            . "(username, password, password_last_updated, email_address, first_name, "
            . "middle_name, last_name, account_is_active, user_type, sticky) "
            . "VALUES (:u, :p, :t, :e, :fn, :mn, :ln, 1, 2, 0)",
            array(
                ':u'  => $adminUser,
                ':p'  => password_hash($adminPass, PASSWORD_DEFAULT),
                ':t'  => $now,
                ':e'  => $adminEmail,
                ':fn' => $adminFirst,
                ':mn' => '',
                ':ln' => $adminLast,
            )
        );

        $userId = scalar($db, 'SELECT id FROM moddb.Users WHERE username = :u', array(':u' => $adminUser));

        // mgr (acl_id 6) da acceso al panel de administración; usr (2) es el
        // rol base de cualquier usuario del portal.
        foreach (array(6, 2) as $aclId) {
            $db->execute(
                "INSERT INTO moddb.user_acls (user_id, acl_id) VALUES (:uid, :acl)",
                array(':uid' => $userId, ':acl' => $aclId)
            );
        }

        step("usuario $adminUser creado (id $userId, roles mgr + usr)");
    }

    if ($force) {
        file_put_contents(DONE_MARKER, date('c') . "\n");
    }

    step('bootstrap completo');
    exit(0);
} catch (Exception $e) {
    do {
        fwrite(STDERR, $e->getMessage() . "\n");
        fwrite(STDERR, $e->getTraceAsString() . "\n");
    } while ($e = $e->getPrevious());
    exit(1);
}
