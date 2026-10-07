# ============================================================================
# A imagem de uma sessão de PS2: PCSX2 + Selkies (streaming WebRTC).
#
#   docker build -t isuva-ps2:latest no-de-jogo/imagem-ps2
#
# PORQUE É QUE PARTE DO nvidia-egl-desktop
#
# É a imagem oficial do projecto Selkies: traz um ecrã virtual (Xvfb), o
# VirtualGL (que faz o OpenGL correr na placa NVIDIA mesmo sem monitor), o
# Selkies já montado com codificação de vídeo na placa (NVENC) e o
# comando virtual que recebe o gamepad do browser. Montar isto à mão são
# dezenas de passos frágeis; aqui só se acrescenta o PCSX2 e o arranque.
#
# A imagem abre um ambiente de trabalho (KDE) e o PCSX2 arranca por cima,
# em ecrã inteiro, pelo autostart do próprio ambiente (isuva-pcsx2.desktop).
#
# VERSÕES: as duas ARG abaixo estão fixas de propósito -- uma actualização
# do PCSX2 ou do Selkies muda o comportamento, e isso deve ser uma decisão,
# não uma surpresa num rebuild. Confirmar os nomes em:
#   https://github.com/PCSX2/pcsx2/releases
#   https://github.com/selkies-project/docker-nvidia-egl-desktop/pkgs/container/nvidia-egl-desktop
# ============================================================================

ARG BASE=ghcr.io/selkies-project/nvidia-egl-desktop:24.04
FROM ${BASE}

ARG PCSX2_VERSAO=v2.2.0

USER root

# O PCSX2 vem em AppImage; extrai-se, porque dentro de um contentor não
# há FUSE para o montar.
RUN apt-get update \
 && apt-get install -y --no-install-recommends ca-certificates wget \
 && wget -q -O /tmp/pcsx2.AppImage \
      "https://github.com/PCSX2/pcsx2/releases/download/${PCSX2_VERSAO}/pcsx2-${PCSX2_VERSAO}-linux-appimage-x64-Qt.AppImage" \
 && chmod +x /tmp/pcsx2.AppImage \
 && cd /opt && /tmp/pcsx2.AppImage --appimage-extract >/dev/null \
 && mv /opt/squashfs-root /opt/pcsx2 \
 && rm -f /tmp/pcsx2.AppImage \
 && rm -rf /var/lib/apt/lists/*

COPY arrancar-pcsx2.sh /usr/local/bin/arrancar-pcsx2.sh
COPY PCSX2.ini.modelo /etc/isuva/PCSX2.ini.modelo
COPY isuva-pcsx2.desktop /etc/xdg/autostart/isuva-pcsx2.desktop
RUN chmod 755 /usr/local/bin/arrancar-pcsx2.sh

USER 1000
