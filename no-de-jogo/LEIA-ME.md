# no-de-jogo/

O que corre na **máquina com placa gráfica** para a PS2 funcionar no
site. O site (PHP) não corre aqui, e este código não corre no site.

```
browser do jogador ──https──▶ site (PHP)        catálogo, fila, quem pode jogar
        │                        │
        │                        │ pedidos assinados (HMAC)
        │                        ▼
        └──https/WebRTC──▶ nó de jogo
                           ├─ nginx            HTTPS, uma porta por lugar, verifica o cookie
                           ├─ agente.py        recebe sessões, copia o jogo, arranca contentores
                           ├─ coturn           ponte do vídeo (WebRTC) quando há NAT pelo meio
                           └─ docker: isuva-ps2 (um por jogador)
                                 PCSX2 + Selkies (vídeo NVENC, teclado, comando)
```

| Ficheiro | O quê |
|---|---|
| `agente.py` | o agente (só biblioteca padrão do Python) |
| `config.exemplo.json` | a configuração; copiar para `/etc/isuva/config.json` |
| `gerar-nginx.py` | gera o nginx: a API e uma porta por lugar |
| `imagem-ps2/` | a imagem Docker de uma sessão (PCSX2 + Selkies) |
| `isuva-agente.service` | o agente como serviço do systemd |

## O que já foi testado e o que falta

**Testado de ponta a ponta,** com o agente em `"motor": "simulado"` (sem
GPU) e um nginx real à frente:

- a fila e os lugares;
- a cópia do jogo e da BIOS do site para a cache;
- a entrada por token e o cookie de cada lugar (um jogador não entra no
  lugar de outro);
- o lugar libertado quando a página fecha;
- as assinaturas;
- o `systemctl stop` a fechar as consolas.

**Ainda não testado numa máquina com GPU:** a imagem `imagem-ps2/`
(PCSX2 + Selkies). É o primeiro passo no servidor a sério, e os pontos que
mais provavelmente vão precisar de afinação são:

1. **Os comandos** (`imagem-ps2/PCSX2.ini.modelo`): a forma como o gamepad
   do browser aparece ao PCSX2.
2. **O TURN:** sem ele, o vídeo não chega a jogadores atrás de NAT (quase
   todos).
3. **As versões** do PCSX2 e da imagem base, fixadas no `Dockerfile`:
   confirmar que os nomes dos ficheiros ainda existem.

Para experimentar tudo menos a PS2 a sério, ponha `"motor": "simulado"`:
cada sessão mostra uma página verde em vez do jogo.

## A máquina

| Peça | Para a PS2 |
|---|---|
| Placa gráfica | NVIDIA com NVENC (GTX 1650 ou superior; RTX 3060 é confortável) |
| Processador | Velocidade alta por núcleo; ~2 núcleos rápidos por jogador |
| Memória | 4 GB por jogador + 4 GB |
| Disco | SSD; a cache guarda os jogos (`cache_max_gb`) |
| Rede | IP público, ~10 Mbps de upload por jogador, perto dos jogadores |
| Sistema | Ubuntu 24.04 |

Os servidores "de empresa" com muitos núcleos lentos (Xeon/EPYC antigos)
são piores para emulação do que um processador de secretária.

## Instalar

Os passos assumem o nó em `no1.exemplo.ao` e o site em
`https://exemplo.ao`. Troque os dois.

### 1. Drivers, Docker e o acesso do Docker à placa

```bash
sudo ubuntu-drivers install                      # driver NVIDIA; reiniciar a seguir
curl -fsSL https://get.docker.com | sudo sh
# o NVIDIA Container Toolkit: seguir
# https://docs.nvidia.com/datacenter/cloud-native/container-toolkit/latest/install-guide.html
sudo nvidia-ctk runtime configure --runtime=docker && sudo systemctl restart docker
docker run --rm --gpus all ubuntu nvidia-smi     # tem de mostrar a placa
```

### 2. DNS, firewall e certificado

- `no1.exemplo.ao` a apontar para o IP da máquina. O ideal é um
  **subdomínio do site**: assim o browser trata o jogo como parte do site
  (o cookie do lugar não é "de terceiros").
- Portas a abrir:
  - **TCP 7443:** a API, para o site. Se puder, só a partir do IP do site.
  - **TCP 8443 a 8443+N−1:** um por lugar.
  - **TCP/UDP 3478, e UDP 49160–49200:** o TURN.

```bash
sudo apt install nginx certbot python3
sudo certbot certonly --nginx -d no1.exemplo.ao
```

### 3. O TURN (coturn)

O vídeo vai por WebRTC, e quase todos os jogadores estão atrás de um router
(NAT). O coturn faz de ponte.

