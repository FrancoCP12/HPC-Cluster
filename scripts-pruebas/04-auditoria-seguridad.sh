#!/bin/bash
# Auditoría de seguridad
echo "=== AUDITORÍA DE SEGURIDAD ==="
echo "Permisos críticos:"
stat -c '%a %u:%g %n' /etc/munge/munge.key /etc/slurm/slurmdbd.conf /etc/sssd/sssd.conf
echo "---"
echo "Servicios expuestos:"
ss -tlnp | grep LISTEN
echo "---"
echo "NFS exports:"
cat /etc/exports
echo "---"
echo "Usuarios LDAP:"
getent passwd | grep -E 'admin|alumno'
