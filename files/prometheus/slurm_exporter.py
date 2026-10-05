#!/usr/bin/env python3
"""
Convierte la API REST de slurmrestd en metricas para Prometheus.

Por que este script y no /slurm/v1/metrics: el build de Slurm 24.11.7 que hay
compilado en packages/ no incluye el endpoint OpenMetrics. En el arbol de
fuentes no hay ninguna cadena "metrics" bajo src/slurmrestd/, asi que no hay
nada que scrapear ahi. La API REST /slurm/v0.0.42 si existe y es la que usa
este script.

Como se autenticа: por socket UNIX con el plugin rest_auth/local. Slurm 24.11
solo trae rest_auth/local y rest_auth/jwt (no hay auth/basic), asi que por TCP
habria que emitir JWT. El socket UNIX evita por completo manejar claves JWT y
ademas slurmrestd queda sin exponer ningun puerto en la red.

El script escribe un archivo .prom y node_exporter lo publica via textfile
collector. Se escribe a un temporal y se renombra: si el scraper lee mientras
se esta escribiendo, ve un .prom truncado y Prometheus se queja de metricas
inconsentes.
"""

import http.client
import json
import os
import socket
import sys

SOCKET_PATH = os.environ.get("SLURMRESTD_SOCKET", "/run/slurmrestd/restd.sock")
OUTPUT_PATH = os.environ.get(
    "SLURM_METRICS_OUT", "/var/lib/node_exporter/slurm.prom"
)
API_VERSION = os.environ.get("SLURM_API_VERSION", "v0.0.42")
TIMEOUT = float(os.environ.get("SLURM_API_TIMEOUT", "10"))


class UnixHTTPConnection(http.client.HTTPConnection):
    """HTTPConnection que conecta contra un socket UNIX en lugar de TCP.

    Es la forma soportada de hablar con slurmrestd por socket: urllib no trae
    ningun transporte UNIX, y http.client solo necesita que se cambie el
    connect().
    """

    def __init__(self, socket_path, timeout=TIMEOUT):
        # El host es ficticio: la conexion real no pasa por DNS ni por TCP.
        super().__init__("localhost", timeout=timeout)
        self.socket_path = socket_path

    def connect(self):
        self.sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.sock.settimeout(self.timeout)
        self.sock.connect(self.socket_path)


def query(path):
    """GET sobre el socket UNIX de slurmrestd."""
    conn = UnixHTTPConnection(SOCKET_PATH, timeout=TIMEOUT)
    try:
        conn.request("GET", "/slurm/%s/%s" % (API_VERSION, path))
        resp = conn.getresponse()
        body = resp.read().decode("utf-8")
        if resp.status != 200:
            raise OSError(
                "slurmrestd devolvio HTTP %s en /%s: %s"
                % (resp.status, path, body[:200])
            )
        return json.loads(body)
    finally:
        conn.close()


def esc(value):
    """Escapa un valor de label para el formato de exposicion de Prometheus."""
    return str(value).replace("\\", "\\\\").replace('"', '\\"').replace("\n", "")


def node_states(raw):
    """Normaliza el campo state de la API a una lista de estados.

    Slurm lo devuelve como CADENA ya formateada: "['IDLE', 'IDLE+POWER']" o
    "['DOWN*']". Mandarlo crudo a Prometheus produce el label state="['IDLE']",
    con corchetes y comillas adentro, que es exactamente lo que se vio en el
    .prom. Se parsea la lista y se devuelven las partes sin el sufijo de causa.
    """
    if isinstance(raw, list):
        parts = [str(x) for x in raw]
    else:
        parts = str(raw or "").strip().strip("[]").split(",")

    cleaned = []
    for part in parts:
        part = part.strip().strip("'\"")
        # IDLE+POWER -> IDLE ; DOWN* -> DOWN. El sufijo va aparte.
        cleaned.append(part.split("+")[0].split("*")[0].strip().upper())

    # IDLE+POWER produce ["IDLE", "IDLE"]: la misma raiz repetida, que en
    # Prometheus seria la misma serie escrita dos veces y un error de scrape.
    unique = []
    for state in cleaned:
        if state and state not in unique:
            unique.append(state)

    return unique or ["UNKNOWN"]


def node_total_cpus(node):
    """CPUs totales del nodo. El campo "cpus" es un dict {set,infinite,number}."""
    total = unwrap_number(node.get("cpus"))
    return total if total is not None else 0


def alloc_cpus(node):
    """CPUs asignados al nodo.

    El endpoint de nodos ya trae "alloc_cpus" plano, asi que se usa ese. Solo
    como respaldo se mira "cpu_alloc" (lista de indices de CPU, p.ej. [0] si
    la CPU 0 esta ocupada), que en este build de la API no viene.
    """
    allocated = unwrap_number(node.get("alloc_cpus"))
    if allocated is not None:
        return allocated

    idx = node.get("cpu_alloc") or []
    if isinstance(idx, list):
        return len(idx)
    return node_total_cpus(node) if idx else 0


def unwrap_number(value):
    """Slurm devuelve los numeros como {set, infinite, number}.

    Un entero plano (o un string numerico) tambien se acepta, y "infinite"
    devuelve None porque no se puede graficar.
    """
    if value is None:
        return None
    if isinstance(value, dict):
        if value.get("infinite"):
            return None
        number = value.get("number")
        return None if number is None else int(number)
    try:
        return int(value)
    except (TypeError, ValueError):
        return None


