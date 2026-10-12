# Slurm-Ansible — Clúster HPC Slurm + GPU con Ansible

Despliegue **100% automatizado** de un clúster [Slurm Workload Manager](https://slurm.schedmd.com/) (23.x / 24.x) sobre **Ubuntu / Debian**, con soporte de **aceleradoras GPU (NVIDIA y AMD)**, **contabilidad SlurmDBD + MySQL**, **NFS** compartido y **Apptainer (Singularity)**.

> Playbook idempotente, sin secretos hardcodeados y listo para producción.

---

## Tabla de contenidos

1. [Arquitectura](#arquitectura)
2. [Características](#características)
3. [Requisitos](#requisitos)
4. [Estructura del repositorio](#estructura-del-repositorio)
5. [Configuración inicial](#configuración-inicial)
   - [Inventario](#1-inventario)
   - [Variables y secretos](#2-variables-y-secretos)
   - [Paquetes Slurm locales (.deb)](#3-paquetes-slurm-locales-deb)
6. [Ejecución del playbook](#ejecución-del-playbook)
7. [Qué hace cada play](#qué-hace-cada-play)
8. [Decisiones técnicas y compromisos asumidos](#decisiones-técnicas-y-compromisos-asumidos)
9. [Validación post-despliegue](#validación-post-despliegue)
10. [Detección de GPU](#detección-de-gpu)
11. [Seguridad](#seguridad)
12. [Solución de problemas](#solución-de-problemas)
13. [Soporte y contribución](#soporte-y-contribución)

---

## Arquitectura

```
                           ┌──────────────────────────────┐
                           │        NODO MAESTRO          │
                           │  slurmctld  (puerto 6817)    │
                           │  NFS Server  (exporta /shared)│
                           │  Apptainer                   │
                           └───────┬──────────┬───────────┘
                                   │          │
                         /etc/hosts│          │NFS /shared
              SlurmDBD (6819)      │          │
                                   ▼          ▼
        ┌──────────────────┐   ┌──────────────────────────────┐
        │ NODO DATABASE    │   │       NODOS DE CÓMPUTO       │
        │  MySQL (puerto   │   │  slurmd (puerto 6818)        │
        │  3306)           │   │  GPUs NVIDIA / AMD (GRES)    │
        │  slurmdbd        │   │  NFS client  (mount /shared) │
        │  (puerto 6819)   │   │  Apptainer                  │
        └──────────────────┘   └──────────────────────────────┘
```

| Rol | Grupo de inventario | Servicios | Puertos |
|---|---|---|---|
| Master | `master` | `slurmctld`, NFS server | 6817, 2049 (NFS) |
| Base de datos | `database` | `mysql`, `slurmdbd` | 3306, 6819 |
| Cómputo | `compute` | `slurmd` | 6818 |
| Todos | `all` | `munge`, `chrony` | 0 (unix socket munge) |

---

## Características

- ✔️ **Instalación por paquetes locales** `.deb` (Slurm SchedMD `slurm-smd-*`) con comodines, **sin versiones hardcodeadas** e idempotente.
- ✔️ **Detección remota de GPU** (100% en el worker): prioriza `nvidia-smi` / `rocm-smi`, luego nodos `/dev/nvidia*` / `/dev/kfd`, y por último clasificación PCI (`10de` = NVIDIA, `1002` = AMD).
- ✔️ **`gres.conf` dinámico** por nodo según vendor y cantidad detectada (NVIDIA: `AutoDetect=nvml`; AMD: `/dev/kfd` + `/dev/dri/renderD*`).
- ✔️ **Contabilidad completa**: MySQL + `slurmdbd` + registro idempotente del clúster con `sacctmgr`.
- ✔️ **Autenticación Munge** con clave compartida distribuida automáticamente.
- ✔️ **NFS compartido** (`/shared`) con mount persistente `_netdev` y sticky bit `1777`.
- ✔️ **Apptainer** instalado y configurado con bind de `/shared` automático.
- ✔️ **CERO hardcoding**: todas las credenciales/nombres externalizados en `group_vars/all.yml` y `no_log` en las tareas sensibles.
- ✔️ **Robustez operativa**: manejo de locks de APT (`fuser`, 300 s), espera activa de `slurmdbd` (`wait_for` 6819), persistencia de `/run/slurm` vía `systemd-tmpfiles`, y limpieza de estados DRAIN (`scontrol state=RESUME`).

---

## Requisitos

### Nodo de control

| Requisito | Valor |
|---|---|
| Linux + `ansible-core` | `>= 2.14` (uso de `systemd.masked`, etc.) |
| Colección `ansible.posix` | requerida por `ansible.posix.mount` |
| Acceso SSH | usuario con `sudo`/`become` en todos los nodos |
| Python | `>= 3.9` |

```bash
# Instalar la colección ansible.posix (una sola vez)
ansible-galaxy collection install ansible.posix
```

### Nodos del clúster

| Requisito | Valor |
|---|---|
| SO | Ubuntu 24.04/22.04 o Debian 12 |
| Arquitectura | `amd64` |
| Red | Subred accesible; puertos 6817/6818/6819/3306/2049 abiertos |

- Los paquetes `.deb` de Slurm deben estar compilados para la misma distro.

### Prerrequisitos manuales (bootstrap)

El playbook no crea cuentas SSH ni IPs. Antes de ejecutar `playbook.yml`:

| Elemento | Detalle |
|---|---|
| Usuarios SSH | `master` (slurmmaster), `work` (worker1), `work2` (worker2) con `sudo`. |
| IPs | `192.168.18.185` (slurmmaster/slurmdb/slurmod/slurmldap), `192.168.18.175` (worker1), `192.168.18.177` (worker2). |
| Secrets | En texto plano en `group_vars/all.yml` (ver sección 2). |

---

## Estructura del repositorio

```
Slurm-Ansible/
├── ansible.cfg              # Configuración de Ansible (inventory, callback yaml)
├── inventory.ini            # Inventario: master / database / compute
├── playbook.yml             # Playbook principal (todo el despliegue)
├── group_vars/
│   └── all.yml              # Variables del clúster + secretos
├── templates/
│   ├── slurm.conf.j2        # Configuración principal de Slurm (multi-nodo)
│   ├── slurmdbd.conf.j2     # Configuración de la base contable
│   ├── gres.conf.j2         # GRES / GPUs (dinámico según vendor detectado)
│   └── cgroup.conf          # Constrain de cores y RAM con cgroup v2
├── packages/                # Paquetes .deb locales de Slurm (ver sección 3)
└── paquetes-compilados/     # (Opcional) árbol fuente/build de Slurm 24.11.7
```

---

## Configuración inicial

### 1. Inventario

Edita `inventory.ini` con los datos reales de tus nodos:

```ini
[master]
slurmmaster ansible_host=192.168.18.176 ansible_user=user ansible_become_pass=password

[database]
slurmdata ansible_host=192.168.18.167 ansible_user=user ansible_become_pass=password

[compute]
worker1 ansible_host=192.168.18.175 ansible_user=user ansible_become_pass=password node_feature=standperf
worker2 ansible_host=192.168.18.177 ansible_user=user ansible_become_pass=password node_feature=highperf

[slurm_cluster:children]
master
database
compute

[all:vars]
ansible_become=true
```

> ⚠️ Las contraseñas SSH embebidas aquí se pueden reemplazar por **llaves SSH** y/o `ansible-vault` para mayor seguridad. La variable `node_feature` alimenta el campo `Feature` de cada nodo en `slurm.conf`.

### 2. Variables y secretos

Las credenciales están en **texto plano** en `group_vars/all.yml`. No hay cifrado: no se usa Ansible Vault ni ningún `.vault_pass`.

```yaml
# group_vars/all.yml
slurm_db_password: "..."
ldap_admin_password: "..."
```

Editá esos valores directamente y aplicá con un tag acotado para no tocar el resto:

```bash
ansible-playbook playbook.yml --tags ldap
```

> ⚠️ Esto implica que las contraseñas reales quedan en el historial de git. Si el remoto alguna vez fue público, o el repo se comparte, rotá las credenciales. Para un clúster de laboratorio con remoto privado es un compromiso razonable; para producción, `ansible-vault encrypt_string` sigue siendo la opción correcta y no requiere ningún cambio en el playbook.

Variables disponibles (todas en `group_vars/all.yml`):

| Variable | Descripción | Ejemplo |
|---|---|---|
| `slurm_cluster_name` | Nombre del clúster (Slurm + sacctmgr) | `clusterUTN` |
| `slurm_version` | Versión de los `.deb` desplegados | `24.11.7` |
| `slurm_user` / `slurm_group` | Usuario/grupo de servicio | `slurm` |
| `slurm_uid` / `slurm_gid` | UID/GID unificados entre nodos | `1100` |
| `slurm_db_host` | Host de la base (resuelto del grupo `[database]`) | automático |
| `slurm_db_user` | Usuario MySQL de Slurm | `slurm` |
| `slurm_db_password` | Contraseña MySQL de Slurm | — |
| `slurm_db_name` | Nombre de la base contable | `slurm_acct_db` |
| `slurm_db_port` | Puerto MySQL | `3306` |
| `slurmdbd_port` | Puerto de `slurmdbd` | `6819` |
| `slurm_shared_dir` | Directorio NFS compartido | `/shared` |
| `apptainer_bind_mount` | Bind path en Apptainer | `/shared` |
| `apt_lock_wait_timeout` | Timeout de locks de apt/dpkg (s) | `300` |
| `slurm_runtime_packages` | Dependencias runtime del `.deb` | lista apt |
| `slurm_packages_dir` | Directorio local de paquetes `.deb` | `packages` |

### 3. Paquetes Slurm locales (.deb)

El playbook instala los `.deb` desde `packages/` mediante **comodines** (nunca versiones fijas):

```text
packages/
├── slurm-smd_*.deb               # núcleo de Slurm
├── slurm-smd-client_*.deb        # herramientas cliente (scontrol, sacctmgr...)
├── slurm-smd-slurmctld_*.deb     # controlador (master)
├── slurm-smd-slurmd_*.deb        # agente de cómputo (workers)
└── slurm-smd-slurmdbd_*.deb      # daemon contable (database)
```

> Los `.deb` deben ser de la **misma versión y distro** que los nodos. Este repo incluye el árbol de build de Slurm **24.11.7** en `paquetes-compilados/` como referencia. Si usas otro SO, recompila (por ejemplo con `debuild`) y coloca los artefactos en `packages/`.

---

## Ejecución del playbook

```bash
# 1. Prueba de conectividad
ansible all -m ping

# 2. Chequeo de sintaxis
ansible-playbook --syntax-check playbook.yml

# 3. Ejecución completa
ansible-playbook playbook.yml

# 4. (Opcional) limitar el alcance
ansible-playbook playbook.yml --limit compute
ansible-playbook playbook.yml --tags ...        # playbooks no usan tags hoy
```

El playbook es **idempotente**: ejecutarlo dos veces no duplica instalaciones ni registros.

---

## Qué hace cada play

| # | Play | Acciones principales |
|---|---|---|
| 0 | Facts globales | Recolecta facts de `slurm_cluster` (necesarios para templates multi-nodo) |
| 1 | Infraestructura base | Locks de APT (`fuser`), enmascara `unattended-upgrades`/timers, instala deps runtime, **chrony** (enmascara `systemd-timesyncd` + `makestep`), `/etc/hosts` (todos los nodos), `systemd-tmpfiles` para `/run/slurm`, usuario/grupo `slurm`, **munge.key** generado y distribuido, `.deb` base + cliente |
| 2 | Database | MySQL (bind `0.0.0.0`), crea BD y usuario Slurm (`no_log`), instala `slurm-smd` + `slurmdbd`, despliega `slurmdbd.conf`, arranca `slurmdbd` |
| 3 | Master | Instala `slurmctld`, `wait_for` 6819, despliega `slurm.conf`, registra el clúster en `sacctmgr` (idempotente), arranca `slurmctld` |
| 4 | NFS | Server en master (`/shared` con sticky `1777` + export) y client en workers (`_netdev` en fstab) |
| 5 | Cómputo | Detección remota de GPU/vendor, instala `slurmd`, despliega `cgroup.conf`, `gres.conf` y `slurm.conf`, arranca `slurmd` |
| 6 | Apptainer | Llave GPG del PPA, repo `apptainer`, instala `apptainer`/`apptainer-suid`, bind de `/shared` |
| 7 | Reconciliación | `wait_for` 6819, regenera `slurm.conf` en master, `scontrol reconfigure`, y `state=RESUME reason=""` (limpieza de DRAIN) |
| — | LDAP | `slapd` con TLS (LDAPS), LDIF de árbol base, usuarios de prueba, SSSD en nodos |
| — | Open OnDemand | Repo OOD 4.2.4, Apache + PUN, Terminal nativa (Shell), apps interactivas (Jupyter, VS Code, MPI), Grafana con `auth.proxy` |
| — | Grafana | `auth.proxy` vía cabecera `X-WEBAUTH-USER`, dashboard de clúster |
| — | Prometheus | Node Exporter + Slurm exporter + timer de scrape |

> Los plays de OOD, Grafana y Prometheus están agrupados aquí por función; el orden real está en `playbook.yml` (`hosts:` de cada bloque).

---

## Decisiones técnicas y compromisos asumidos

Esta sección registra decisiones donde se eligió **no** arreglar algo, con el motivo
y la consecuencia aceptada. Está para que nadie "lo arregle" sin saber que fue
deliberado.

### Terminal Web interactiva: eliminada en favor del Shell nativo

**Decisión.** Se elimina la app interactiva `sys/desktop` (**Terminal Web**, una
terminal en el navegador con Xvnc + xterm + noVNC) y toda su pila de soporte:

- en la PUN: los paquetes `novnc` y `websockify` y el wrapper `/opt/websockify/run`;
- en los nodos de cómputo: `tigervnc-standalone-server`, `xterm`,
  `matchbox-window-manager` y `websockify` (play 12, que ahora los desinstala).

El portal queda con la **Terminal nativa** (`sys/shell`) por defecto.

**Por qué.** La Terminal Web se agregó cuando el Shell nativo no arrancaba (el
`MemoryDenyWriteExecute=yes` de Apache, ver la sección siguiente). Una vez
resuelto eso la app era redundante: dos formas de hacer lo mismo, y la segunda
arrastra cientos de MB de VNC en cada nodo.

**Consecuencia.** Desaparece la opción "Terminal Web" del menú **Interactive
Apps**; para una terminal se usa **Clusters → Shell Access** (la Terminal
nativa). El proxy `/node` y `/rnode` de `ood_portal.yml` **no se toca**: lo
siguen usando Jupyter, VS Code y MPI.

### Terminal nativa (Shell): `MemoryDenyWriteExecute=no` en Apache

**Decisión.** Se conserva la app `sys/shell` (la **Terminal** del portal) y se
desactiva `MemoryDenyWriteExecute` **solo** en `apache2.service`, con un drop-in:

```
/etc/systemd/system/apache2.service.d/zz-memory.conf
[Service]
MemoryDenyWriteExecute=no
```

**Por qué.** El Shell es una app Node; V8 necesita marcar páginas de memoria como
ejecutables (`mprotect`/`mmap` con `PROT_EXEC`) para su JIT. Ubuntu 26.04 endureció
`apache2.service` con `MemoryDenyWriteExecute=yes` (política seccomp W^X). Esa
restricción la **heredan todos los hijos de Apache**: la PUN que se arranca vía
`sudo nginx_stage` y el Node que ésta lanza. Así, V8 aborta en cuanto compila su
primer baseline:

```
# Fatal error in , line 0
# Check failed: 12 == (*__errno_location ())    # 12 = ENOMEM
  v8::base::OS::SetPermissions(...)
  v8::internal::GenerateBaselineCode(...)        # JIT
```

Por eso el mismo binario con el mismo Node corría **bien a mano** (fuera de
systemd no existe ese filtro) y **mal bajo Passenger** (que es hijo de Apache). No
era un problema de versión de Node: ni el Node 22 del sistema ni un Node LTS 20
aparte cambiaban nada. Apache no usa JIT, así que desactivar esa capa no le quita
nada que necesite. Es el mismo workaround que necesita RHEL 10 (allí lo trae
`httpd.service`).

**Consecuencia.** Se relaja una capa de hardening de Apache (W^X). Se acepta: sin
esto el Shell no arranca, y ningún otro proceso de Apache depende de esa
restricción.

**Verificar.**

```bash
systemctl show apache2 -p MemoryDenyWriteExecute   # -> no
# Al abrir Clusters -> Shell Access, el log del usuario NO debe mostrar el "Check failed: 12"
sudo tail -n 50 /var/log/ondemand-nginx/<usuario>/error.log
```

### Terminal nativa (Shell): `ssh_known_hosts` global

**Decisión.** Se publica `/etc/ssh/ssh_known_hosts` (propiedad `root:root`, modo
`0644`) con las claves de host de **todos** los nodos del clúster, recolectadas
con `ssh-keyscan` a las IPs (`ansible_host`), a los nombres del inventario y a
los hostnames reales de cómputo.

**Por qué.** La Terminal corre como **cada usuario LDAP** y su SSH no tiene un
`known_hosts` propio. Al hacer `ssh <nodo>` por primera vez, el usuario veía:

```
hostkeys_find_by_key_hostfile: hostkeys_foreach failed for /etc/ssh/ssh_known_hosts: Permission denied
The authenticity of host '192.168.18.191' can't be established.
```

El archivo **no existía**. Como el prompt de autenticidad bloquea a un
investigador y rompe scripts, se siembra un `known_hosts` **global** para todos
los usuarios sin tocar cada `$HOME`. Se ignoran las líneas de comentario que
emite `ssh-keyscan` y lo que no resuelva (nodos sin DNS) simplemente no aporta
claves.

**Verificar.**

```bash
ls -l /etc/ssh/ssh_known_hosts            # -> -rw-r--r-- root root
sudo -u <usuarioLDAP> -H ssh -o BatchMode=yes 192.168.18.191 true
# NO debe aparecer "Host key verification failed" ni el prompt de autenticidad;
# solo el normal "Permission denied (publickey,password)" si aún no hay clave.
```

---

## Validación post-despliegue

Ejecuta desde el master:

```bash
# Estado del clúster y partición
sinfo -N -o "%n %t %C %m %G %f"
sinfo -o "%P %a %l %N" --summary

# Nodos y GPUs detectados
scontrol show nodes

# Partición / cola de trabajos
squeue

# Contabilidad registrada
sacctmgr show clusters
sacctmgr show assoc

# Servicios
systemctl status slurmctld slurmd slurmdbd munge chrony nfs-server mysql
```

Prueba de humo (subir un trabajo):

```bash
srun -N 1 hostname
sbatch --wrap "echo 'Hola desde Slurm'; sleep 10" && watch -n1 squeue
```

Arranque manual de servicios en cada rol (si no los activó systemd):

```bash
systemctl start munge          # todos
systemctl start slurmdbd       # database
systemctl start slurmctld      # master
systemctl start slurmd         # compute
```

---

## Detección de GPU

El playbook ejecuta la detección **exclusivamente en cada worker remoto** (nunca en el nodo de control; no se usa `lookup('pipe')`), en este orden:

1. **Herramientas oficiales**: `nvidia-smi` (cuenta GPUs) → vendor `nvidia`; `rocm-smi` → vendor `amd`.
2. **Nodos de dispositivo**: `/dev/nvidia[0-9]*` → `nvidia`; `/dev/kfd` + `/dev/dri/renderD*` → `amd`.
3. **Clasificación por hardware** con `lspci -nn`: IDs `[10de:]` = NVIDIA y `[1002:]` = AMD (contando controllers `VGA | 3D controller | Display`).

Resultado → **facts tipados**:

| Fact | Tipo | Valores |
|---|---|---|
| `detected_gpus` | `int` | 0, 1, 2, … |
| `gpu_vendor` | `string` | `nvidia`, `amd`, `none` |

Con `detected_gpus > 0` se genera `gres.conf` (NVIDIA: `AutoDetect=nvml` con fallback comentado a `/dev/nvidia*`; AMD: `/dev/kfd` + un `renderD` por GPU). Con `0` GPUs el archivo se elimina y el nodo queda como CPU-only.

---

## MPI (OpenMPI) y jobs distribuidos

El playbook instala **OpenMPI 5** (`openmpi-bin` + `libopenmpi-dev`) en **todos** los nodos
(`mpi_packages` en `group_vars/all.yml`). Los programas MPI se compilan con `mpicc` y se
lanzan con **`mpirun` dentro de una asignación de Slurm** (`sbatch`/`salloc`). El OpenMPI de
Ubuntu viene compilado `--with-slurm` (trae `plm/slurm` + `ras/slurm`), así que `mpirun`
detecta la asignación, arranca sus daemons con `srun` y reparte los ranks en los nodos
asignados. **No hace falta SSH sin clave entre nodos.**

```bash
# 1. Compilar una vez, sobre el FS compartido (visible en todos los nodos)
mpicc -O2 hello.c -o /shared/hello

# 2. hello.sbatch  (se envía desde cualquier nodo con cliente Slurm)
#!/bin/bash
#SBATCH -N2            # 2 nodos
#SBATCH -n2            # 2 ranks
#SBATCH --chdir=/tmp   # OJO: el workdir debe EXISTIR en el nodo de cómputo (ver abajo)
mpirun -np 2 /shared/hello
```

```bash
sbatch hello.sbatch
```

Salida esperada (un rank por nodo):

```
rank 0/2 on work2
rank 1/2 on workf
```

### Por qué `mpirun` y no `srun`

`slurm.conf` deja `MpiDefault=none` **a propósito**. El build de Slurm **sí** trae los plugins
`mpi/pmix*` y `mpi/pmi2`, pero:

- `srun --mpi=pmix_v5`: con el `libpmix` de Ubuntu el server PMIx de `srun` se **cuelga**
  (`Socket timed out on send/recv operation`) y deja el nodo en `COMPLETING`.
- `srun --mpi=pmi2`: OpenMPI 5 **ya no** habla PMI-1/2.

Por eso el camino soportado es OpenMPI puro (`mpirun` + `plm/slurm`). `MpiDefault` queda en
`none` y no se usa `srun <app_mpi>` directo.

### Nota sobre el WorkDir (importante)

El directorio de trabajo del job (`--chdir`, o el dir desde el que se envía `sbatch`) tiene
que **existir en el nodo de cómputo**, porque `slurmd` abre ahí el `stdout` del job. Los homes
`/home/masterf`, `/home/workf`, `/home/work2` son **locales a cada nodo**, así que enviar un
job desde el master sin `--chdir` falla con:

```
error: Could not open stdout file /home/masterf/slurm-N.out: No such file or directory
_fork_all_tasks: IO setup failed: Slurmd could not connect IO   -> job muere con señal 53
```

Usá `--chdir=/tmp` / `--chdir=/shared/<dir>` o, para usuarios LDAP, el home compartido
`/export/home/<user>`.

### MPI desde Open OnDemand (app `MPI`)

El portal trae una app **Interactive Apps → MPI (OpenMPI)** (código en
`files/ood/apps/sys/mpi/`) que arma y envía el job MPI por vos. Elegís:

- **Nodos** (1..cantidad de nodos `compute`, por defecto 2),
- **Tasks por nodo** (por defecto 1; ver límite abajo),
- **Programa MPI** (ruta absoluta; por defecto `/usr/bin/hostname`, que con 2 ranks
  imprime el nombre de cada nodo — sirve para verificar el reparto sin compilar nada),
- **Argumentos** (opcional),
- y la duración.

La app no expone un servicio web: su `template/script.sh.erb` corre
`mpirun -np <nodos × tasks> <programa>` dentro del job. La salida del job queda en
`output.log` y **se muestra en la propia tarjeta** (`info.html.erb`, que OOD renderiza
como Markdown). Para el log completo, el **Session ID** de la tarjeta abre el directorio
de la sesión en `Files`.

Para correr un MPI propio, compilalo con `mpicc` sobre el FS compartido y poné esa ruta
en el formulario:

```bash
cat > /shared/hello.c <<'EOF'
#include <mpi.h>
#include <stdio.h>
int main(int argc, char **argv) {
  int rank, size; char name[MPI_MAX_PROCESSOR_NAME]; int len;
  MPI_Init(&argc, &argv);
  MPI_Comm_rank(MPI_COMM_WORLD, &rank);
  MPI_Comm_size(MPI_COMM_WORLD, &size);
  MPI_Get_processor_name(name, &len);
  printf("rank %d/%d on %s\n", rank, size, name);
  MPI_Finalize();
  return 0;
}
EOF
mpicc /shared/hello.c -o /shared/hello     # dejalo en /shared o en /export/home/<user>
```

Límite a tener en cuenta (el mismo que en las otras apps): `work2` tiene **1 CPU**, así
que `Tasks por nodo = 1`. Pedir más deja el job en `PENDING` para siempre
(`MinCPUsNode`). El máximo de nodos sale del grupo `compute` del inventario
(`mpi_num_nodes_max` en `group_vars/all.yml`).

> **Por qué `unset SLURM_EXPORT_ENV` en `template/script.sh.erb` (no lo borres).**
> OOD envía el job con `--export=NONE` (default de `ood_core`), así que el job hereda
> `SLURM_EXPORT_ENV=NONE`. El `srun` que `mpirun` (PRRTE, componente `plm:slurm`) lanza
> para arrancar el daemon `prted` hereda ese modo y lo ejecuta con un entorno **vacío**
> (sin `PATH`); `slurmstepd` hace `execve("prted")` y falla:
> `slurmstepd: error: execve(): prted: No such file or directory` → `srun-failed`
> (PRRTE sólo muestra *"You were supposed to get help about: srun-failed"*, sin la causa
> real). Haciendo `unset SLURM_EXPORT_ENV` el `srun` interno exporta el entorno del job
> (que **sí** trae `PATH`) al step y `prted` arranca. Sin ese `unset` la app compila y
> envía el job pero éste muere al instante.

---

## Seguridad

- **Secretos en texto plano**: `group_vars/all.yml` contiene contraseñas reales sin cifrar. Es una decisión consciente para este laboratorio; ver sección 2. El repo debe quedar en un remoto privado.
- **`no_log: true`** en tareas que manejan credenciales (MySQL, `slurmdbd.conf`).
- **`munge.key`** con permisos `0400` y distribuido solo entre nodos del clúster.
- **`slurmdbd.conf`** con permisos `0600`.
- **Firewall**: restringe 6817/6818/6819/3306/2049 a la subred del clúster.
- **Sticky bit `1777`** en `/shared` para evitar sobrescrituras entre usuarios.
- **No commitees**: claves SSH, `.deb` gigantes innecesarios, o el contenido de `paquetes-compilados/` si no lo necesitas. (`group_vars/all.yml` sí se commitea con secretos en claro por decisión del proyecto.)

---

## Solución de problemas

| Síntoma | Causa probable | Solución |
|---|---|---|
| `apt: rc=100` o `Failed to lock /var/lib/dpkg/lock-frontend` | `unattended-upgrades`/apt en background | El play reinicia timers; a la próxima ejecución enmascara y espera locks (300 s). Puedes correr `systemctl stop apt-daily.timer apt-daily-upgrade.timer` manualmente. |
| `sacctmgr: error ... already exists. Not adding` / exit 1 | El clúster ya estaba registrado | Ya resuelto: el play hace `sacctmgr show cluster` previo y tolera `already exists` sin abortar. |
| `slurmctld` no arranca: *Association problems* | Clúster no registrado en la BD | El play registra con `sacctmgr -i add cluster` **antes** de iniciar `slurmctld`. Verificar `sacctmgr show clusters`. |
| Nodo en `DRAIN` con `Low RealMemory` / *reboot* | HW cambiado / reinicio sucio | El play final ejecuta `scontrol update ... state=RESUME reason=""`. Manual: `scontrol update nodename=<nodo> state=resume reason=""`. |
| `error: unable to stat /run/slurm` tras reinicio | `/run` es tmpfs | Garantizado por `/etc/tmpfiles.d/slurm.conf`. Verifica con `systemd-tmpfiles --create --prefix=/run/slurm`. |
| `gres.conf`: `unsupported operand type(s) for -: 'AnsibleUnsafeText' and 'int'` | Expresión Jinja evaluada en línea comentada | Ya corregido: comentarios `{# ... #}` nativos de Jinja + casteo `(detected_gpus | int) - 1`. |
| GPU detectadas pero `sinfo` muestra `gres=(null)` | `slurmctld` sin reconfigurar | `scontrol reconfigure` (el play lo hace; manual: reintentar). |
| Mount NFS cuelga el boot | NFS no disponible al arrancar | `_netdev` en fstab ya añadido; verificar orden de arranque con `systemctl status nfs-client.target`. |
| App MPI en OOD: job `Completed`/`FAILED` al instante con *"Sorry! You were supposed to get help about: srun-failed"* | OOD envía con `--export=NONE` → `SLURM_EXPORT_ENV=NONE` → el `srun` interno de PRRTE lanza `prted` con entorno vacío | Ya corregido en `template/script.sh.erb` con `unset SLURM_EXPORT_ENV`. Para ver la causa real (`execve(): prted: No such file or directory`) correr `mpirun` con `OMPI_MCA_plm_base_verbose=100`. |

---

## Soporte y contribución

- Reporta incidencias en la pestaña **Issues** de este repositorio de GitHub.
- Envía mejoras mediante **Pull Requests** (mantén idempotencia y sin secretos).
- Documentación oficial: [Slurm Quick Start](https://slurm.schedmd.com/quickstart.html), [slurm.conf](https://slurm.schedmd.com/slurm.conf.html), [gres.conf](https://slurm.schedmd.com/gres.conf.html), [Apptainer](https://apptainer.org/docs/).

---

## Licencia

MIT — úsalo, modifícalo y adáptalo libremente para tus clusters de cómputo.
