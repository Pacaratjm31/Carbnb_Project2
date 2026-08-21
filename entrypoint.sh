#!/bin/bash

set -e

echo "=== Fixing Apache MPM configuration ==="

a2dismod mpm_event 2>/dev/null || true
a2dismod mpm_worker 2>/dev/null || true
a2dismod mpm_itk 2>/dev/null || true

rm -f /etc/apache2/mods-enabled/mpm_event.*
rm -f /etc/apache2/mods-enabled/mpm_worker.*
rm -f /etc/apache2/mods-enabled/mpm_itk.*

a2enmod mpm_prefork

echo "=== Enabled MPM modules ==="
ls -la /etc/apache2/mods-enabled/mpm*

echo "=== Apache config test ==="
apache2ctl -t

echo "=== Starting Apache ==="
exec apache2-foreground