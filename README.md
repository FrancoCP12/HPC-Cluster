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
   - [Variables y secretos (Ansible Vault)](#2-variables-y-secretos-ansible-vault)
   - [Paquetes Slurm locales (.deb)](#3-paquetes-slurm-locales-deb)
6. [Ejecución del playbook](#ejecución-del-playbook)
7. [Qué hace cada play](#qué-hace-cada-play)
8. [Validación post-despliegue](#validación-post-despliegue)
9. [Detección de GPU](#detección-de-gpu)
10. [Seguridad](#seguridad)
11. [Solución de problemas](#solución-de-problemas)
12. [Soporte y contribución](#soporte-y-contribución)

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
- ✔️ **CERO hardcoding**: todas las credenciales/nombres externalizados en `group_vars/all.yml` (secreto con Ansible Vault) y `no_log` en las tareas sensibles.
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

# (Opcional) encriptación de secretos
ansible-vault --version
```

### Nodos del clúster

| Requisito | Valor |
|---|---|
| SO | Ubuntu 24.04/22.04 o Debian 12 (compatible con los `.deb` compilados) |
| Arquitectura | `amd64` |
| Recursos | CPU × N, RAM según carga, GPUs NVIDIA/AMD (opcionales) |
| Red | Toda la subred nodos accesible; puertos 6817/6818/6819/3306/2049 abiertos |

- Los paquetes `.deb` de Slurm deben estar **compilados para la misma distro** de los nodos (ver [sección paquetes](#3-paquetes-slurm-locales-deb)).

---

## Estructura del repositorio

```
Slurm-Ansible/
├── ansible.cfg              # Configuración de Ansible (inventory, callback yaml)
├── inventory.ini            # Inventario: master / database / compute
├── playbook.yml             # Playbook principal (todo el despliegue)
├── group_vars/
│   └── all.yml              # Variables del clúster + secretos (Vault)
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

### 2. Variables y secretos (Ansible Vault)

Crea los secretos de forma segura:

```bash
# Genera un valor cifrado para la contraseña de la BD
ansible-vault encrypt_string 'Tu-Super-Clave-123' --name slurm_db_password
```

Copia el bloque generado en `group_vars/all.yml`:

```yaml
slurm_db_password: !vault |
          $ANSIBLE_VAULT;1.1;AES256
          ...
```

Variables disponibles (todas en `group_vars/all.yml`):

| Variable | Descripción | Ejemplo |
|---|---|---|
| `slurm_cluster_name` | Nombre del clúster (Slurm + sacctmgr) | `clusterUTN` |
| `slurm_version` | Versión de los `.deb` desplegados | `24.11.7` |
| `slurm_user` / `slurm_group` | Usuario/grupo de servicio | `slurm` |
| `slurm_uid` / `slurm_gid` | UID/GID unificados entre nodos | `1100` |
| `slurm_db_host` | Host de la base (resuelto del grupo `[database]`) | automático |
| `slurm_db_user` | Usuario MySQL de Slurm | `slurm` |
| `slurm_db_password` | **Secreto** MySQL (Vault) | — |
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

# 3. Ejecución completa (con Vault)
ansible-playbook playbook.yml

#   Si usaste ansible-vault:
ansible-playbook playbook.yml --ask-vault-pass

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

## Seguridad

- **Secretos en Vault**: la contraseña de MySQL nunca viaja en claro; usar `ansible-vault encrypt_string`.
- **`no_log: true`** en tareas que manejan credenciales (MySQL, `slurmdbd.conf`).
- **`munge.key`** con permisos `0400` y distribuido solo entre nodos del clúster.
- **`slurmdbd.conf`** con permisos `0600`.
- **Firewall**: restringe 6817/6818/6819/3306/2049 a la subred del clúster.
- **Sticky bit `1777`** en `/shared` para evitar sobrescrituras entre usuarios.
- **No commitees** los siguientes archivos: `group_vars/all.yml` con secretos reales, claves SSH, `.deb` gigantes innecesarios, o el contenido de `paquetes-compilados/` si no lo necesitas.

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

---

## Soporte y contribución

- Reporta incidencias en la pestaña **Issues** de este repositorio de GitHub.
- Envía mejoras mediante **Pull Requests** (mantén idempotencia y sin secretos).
- Documentación oficial: [Slurm Quick Start](https://slurm.schedmd.com/quickstart.html), [slurm.conf](https://slurm.schedmd.com/slurm.conf.html), [gres.conf](https://slurm.schedmd.com/gres.conf.html), [Apptainer](https://apptainer.org/docs/).

---

## Licencia

MIT — úsalo, modifícalo y adáptalo libremente para tus clusters de cómputo.
