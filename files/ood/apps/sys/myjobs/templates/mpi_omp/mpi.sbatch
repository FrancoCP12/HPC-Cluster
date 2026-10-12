#!/bin/bash
# ============================================================================
#  MPI (OpenMPI) - trabajo batch
#  Edita SOLO los valores marcados con "←" de la parte superior: nodos,
#  ranks, cores, duracion, programa y argumentos. Despues pulsa "Lanzar".
#  El resto del script ya funciona solo: carga OpenMPI del sistema, arregla el
#  entorno que OOD limpia (--export=NONE) y lanza mpirun con tantos ranks como
#  diga --ntasks (via SLURM_NTASKS, no hay que repetir el numero aqui abajo).
# ============================================================================

#SBATCH --job-name=mpi_omp
#SBATCH --partition=general
#SBATCH -N 2                  # ← NODOS (cuantos nodos de computo)
#SBATCH --ntasks=2            # ← RANKS totales (1 proceso MPI por core)
#SBATCH --cpus-per-task=1     # ← CORES por rank
#SBATCH -t 01:00:00           # ← duracion maxima (HH:MM:SS)
#SBATCH --mem=1024M           # ← memoria total estimada

# GPU: cuando el nodo tenga GPU, descomenta la linea y pedi la cantidad.
#   #SBATCH --gres=gpu:1
# (La apptainer que corra en la GPU necesita ademas "apptainer exec --nv ...")

# ============================ PROGRAMA =====================================
PROGRAM="/shared/hello"    # ← tu binario MPI (en el filesystem compartido)
ARGS=""                    # ← argumentos para el binario (o dejalo vacio)
# ============================================================================

set -euo pipefail

# Cargar OpenMPI del sistema aunque OOD haya limpiado el entorno (--export=NONE).
# En Ubuntu el lanzador vive en /usr/bin (mpirun.openmpi) y las libs en
# /usr/lib/x86_64-linux-gnu/openmpi/lib; con el entorno limpio ni PATH ni
# LD_LIBRARY_PATH traen esto, asi que se exporta explicito.
OMPI_LIB="/usr/lib/x86_64-linux-gnu/openmpi/lib"
export PATH="/usr/bin:/bin:${PATH}"
export LD_LIBRARY_PATH="${OMPI_LIB}${LD_LIBRARY_PATH:+:${LD_LIBRARY_PATH}}"

# Fix OOD -> PRRTE: si OOD lanzo el job con SLURM_EXPORT_ENV=NONE, mpirun y
# prted quedan sin PATH y mueren con "/usr/bin/env: 'bash': No such file or
# directory". NO BORRAR.
unset SLURM_EXPORT_ENV

echo "=== Trabajo en $(hostname) (job ${SLURM_JOB_ID})"
scontrol show job "${SLURM_JOB_ID}" | grep -E "JobName|NumNodes|NumTasks|AllocTRES" || true

echo "=== Lanzando ${SLURM_NTASKS} ranks de: ${PROGRAM} ${ARGS}"
mpirun -np "${SLURM_NTASKS}" "${PROGRAM}" ${ARGS}