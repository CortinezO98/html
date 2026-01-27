#!/bin/bash
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
php "$DIR/gestion_alertas_notificacion_robot.php"
