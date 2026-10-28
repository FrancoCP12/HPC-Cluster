#!/bin/bash
# Pruebas de stress
echo "=== PRUEBAS DE STRESS ==="
# 5 jobs simultáneos
for i in 1 2 3 4 5; do
  sbatch --wrap="hostname; sleep 30" --output=/tmp/stress_$i.out
done
sleep 5
squeue
sleep 35
squeue
echo "Completados"