def job_states(raw):
    """Estados de un job, tomados de "job_state".

    Este build NO trae "state" en los jobs: el nombre real del campo es
    "job_state" y ya viene como lista de strings ("RUNNING", "CANCELLED"),
    no como bitfield entero. Por eso hay dos funciones distintas, una para
    nodos y otra para jobs.
    """
    if isinstance(raw, list):
        parts = [str(x) for x in raw]
    else:
        parts = str(raw or "").strip().strip("[]").split(",")

    unique = []
    for part in parts:
        part = part.strip().strip("'\"")
        # CANCELLED+TERM -> CANCELLED ; COMPLETING+CG -> COMPLETING. El
        # sufijo es el motivo, no un estado distinto.
        part = part.split("+")[0].strip().upper()
        if part and part not in unique:
            unique.append(part)

    return unique or ["UNKNOWN"]


JOB_STATES = [
    "PENDING", "RUNNING", "COMPLETED", "FAILED", "CANCELLED",
    "TIMEOUT", "NODE_FAIL", "PREEMPTED", "SUSPENDED", "REQUEUED",
    "RESIZING", "REVOKED", "SIGNALING", "STAGE_OUT", "COMPLETING",
]

DOWN_STATES = ("DOWN", "FAIL", "FAILURE", "ERROR", "POWER_DOWN", "NOT_RESPONDING")


def render(nodes, jobs, partitions):
    out = []
    out.append("# HELP slurm_nodes_total Nodes registrados en el cluster.")
    out.append("# TYPE slurm_nodes_total gauge")
    out.append("slurm_nodes_total %d" % len(nodes))

    out.append("# HELP slurm_nodes_alloc_cpus CPUs asignados por nodo.")
    out.append("# TYPE slurm_nodes_alloc_cpus gauge")
    out.append(
        "# HELP slurm_nodes_total_cpus CPUs totales por nodo.")
    out.append("# TYPE slurm_nodes_total_cpus gauge")
    out.append("# HELP slurm_nodes_state Estado del nodo (up=1 si no esta down).")
    out.append("# TYPE slurm_nodes_state gauge")

    for node in nodes:
        name = esc(node.get("name", "unknown"))
        states = node_states(node.get("state"))
        # Un nodo puede tener varios estados a la vez (IDLE+POWER); se emite una
        # serie por estado para poder filtrar en Grafana.
        for base in states:
            healthy = 0 if base in DOWN_STATES else 1
            out.append(
                'slurm_nodes_state{node="%s",state="%s"} %d'
                % (name, esc(base), healthy)
            )
        out.append(
            'slurm_nodes_alloc_cpus{node="%s"} %d' % (name, alloc_cpus(node))
        )
        out.append(
            'slurm_nodes_total_cpus{node="%s"} %d' % (name, node_total_cpus(node))
        )

    out.append("# HELP slurm_jobs_total Jobs conocidos por el controlador.")
    out.append("# TYPE slurm_jobs_total gauge")
    out.append("# HELP slurm_jobs_state Jobs por estado.")
    out.append("# TYPE slurm_jobs_state gauge")

    counts = {}
    for job in jobs:
        for state in job_states(job.get("job_state")):
            counts[state] = counts.get(state, 0) + 1

    out.append("slurm_jobs_total %d" % len(jobs))
    # Se emiten todos los estados conocidos para que el grafico tenga una
    # serie por estado aunque valga cero; si no, aparecen y desaparecen.
    for state in JOB_STATES:
        out.append(
            'slurm_jobs_state{state="%s"} %d' % (state, counts.get(state, 0))
        )
    for state in sorted(set(counts) - set(JOB_STATES)):
        out.append(
            'slurm_jobs_state{state="%s"} %d' % (state, counts[state])
        )

    out.append("# HELP slurm_partitions_total Particiones definidas.")
    out.append("# TYPE slurm_partitions_total gauge")
    out.append("slurm_partitions_total %d" % len(partitions))

    out.append("# HELP slurm_exporter_up El exporter pudo hablar con slurmrestd.")
    out.append("# TYPE slurm_exporter_up gauge")
    out.append("slurm_exporter_up 1")

    return "\n".join(out) + "\n"


def main():
    try:
        nodes = query("nodes").get("nodes", [])
        jobs = query("jobs").get("jobs", [])
        partitions = query("partitions").get("partitions", [])
    except (OSError, http.client.HTTPException, ValueError, KeyError) as exc:
        # No se borra el archivo anterior: Prometheus sigue mostrando el ultimo
        # valor conocido. Un exporter que borra el .prom al fallar hace que
        # los paneles queden vacios por un fallo momentaneo de slurmrestd.
        sys.stderr.write("no se pudo consultar slurmrestd: %s\n" % exc)
        if os.path.exists(OUTPUT_PATH):
            out = open(OUTPUT_PATH).read()
            if "slurm_exporter_up 1" in out:
                out = out.replace("slurm_exporter_up 1", "slurm_exporter_up 0")
                write_atomic(out)
        return 1

    write_atomic(render(nodes, jobs, partitions))
    return 0


def write_atomic(content):
    tmp = OUTPUT_PATH + ".tmp"
    with open(tmp, "w") as handle:
        handle.write(content)
        handle.flush()
        os.fsync(handle.fileno())
    os.rename(tmp, OUTPUT_PATH)


if __name__ == "__main__":
    sys.exit(main())