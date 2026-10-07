#!/bin/bash
# ============================================================================
# Arranca o PCSX2 com o jogo da sessão, em ecrã inteiro.
#
# O agente (agente.py) passa tudo por variáveis de ambiente e volumes:
#
#   ISUVA_JOGO   o caminho do jogo dentro do contentor (/jogo/<nome>)
#   ISUVA_BIOS   o nome do ficheiro da BIOS, dentro de /bios
#   /cartoes     os cartões de memória DESTE jogador -- é uma pasta do nó
#                por utilizador, por isso o que se grava fica para a
#                próxima sessão (no mesmo nó)
#
# A configuração do PCSX2 é escrita de raiz a cada arranque, a partir do
# modelo: o contentor é descartável, e um PCSX2.ini antigo com o assistente
# de primeira utilização por fazer prendia o jogo atrás de um diálogo que
# ninguém consegue fechar.
# ============================================================================
set -euo pipefail

CONF="${HOME}/.config/PCSX2/inis"
mkdir -p "${CONF}"
sed -e "s|@BIOS@|${ISUVA_BIOS:-}|g" /etc/isuva/PCSX2.ini.modelo > "${CONF}/PCSX2.ini"

# o ambiente de trabalho ainda está a assentar; o PCSX2 em ecrã inteiro
# antes disso às vezes abre atrás do painel
sleep 3

# vglrun: o OpenGL vai à placa NVIDIA (por EGL) em vez de ao processador.
# Sem ele, o PCSX2 desenhava em software e não passava dos 10 fps.
exec vglrun -d "${VGL_DISPLAY:-egl}" /opt/pcsx2/AppRun -fullscreen -nogui -batch -- "${ISUVA_JOGO}"
