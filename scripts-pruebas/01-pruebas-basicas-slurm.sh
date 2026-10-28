#!/bin/bash
# Pruebas básicas de Slurm
echo "=== PRUEBAS BÁSICAS SLURM ==="
sinfo
echo "---"
scontrol show nodes
echo "---"
scontrol show partitions
echo "---"
squeue
echo "---"
scontrol ping