```bash
sudo apt install coturn
sudo tee /etc/turnserver.conf <<'EOF'
listening-port=3478
min-port=49160
max-port=49200
fingerprint
lt-cred-mech
user=isuva:TROCAR-palavra-passe-do-coturn
realm=no1.exemplo.ao
external-ip=IP_PUBLICO_DA_MAQUINA
EOF
sudo sed -i 's/#TURNSERVER_ENABLED=1/TURNSERVER_ENABLED=1/' /etc/default/coturn
sudo systemctl enable --now coturn
```

Ponha os mesmos valores em `docker.env` no `config.json`
(`SELKIES_TURN_*`).

### 4. A imagem da PS2

```bash
sudo mkdir -p /opt/isuva && sudo cp -r no-de-jogo /opt/isuva/
cd /opt/isuva/no-de-jogo
sudo docker build -t isuva-ps2:latest imagem-ps2
```

### 5. O agente

```bash
sudo useradd --system --create-home --groups docker isuva
sudo mkdir -p /etc/isuva /var/lib/isuva
sudo cp config.exemplo.json /etc/isuva/config.json
sudo chown -R isuva: /var/lib/isuva
sudo chown root:isuva /etc/isuva/config.json && sudo chmod 640 /etc/isuva/config.json
sudo nano /etc/isuva/config.json        # segredo, capacidade, TURN
sudo cp isuva-agente.service /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl enable --now isuva-agente
journalctl -u isuva-agente -f
```

O **segredo** vem do site: em *Servidores de jogo → Acrescentar
servidor*, o formulário já traz um gerado ao acaso. Copie-o para aqui.

### 6. O nginx

```bash
sudo python3 gerar-nginx.py /etc/isuva/config.json \
    --dominio no1.exemplo.ao --site https://exemplo.ao \
    --porta-base 8443 --porta-api 7443 \
    | sudo tee /etc/nginx/conf.d/isuva.conf >/dev/null
sudo nginx -t && sudo systemctl reload nginx
```

Mudou a `capacidade`? Gere outra vez: cada lugar é uma porta.

### 7. No site

1. *Servidores de jogo → Acrescentar servidor:*
   - API: `https://no1.exemplo.ao:7443`;
   - público: `https://no1.exemplo.ao`;
   - porta: 8443;
   - jogadores: o mesmo valor da `capacidade`;
   - o segredo.
2. A lista tem de dizer **Responde**.
3. *Gerir jogos → Emulador e BIOS:* a BIOS da PS2, com o nome original.
4. *Gerir jogos → Adicionar jogo:* consola PlayStation 2, um `.iso` ou `.chd`.

## Medir quantos jogadores aguenta

Comece com `capacidade: 1`, jogue um jogo pesado (os mais exigentes da PS2
são de 2004 a 2006), e veja:

```bash
nvidia-smi dmon -s u          # uso da placa e do codificador (enc)
htop                          # os núcleos do processador
```

Se nada passar dos ~70%, suba para 2, gere o nginx outra vez, acerte o
número no painel e repita. O limite costuma ser o **processador** (o PCSX2
gosta de núcleos rápidos) antes da placa gráfica.

## Como funciona, por dentro

- **Cada lugar é uma porta.** O cliente web do Selkies liga-se a
  `/webrtc/signalling/` na raiz; com uma porta por lugar, cada sessão tem a
  sua raiz.
- **Entrar:** o site dá ao jogador `https://no1…:8443/entrar?s=<sessão>&t=<token>`.
  O agente confere o token e troca-o por um cookie desse lugar. Daí em
  diante o nginx pergunta ao agente (`auth_request /auth`) em cada pedido.
- **A cópia do jogo:** o nó descarrega o jogo e a BIOS do site, por
  endereços assinados e com prazo (`/no/ficheiro/…`, `NoControlo`). Guarda
  tudo em `cache` e só volta a descarregar se o ficheiro mudar. Uma descarga
  interrompida retoma de onde parou.
- **Cartões de memória:** são da **conta**, guardados no site. O agente
  lê o cartão do jogador do site antes de arrancar a consola
  (`trazer_cartao`), e devolve-o comprimido ao terminar e de
  `devolver_cartao` em `devolver_cartao` segundos. Assim a gravação segue
  o jogador para qualquer nó. A pasta `cartoes/<id do utilizador>` é só a
  cópia de trabalho.
  - **A devolução final acontece antes de o agente responder ao site.** É
    de propósito: quem acaba um jogo e começa outro faz o site pedir a
    sessão nova logo a seguir, e essa sessão lê o cartão.
  - Se o site não responder nesse momento, o cartão fica marcado como
    `.pendente` e sobe na sessão seguinte, antes de se ler o do site.
- **Lugares abandonados:**
  - o site termina a sessão quando a página do jogador deixa de dar sinal
    (2 minutos);
  - o agente faz o mesmo se o site deixar de passar esse sinal
    (`sem_sinal`);
  - nenhuma sessão passa de `duracao_max`.
