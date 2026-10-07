#!/usr/bin/env python3
"""
GERA A CONFIGURAÇÃO DO NGINX DE UM NÓ DE JOGO.

    python3 gerar-nginx.py config.json --dominio no1.exemplo.ao \\
        --site https://exemplo.ao --porta-base 8443 --porta-api 7443 \\
        > /etc/nginx/conf.d/isuva.conf

O que sai daqui:

    porta-api (7443)          a API do agente, para o SITE (pedidos assinados)
    porta-base + N (8443...)  o lugar N, para o BROWSER do jogador

PORQUÊ UMA PORTA POR LUGAR

O cliente web do Selkies liga-se a /webrtc/signalling/ na raiz do
servidor. Pôr os lugares em caminhos (/l/0/, /l/1/) obrigava a mexer no
Selkies; uma porta por lugar dá a cada um a sua raiz. O número de lugares
é fixo (a capacidade), por isso esta configuração também é fixa: gera-se
uma vez, e só se volta a gerar quando a capacidade mudar.

O QUE CADA LUGAR VERIFICA

Todo o pedido a um lugar passa por um auth_request ao agente (/auth),
que só deixa passar quem tem o cookie desse lugar -- e esse cookie só se
ganha no /entrar, com o token que o site deu ao jogador daquela sessão.
Quem adivinhar a porta de um lugar não vê o jogo de ninguém.

O cabeçalho X-Slot é posto AQUI, pelo nginx, e por cima do que o browser
mande; na porta da API vai vazio. É isso que impede alguém de se fazer
passar por outro lugar.
"""

import argparse
import json
import re
import sys


def main():
    p = argparse.ArgumentParser(description="Gera o nginx de um nó de jogo do isuva.")
    p.add_argument("config", help="o config.json do agente")
    p.add_argument("--dominio", required=True, help="o nome do nó, ex.: no1.exemplo.ao")
    p.add_argument("--site", required=True, help="o endereço do site, ex.: https://exemplo.ao (o único que pode embeber o jogo)")
    p.add_argument("--porta-base", type=int, default=8443, help="a porta do lugar 0 (a porta_no do painel)")
    p.add_argument("--porta-api", type=int, default=7443, help="a porta da API, para o site")
    p.add_argument("--cert", help="certificado (por omissão, o do Let's Encrypt para o domínio)")
    p.add_argument("--chave", help="chave do certificado")
    a = p.parse_args()

    with open(a.config, encoding="utf-8") as f:
        cfg = json.load(f)

    capacidade = int(cfg.get("capacidade", 1))
    porta_agente = int(cfg.get("porta_api", 7000))
    porta_interna = int(cfg.get("porta_interna", 9000))

    # estes valores vão parar dentro da configuração do nginx: só o que
    # tem cara de domínio e de endereço, para não se colar lá outra coisa
    if not re.fullmatch(r"[A-Za-z0-9.\-]+", a.dominio):
        sys.exit("--dominio inválido")
    if not re.fullmatch(r"https://[A-Za-z0-9.\-]+(:\d+)?", a.site.rstrip("/")):
        sys.exit("--site tem de ser https://dominio")

    cert = a.cert or "/etc/letsencrypt/live/%s/fullchain.pem" % a.dominio
    chave = a.chave or "/etc/letsencrypt/live/%s/privkey.pem" % a.dominio
    site = a.site.rstrip("/")

    out = []
    out.append("""# Gerado por no-de-jogo/gerar-nginx.py -- não editar à mão: gerar outra vez.
# %d lugar(es), portas %d a %d, API na %d.

map $http_upgrade $isuva_ligacao {
    default upgrade;
    ''      close;
}

# A API do agente, para o site. Os pedidos vêm assinados (o agente verifica).
server {
    listen %d ssl;
    server_name %s;
    ssl_certificate     %s;
    ssl_certificate_key %s;

    location / {
        proxy_pass http://127.0.0.1:%d;
        # vazio de propósito: /entrar e /auth não são para esta porta
        proxy_set_header X-Slot "";
        proxy_set_header Host $host;
        client_max_body_size 1m;
    }
}
""" % (capacidade, a.porta_base, a.porta_base + capacidade - 1, a.porta_api,
       a.porta_api, a.dominio, cert, chave, porta_agente))

    for n in range(capacidade):
        out.append("""
# ---- lugar %(n)d ----
server {
    listen %(porta)d ssl;
    server_name %(dominio)s;
    ssl_certificate     %(cert)s;
    ssl_certificate_key %(chave)s;

    # só a página do site pode pôr o jogo num <iframe>
    add_header Content-Security-Policy "frame-ancestors %(site)s" always;

    location = /entrar {
        proxy_pass http://127.0.0.1:%(agente)d;
        proxy_set_header X-Slot %(n)d;
    }

    location = /_isuva_auth {
        internal;
        proxy_pass http://127.0.0.1:%(agente)d/auth;
        proxy_pass_request_body off;
        proxy_set_header Content-Length "";
        proxy_set_header X-Slot %(n)d;
    }

    location / {
        auth_request /_isuva_auth;
        error_page 401 = @negado;

        proxy_pass http://127.0.0.1:%(interna)d;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $isuva_ligacao;
        proxy_set_header Host $host;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
        proxy_buffering off;
        # o Selkies pode mandar o seu próprio X-Frame-Options; quem manda é o CSP acima
        proxy_hide_header X-Frame-Options;
    }

    location @negado {
        default_type text/plain;
        return 403 "Sessao invalida ou terminada. Volte ao site.";
    }
}
""" % {"n": n, "porta": a.porta_base + n, "dominio": a.dominio, "cert": cert, "chave": chave,
       "site": site, "agente": porta_agente, "interna": porta_interna + n})

    sys.stdout.write("".join(out))


if __name__ == "__main__":
    main()
