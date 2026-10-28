#!/bin/bash
# Pruebas MPI/OpenMPI
echo "=== PRUEBAS MPI ==="
which mpicc mpirun
mpicc --version | head -1
mpirun --version | head -1
echo "---"
cd /shared/tests
cat > hello_mpi_test.c << 'C'
#include <mpi.h>
#include <stdio.h>
int main(int argc, char** argv) {
    MPI_Init(NULL, NULL);
    int world_size, world_rank;
    MPI_Comm_size(MPI_COMM_WORLD, &world_size);
    MPI_Comm_rank(MPI_COMM_WORLD, &world_rank);
    char processor_name[MPI_MAX_PROCESSOR_NAME];
    int name_len;
    MPI_Get_processor_name(processor_name, &name_len);
    printf("Hello from %s, rank %d/%d\n", processor_name, world_rank, world_size);
    MPI_Finalize();
    return 0;
}
C
mpicc hello_mpi_test.c -o hello_mpi_test
srun -N1 -n2 mpirun -np 2 ./hello_mpi_test
