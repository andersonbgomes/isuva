#!/usr/bin/env python3
"""
O AGENTE DE UM NÓ DE JOGO.

Corre na máquina com placa gráfica e faz uma coisa: recebe sessões do site
(isuva) e, para cada uma, arranca um contentor com o PCSX2 e o Selkies (que
envia a imagem por WebRTC ao browser do jogador). Cada sessão ocupa um
LUGAR (slot); o lugar N fica na porta interna porta_interna + N, e o nginx
do nó expõe-no na porta pública porta_base + N (ver gerar-nginx.py).

Só biblioteca padrão do Python, de propósito: numa máquina de jogo não há
nada para instalar além do Python que o Ubuntu já traz.

AS DUAS PORTAS DO AGENTE

    API      /estado, /sessoes...  Pedidos do SITE, assinados com o segredo
             partilhado (HMAC-SHA256 -- igual ao lib/jogos/Agente.php).
    browser  /entrar, /auth        Pedidos do BROWSER do jogador, que só
             chegam através do nginx de um lugar (que põe o cabeçalho
             X-Slot). O /entrar troca o token da sessão por um cookie; o
             /auth é o auth_request do nginx: diz se aquele cookie pode
             ver aquele lugar.

O agente escuta só em 127.0.0.1: tudo o que vem de fora passa pelo nginx,
que trata do HTTPS.

COMO SE CORRE

    python3 agente.py config.json

(ver config.exemplo.json, e o LEIA-ME.md para a instalação toda)
"""

import gzip
import hashlib
import hmac
import http.cookies
import json
import logging
import os
import secrets
import shutil
import signal
import subprocess
import sys
import tempfile
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

VERSAO = "1.0.0"

# Os pedidos do site com mais de 2 minutos são recusados: um pedido
# apanhado a meio não pode ser repetido mais tarde.
JANELA_ASSINATURA = 120

# Os núcleos que este agente sabe arrancar. O site manda o 'nucleo' da
# consola (lib/jogos/Consolas.php); um núcleo que não esteja aqui é um
# erro de configuração, não uma sessão.
NUCLEOS = {"pcsx2"}

log = logging.getLogger("agente")


# ======================================================================
# Configuração
# ======================================================================

PADROES = {
    "escutar": "127.0.0.1",
    "porta_api": 7000,
    "capacidade": 1,
    "porta_interna": 9000,          # o lugar N fica em porta_interna + N
    "cache": "/var/lib/isuva/cache",
    "cache_max_gb": 200,
    "cartoes": "/var/lib/isuva/cartoes",
    "sem_sinal": 300,               # segundos sem sinal do site até libertar o lugar
    "devolver_cartao": 300,         # de quanto em quanto tempo o cartão volta ao site durante o jogo
    "duracao_max": 4 * 3600,        # nenhuma sessão passa disto
    "arranque_max": 180,            # segundos para o contentor responder
    "motor": "docker",              # docker | simulado (para testes, sem GPU)
    "docker": {
        "imagem": "isuva-ps2:latest",
        "gpus": "all",
        "porta_contentor": 8080,
        "resolucao": [1280, 720],
        "codificador": "nvh264enc",
        "env": {},                  # extra (ex.: o TURN -- ver o LEIA-ME)
    },
}


def ler_config(caminho):
    with open(caminho, encoding="utf-8") as f:
        c = json.load(f)
    cfg = json.loads(json.dumps(PADROES))
    for k, v in c.items():
        if isinstance(v, dict) and isinstance(cfg.get(k), dict):
            cfg[k].update(v)
        else:
            cfg[k] = v

    # Sem segredo (ou com um curto) qualquer pessoa pedia sessões ao nó e
    # gastava a placa gráfica. Não se arranca assim.
    if not isinstance(cfg.get("segredo"), str) or len(cfg["segredo"]) < 32:
        sys.exit("config: o 'segredo' tem de ter pelo menos 32 caracteres (o mesmo do painel do site)")
    return cfg


# ======================================================================
# Assinaturas
# ======================================================================

def assinar(segredo, metodo, caminho, tempo, corpo):
    """Igual ao Agente::assinar() do site -- as duas têm de bater certo."""
    texto = "\n".join([metodo.upper(), caminho, str(tempo), hashlib.sha256(corpo).hexdigest()])
    return hmac.new(segredo.encode(), texto.encode(), hashlib.sha256).hexdigest()


def selo_cookie(segredo, sessao):
    """O valor do cookie de um lugar: prova que quem o tem entrou com o token desta sessão."""
    texto = "cookie|%d|%d|%s" % (sessao.id, sessao.slot, sessao.token)
    return hmac.new(segredo.encode(), texto.encode(), hashlib.sha256).hexdigest()


# ======================================================================
# Sessões
# ======================================================================

class Sessao:
    def __init__(self, dados):
        self.id = int(dados["sessao"])
        self.slot = int(dados["slot"])
        self.token = str(dados["token"])
        self.utilizador = int(dados.get("utilizador") or 0)
        self.nucleo = str(dados["nucleo"])
        self.jogo = dados["jogo"]
        self.bios = dados.get("bios")
        self.estado = "a_preparar"      # a_preparar | pronta | terminada | erro
        self.progresso = 0.0
        self.mensagem = "Na fila do servidor..."
        self.inicio = time.time()
        self.vivo = time.time()
        self.processo = None            # só no motor simulado
        # o cartão de memória da conta: lido do site ao arrancar, devolvido
        # no fim e de devolver_cartao em devolver_cartao segundos
        self.cartao = dados.get("cartao") or None
        self.cartao_lido = False        # só se devolve um cartão que se leu primeiro
        self.cartao_marca = None        # (mtime, tamanho) da última devolução

    def activa(self):
        return self.estado in ("a_preparar", "pronta")

    def resumo(self):
        return {
            "sessao": self.id, "slot": self.slot, "estado": self.estado,
            "progresso": round(self.progresso, 3), "mensagem": self.mensagem,
            "desde": int(self.inicio),
        }


class No:
    """O estado do nó: os lugares, as sessões, a cache."""

    def __init__(self, cfg):
        self.cfg = cfg
        self.lock = threading.RLock()
        self.sessoes = {}               # id -> Sessao (activas e as que acabaram há pouco)
        self.em_uso = set()             # ficheiros da cache que uma sessão está a usar
        self.descargas = {}             # destino -> Lock: uma descarga de cada ficheiro de cada vez
        os.makedirs(os.path.join(cfg["cache"], "jogos"), exist_ok=True)
        os.makedirs(os.path.join(cfg["cache"], "bios"), exist_ok=True)
        os.makedirs(cfg["cartoes"], exist_ok=True)

    # ---------------------------------------------------------------- descargas

    def trava(self, destino):
        """
        A trava de um ficheiro da cache.

        Dois jogadores a pedir o mesmo jogo pela primeira vez, ao mesmo
        tempo, descarregavam os dois para o mesmo .part -- e um estragava o
        ficheiro do outro (aconteceu no primeiro teste, com a BIOS). Com a
        trava, o segundo espera pelo primeiro e encontra o ficheiro pronto.
        """
        with self.lock:
            if destino not in self.descargas:
                self.descargas[destino] = threading.Lock()
            return self.descargas[destino]

    # ---------------------------------------------------------------- lugares

    def no_lugar(self, slot):
        with self.lock:
            for s in self.sessoes.values():
                if s.slot == slot and s.activa():
                    return s
        return None

    def ocupados(self):
        with self.lock:
            return sum(1 for s in self.sessoes.values() if s.activa())

    # ---------------------------------------------------------------- criar

    def criar(self, dados):
        """Devolve (codigo, corpo)."""
        try:
            sid = int(dados["sessao"])
            slot = int(dados["slot"])
            str(dados["token"])
            jogo = dados["jogo"]
            int(jogo["id"]), int(jogo["tamanho"]), str(jogo["nome"]), str(jogo["url"])
        except (KeyError, TypeError, ValueError):
            return 400, {"erro": "Pedido incompleto."}

        if not 0 <= slot < int(self.cfg["capacidade"]):
            return 400, {"erro": "Lugar %d fora da capacidade deste nó (%d)." % (slot, self.cfg["capacidade"])}
        if dados.get("nucleo") not in NUCLEOS:
            return 400, {"erro": "Este nó não sabe correr o núcleo '%s'." % dados.get("nucleo")}

        with self.lock:
            existente = self.sessoes.get(sid)
            if existente and existente.activa():
                # o site repetiu o pedido (uma falha de rede pelo meio): é a mesma sessão
                return 200, existente.resumo()

            ocupante = self.no_lugar(slot)
            if ocupante:
                return 409, {"erro": "O lugar %d está ocupado pela sessão %d." % (slot, ocupante.id)}

            s = Sessao(dados)
            self.sessoes[sid] = s

        threading.Thread(target=self.preparar, args=(s,), daemon=True, name="sessao-%d" % sid).start()
        log.info("sessão %d: criada no lugar %d (jogo %s)", sid, slot, s.jogo["nome"])
        return 201, s.resumo()

    # ---------------------------------------------------------------- preparar

    def preparar(self, s):
        """Copia o jogo e a BIOS para a cache, arranca o motor e espera que responda."""
        try:
            bios = self.trazer_bios(s)
            self.trazer_cartao(s)
            jogo = self.trazer_jogo(s)
            if not s.activa():
                return

            s.mensagem = "A arrancar a consola..."
            s.progresso = 1.0
            self.motor_arrancar(s, jogo, bios)
            self.esperar_resposta(s)
            if s.activa():
                s.estado = "pronta"
                s.mensagem = ""
                log.info("sessão %d: pronta", s.id)
        except Exception as e:                          # noqa: BLE001 -- qualquer falha acaba nesta sessão, não no agente
            log.exception("sessão %d: falhou", s.id)
            if s.activa():
                s.estado = "erro"
                s.mensagem = str(e)[:250] or "Falha a preparar o jogo."
            self.motor_parar(s)

    def trazer_bios(self, s):
        if not s.bios:
            return None
        nome = os.path.basename(str(s.bios["nome"]))
        pasta = os.path.join(self.cfg["cache"], "bios", s.nucleo)
        os.makedirs(pasta, exist_ok=True)
        destino = os.path.join(pasta, nome)
        # A BIOS é pequena: descarrega-se sempre. Assim, quando o
        # administrador a troca no painel, a sessão seguinte já usa a nova.
        s.mensagem = "A copiar a BIOS..."
        with self.trava(destino):
            # sem tamanho conhecido não há como validar uma retoma: um .part
            # de uma BIOS antiga ficava colado à nova. Começa-se sempre do zero.
            if os.path.exists(destino + ".part"):
                os.remove(destino + ".part")
            descarregar(s.bios["url"], destino, None, s)
        return destino

    def trazer_jogo(self, s):
        """
        O jogo fica na cache com o id e o tamanho no nome: se o mesmo jogo
        for trocado no site por outro ficheiro, o tamanho muda e a cópia
        velha deixa de servir sozinha.
        """
        j = s.jogo
        ext = os.path.splitext(str(j["nome"]))[1].lower()
        if not ext[1:].isalnum():
            ext = ""
        destino = os.path.join(self.cfg["cache"], "jogos", "%d-%d%s" % (int(j["id"]), int(j["tamanho"]), ext))

        with self.lock:
            self.em_uso.add(destino)

        with self.trava(destino):
            # verificado já com a trava: se outra sessão acabou de o
            # descarregar enquanto esta esperava, está pronto
            if os.path.exists(destino) and os.path.getsize(destino) == int(j["tamanho"]):
                os.utime(destino)       # para a limpeza da cache, isto conta como "usado agora"
                return destino

            self.abrir_espaco(int(j["tamanho"]))
            s.mensagem = "A copiar o jogo para o servidor (só na primeira vez)..."
            descarregar(j["url"], destino, int(j["tamanho"]), s)
        return destino

    def abrir_espaco(self, preciso):
        """
        Apaga os jogos usados há mais tempo até caber o novo.

        Nunca apaga um que esteja a ser usado. Sem limite, a cache crescia
        até encher o disco -- e um disco cheio parava o nó inteiro.
        """
        pasta = os.path.join(self.cfg["cache"], "jogos")
        limite = float(self.cfg["cache_max_gb"]) * 1024 ** 3
        ficheiros = []
        for nome in os.listdir(pasta):
            c = os.path.join(pasta, nome)
            if os.path.isfile(c):
                ficheiros.append((os.path.getmtime(c), os.path.getsize(c), c))
        total = sum(f[1] for f in ficheiros)

        livre_disco = shutil.disk_usage(pasta).free
        for _, tamanho, c in sorted(ficheiros):
            if total + preciso <= limite and livre_disco > preciso + 2 * 1024 ** 3:
                break
            with self.lock:
                if c in self.em_uso:
                    continue
            log.info("cache: apagado %s para abrir espaço", c)
            os.remove(c)
            total -= tamanho
            livre_disco += tamanho

        if livre_disco < preciso:
            raise RuntimeError("Não há espaço em disco no servidor de jogo para este jogo.")

    # ---------------------------------------------------------------- cartão de memória

    def caminho_cartao(self, s):
        """O cartão do jogador neste nó (a pasta é montada em /cartoes no contentor)."""
        pasta = os.path.join(self.cfg["cartoes"], str(s.utilizador))
        os.makedirs(pasta, exist_ok=True)
        return os.path.join(pasta, "Mcd001.ps2")

    def trazer_cartao(self, s):
        """
        O cartão de memória da CONTA, lido do site antes de a consola
        arrancar. É o que faz a gravação seguir a pessoa para qualquer nó.

        Se o site não responder, a sessão FALHA em vez de arrancar com o
        cartão que este nó tiver: esse pode ser velho, e no fim da sessão
        seria devolvido por cima do bom.
        """
        if not s.cartao:
            return
        destino = self.caminho_cartao(s)
        s.mensagem = "A trazer o seu cartão de memória..."
        with self.trava(destino):
            # Uma sessão anterior não conseguiu devolver o cartão (o site
            # estava em baixo): o deste nó é o mais recente. Vai primeiro
            # para o site, e só depois se lê -- senão o velho vinha por cima.
            if os.path.exists(destino + ".pendente"):
                if not self.enviar_cartao(s, destino):
                    raise RuntimeError("O seu cartão de memória ainda não voltou ao site. Tente daqui a pouco.")
                os.remove(destino + ".pendente")
            try:
                with urllib.request.urlopen(urllib.request.Request(s.cartao["url"], headers={"User-Agent": "isuva-agente/" + VERSAO}), timeout=30) as r:
                    dados = r.read()
            except urllib.error.HTTPError as e:
                if e.code != 404:
                    raise RuntimeError("Não foi possível ler o seu cartão de memória (%d)." % e.code)
                dados = None    # a conta ainda não tem cartão: fica o deste nó, se houver
            except OSError as e:
                raise RuntimeError("Não foi possível ler o seu cartão de memória: %s" % e)

            if dados:
                # comprimido pelo devolver_cartao (o site guarda os bytes tal e qual)
                if dados[:2] == b"\x1f\x8b":
                    dados = gzip.decompress(dados)
                with open(destino + ".novo", "wb") as f:
                    f.write(dados)
                os.replace(destino + ".novo", destino)
            s.cartao_lido = True
            if os.path.exists(destino):
                s.cartao_marca = (os.path.getmtime(destino), os.path.getsize(destino))

    def devolver_cartao(self, s, final):
        """
        Manda o cartão de volta ao site, comprimido (um cartão de PS2 tem
        8 MB, quase todos zeros: comprimido fica em dezenas de KB).

        Só se foi lido no arranque (senão podia ir um cartão velho por cima
        do da conta) e só se mudou desde a última vez. A cópia é feita para
        a memória primeiro: durante o jogo o PCSX2 pode estar a escrever.
        """
        if not s.cartao or not s.cartao_lido:
            return
        destino = self.caminho_cartao(s)
        with self.trava(destino):
            if not os.path.exists(destino):
                return
            marca = (os.path.getmtime(destino), os.path.getsize(destino))
            if marca == s.cartao_marca:
                return
            if self.enviar_cartao(s, destino, 2 if final else 1):
                s.cartao_marca = marca
            elif final:
                # fica marcado: a próxima sessão desta conta neste nó manda-o
                # antes de ler o do site (ver trazer_cartao)
                open(destino + ".pendente", "w").close()

    def enviar_cartao(self, s, destino, tentativas=2):
        """
        O PUT do cartão, comprimido. Poucas tentativas e curtas: no fim da
        sessão isto corre DENTRO do pedido de terminar do site, que desiste
        ao fim de 15 s.
        """
        with open(destino, "rb") as f:
            dados = gzip.compress(f.read(), compresslevel=6)
        for _ in range(tentativas):
            try:
                pedido = urllib.request.Request(s.cartao["url"], data=dados, method="PUT", headers={
                    "Content-Type": "application/octet-stream", "User-Agent": "isuva-agente/" + VERSAO})
                urllib.request.urlopen(pedido, timeout=10).close()
                log.info("sessão %d: cartão devolvido ao site (%d bytes)", s.id, len(dados))
                return True
            except (urllib.error.HTTPError, OSError) as e:
                log.warning("sessão %d: o cartão não voltou ao site (%s)", s.id, e)
                time.sleep(1)
        return False

    def esperar_resposta(self, s):
        """O contentor arrancou quando a porta dele responde a HTTP."""
        porta = int(self.cfg["porta_interna"]) + s.slot
        fim = time.time() + float(self.cfg["arranque_max"])
        while time.time() < fim and s.activa():
            try:
                urllib.request.urlopen("http://127.0.0.1:%d/" % porta, timeout=2).close()
                return
            except urllib.error.HTTPError:
                return              # respondeu, mesmo que com erro: está de pé
            except OSError:
                time.sleep(1)
        if s.activa():
            raise RuntimeError("A consola não arrancou a tempo.")

    # ---------------------------------------------------------------- motor

    def nome_contentor(self, s):
        return "isuva-lugar-%d" % s.slot

    def motor_arrancar(self, s, jogo, bios):
        porta = int(self.cfg["porta_interna"]) + s.slot

        if self.cfg["motor"] == "simulado":
            # Para testar o site e o nginx sem GPU: um servidor HTTP que
            # mostra uma página no lugar do jogo.
            pasta = tempfile.mkdtemp(prefix="isuva-sim-")
            with open(os.path.join(pasta, "index.html"), "w", encoding="utf-8") as f:
                f.write("<!doctype html><meta charset=utf-8><body style='background:#111;color:#0f0;font:20px monospace;padding:2em'>"
                        "SIMULAÇÃO &middot; sessão %d &middot; lugar %d &middot; %s</body>" % (s.id, s.slot, os.path.basename(jogo)))
            s.processo = subprocess.Popen(
                [sys.executable, "-m", "http.server", str(porta), "--bind", "127.0.0.1", "--directory", pasta],
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            return

        d = self.cfg["docker"]
        cartoes = os.path.join(self.cfg["cartoes"], str(s.utilizador))
        os.makedirs(cartoes, exist_ok=True)
        nome_jogo = os.path.basename(jogo)

        # um contentor antigo com o mesmo nome (o agente reiniciou a meio) sai primeiro
        subprocess.run(["docker", "rm", "-f", self.nome_contentor(s)],
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

        env = {
            "TZ": "UTC",
            "DISPLAY_SIZEW": str(d["resolucao"][0]),
            "DISPLAY_SIZEH": str(d["resolucao"][1]),
            "DISPLAY_REFRESH": "60",
            "SELKIES_ENCODER": d["codificador"],
            "SELKIES_ENABLE_RESIZE": "false",
            # a autenticação é do nginx do nó (o /auth daqui); a do Selkies
            # ficava a pedir utilizador e palavra-passe dentro do iframe
            "SELKIES_ENABLE_BASIC_AUTH": "false",
            "PASSWD": secrets.token_hex(16),
            "ISUVA_JOGO": "/jogo/" + nome_jogo,
            "ISUVA_BIOS": os.path.basename(bios) if bios else "",
        }
        env.update({str(k): str(v) for k, v in d.get("env", {}).items()})

        cmd = ["docker", "run", "-d", "--rm",
               "--name", self.nome_contentor(s),
               "--gpus", str(d["gpus"]),
               "--tmpfs", "/dev/shm:rw",
               "-p", "127.0.0.1:%d:%d" % (porta, int(d["porta_contentor"])),
               "-v", "%s:/jogo/%s:ro" % (jogo, nome_jogo),
               "-v", "%s:/cartoes" % cartoes,
               "--label", "isuva.sessao=%d" % s.id]
        if bios:
            cmd += ["-v", "%s:/bios:ro" % os.path.dirname(bios)]
        for k, v in env.items():
            cmd += ["-e", "%s=%s" % (k, v)]
        cmd.append(d["imagem"])

        r = subprocess.run(cmd, capture_output=True, text=True)
        if r.returncode != 0:
            raise RuntimeError("O contentor não arrancou: " + (r.stderr.strip()[-200:] or "erro desconhecido"))

    def motor_parar(self, s):
        if self.cfg["motor"] == "simulado":
            if s.processo:
                s.processo.terminate()
                try:
                    s.processo.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    s.processo.kill()
                s.processo = None
            return
        subprocess.run(["docker", "rm", "-f", self.nome_contentor(s)],
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

    # ---------------------------------------------------------------- terminar

    def terminar(self, s, motivo):
        with self.lock:
            if not s.activa():
                return
            s.estado = "terminada"
            s.mensagem = motivo
        self.motor_parar(s)
        self.libertar_cache()
        log.info("sessão %d: terminada (%s)", s.id, motivo)
        # O contentor já parou, por isso o PCSX2 já escreveu o cartão todo.
        # A devolução é feita AQUI, antes de responder ao site, e não numa
        # thread: quem termina um jogo e começa outro (aqui ou noutro nó)
        # faz o site pedir a sessão nova logo a seguir a esta resposta -- e
        # a sessão nova lê o cartão do site. Com uma thread, podia lê-lo
        # antes de este chegar, e o progresso desta sessão perdia-se.
        self.devolver_cartao(s, True)

    def libertar_cache(self):
        with self.lock:
            usados = set()
            for s in self.sessoes.values():
                if s.activa():
                    ext = os.path.splitext(str(s.jogo["nome"]))[1].lower()
                    usados.add(os.path.join(self.cfg["cache"], "jogos", "%d-%d%s" % (int(s.jogo["id"]), int(s.jogo["tamanho"]), ext)))
            self.em_uso = usados

    def vigiar(self):
        """
        De 15 em 15 segundos: liberta os lugares abandonados e esquece as
        sessões que acabaram há mais de uma hora.

        É a rede de segurança do lado do nó: se o site cair, ou deixar de
        passar o sinal de vida, o lugar não fica preso para sempre.
        """
        while True:
            time.sleep(15)
            agora = time.time()
            with self.lock:
                lista = list(self.sessoes.values())
            for s in lista:
                if s.estado == "pronta" and agora - getattr(s, "_devolvido", s.inicio) > float(self.cfg["devolver_cartao"]):
                    s._devolvido = agora
                    threading.Thread(target=self.devolver_cartao, args=(s, False), daemon=True).start()
                if s.activa() and agora - s.vivo > float(self.cfg["sem_sinal"]):
                    self.terminar(s, "Sem sinal do site.")
                elif s.activa() and agora - s.inicio > float(self.cfg["duracao_max"]):
                    self.terminar(s, "Chegou ao tempo máximo de uma sessão.")
                elif s.estado == "pronta" and self.cfg["motor"] == "docker" and not contentor_vivo(self.nome_contentor(s)):
                    self.terminar(s, "A consola fechou-se.")
            with self.lock:
                for sid in [k for k, v in self.sessoes.items() if not v.activa() and agora - v.inicio > 3600]:
                    del self.sessoes[sid]


def limpar_orfaos(cfg):
    """
    Ao arrancar, os contentores de sessões de uma vida anterior do agente
    (que caiu, ou a máquina reiniciou) já não pertencem a ninguém: o agente
    esqueceu-as, e o site vai dá-las por perdidas. Saem todos.
    """
    if cfg["motor"] != "docker":
        return
    r = subprocess.run(["docker", "ps", "-aq", "--filter", "label=isuva.sessao"], capture_output=True, text=True)
    ids = r.stdout.split()
    if ids:
        log.info("a remover %d contentor(es) de sessões antigas", len(ids))
        subprocess.run(["docker", "rm", "-f"] + ids, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)


def contentor_vivo(nome):
    r = subprocess.run(["docker", "inspect", "-f", "{{.State.Running}}", nome], capture_output=True, text=True)
    return r.returncode == 0 and r.stdout.strip() == "true"


def descarregar(url, destino, tamanho, sessao):
    """
    Descarrega url para destino, retomando de onde parou (Range) se já
    houver um .part de uma tentativa anterior. Uma ISO de 4 GB que cai aos
    3,9 GB não volta ao zero.
    """
    if not url.startswith(("https://", "http://")):
        raise RuntimeError("Endereço de ficheiro inválido.")

    parcial = destino + ".part"
    for tentativa in range(5):
        feito = os.path.getsize(parcial) if os.path.exists(parcial) else 0
        pedido = urllib.request.Request(url, headers={"User-Agent": "isuva-agente/" + VERSAO})
        if feito:
            pedido.add_header("Range", "bytes=%d-" % feito)
        try:
            with urllib.request.urlopen(pedido, timeout=30) as r:
                if feito and r.status != 206:
                    feito = 0           # o servidor não retomou: começa de novo
                with open(parcial, "ab" if feito else "wb") as f:
                    while True:
                        if not sessao.activa():
                            return
                        bloco = r.read(1024 * 1024)
                        if not bloco:
                            break
                        f.write(bloco)
                        feito += len(bloco)
                        if tamanho:
                            sessao.progresso = min(feito / tamanho, 0.99)
            break
        except urllib.error.HTTPError as e:
            if e.code in (403, 404):
                raise RuntimeError("O site recusou o ficheiro (%d). O endereço pode ter expirado." % e.code)
            log.warning("descarga %s: HTTP %d, tentativa %d", destino, e.code, tentativa + 1)
        except OSError as e:
            log.warning("descarga %s: %s, tentativa %d", destino, e, tentativa + 1)
        time.sleep(3 * (tentativa + 1))
    else:
        raise RuntimeError("Não foi possível copiar o ficheiro do site.")

    if tamanho and os.path.getsize(parcial) != tamanho:
        os.remove(parcial)
        raise RuntimeError("O ficheiro chegou com o tamanho errado. Tente outra vez.")
    os.replace(parcial, destino)


# ======================================================================
# HTTP
# ======================================================================

class Pedidos(BaseHTTPRequestHandler):
    server_version = "isuva-agente/" + VERSAO
    no = None   # posto em main()

    def log_message(self, fmt, *args):
        log.debug("%s %s", self.address_string(), fmt % args)

    # ---------------------------------------------------------------- respostas

    def responder(self, codigo, dados=None, cabecalhos=None):
        corpo = b"" if dados is None else json.dumps(dados, ensure_ascii=False).encode()
        self.send_response(codigo)
        if dados is not None:
            self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(corpo)))
        self.send_header("Cache-Control", "no-store")
        for k, v in (cabecalhos or []):
            self.send_header(k, v)
        self.end_headers()
        if corpo and self.command != "HEAD":
            self.wfile.write(corpo)

    def corpo(self):
        n = int(self.headers.get("Content-Length") or 0)
        if n > 1024 * 1024:
            raise ValueError("corpo grande demais")
        return self.rfile.read(n) if n else b""

    # ---------------------------------------------------------------- verbos

    def do_GET(self):
        self.tratar("GET")

    def do_POST(self):
        self.tratar("POST")

    def do_DELETE(self):
        self.tratar("DELETE")

    def tratar(self, metodo):
        url = urllib.parse.urlsplit(self.path)
        caminho = url.path
        try:
            corpo = self.corpo()
        except ValueError:
            return self.responder(413, {"erro": "Pedido grande demais."})

        # o lado do browser (só através do nginx de um lugar)
        if caminho == "/entrar" and metodo == "GET":
            return self.entrar(urllib.parse.parse_qs(url.query))
        if caminho == "/auth" and metodo == "GET":
            return self.auth()

        # daqui para baixo, só pedidos assinados pelo site
        if not self.assinatura_valida(metodo, caminho, corpo):
            return self.responder(401, {"erro": "Assinatura inválida."})

        partes = [p for p in caminho.split("/") if p]
        no = self.no

        if partes == ["estado"] and metodo == "GET":
            return self.responder(200, {
                "versao": VERSAO, "capacidade": int(no.cfg["capacidade"]),
                "ocupados": no.ocupados(), "motor": no.cfg["motor"],
            })

        if partes == ["sessoes"] and metodo == "POST":
            try:
                dados = json.loads(corpo or b"{}")
            except ValueError:
                return self.responder(400, {"erro": "JSON inválido."})
            codigo, resposta = no.criar(dados)
            return self.responder(codigo, resposta)

        if len(partes) >= 2 and partes[0] == "sessoes" and partes[1].isdigit():
            s = no.sessoes.get(int(partes[1]))
            if s is None:
                return self.responder(404, {"erro": "Sessão desconhecida."})

            if len(partes) == 2 and metodo == "GET":
                return self.responder(200, s.resumo())
            if len(partes) == 2 and metodo == "DELETE":
                no.terminar(s, "Terminada pelo site.")
                return self.responder(200, s.resumo())
            if partes[2:] == ["vivo"] and metodo == "POST":
                if not s.activa():
                    return self.responder(404, {"erro": "A sessão já terminou."})
                s.vivo = time.time()
                return self.responder(200, {"ok": True})

        return self.responder(404, {"erro": "Não existe."})

    def assinatura_valida(self, metodo, caminho, corpo):
        try:
            tempo = int(self.headers.get("X-Tempo", ""))
        except ValueError:
            return False
        if abs(time.time() - tempo) > JANELA_ASSINATURA:
            return False
        certa = assinar(self.no.cfg["segredo"], metodo, caminho, tempo, corpo)
        return hmac.compare_digest(certa, self.headers.get("X-Assinatura", ""))

    # ---------------------------------------------------------------- browser

    def lugar(self):
        """O lugar deste pedido, posto pelo nginx. Sem ele, o pedido não veio de um lugar."""
        v = self.headers.get("X-Slot", "")
        return int(v) if v.isdigit() else None

    def entrar(self, q):
        """
        O jogador chega aqui com o token da sessão (dado pelo site) e sai
        com um cookie deste lugar. O token vai no endereço só uma vez; o
        resto da sessão (a página do Selkies, o WebSocket) anda com o
        cookie.
        """
        slot = self.lugar()
        try:
            sid = int((q.get("s") or [""])[0])
        except ValueError:
            sid = -1
        token = (q.get("t") or [""])[0]

        s = self.no.no_lugar(slot) if slot is not None else None
        if not s or s.id != sid or s.estado != "pronta" or not hmac.compare_digest(s.token, token):
            return self.responder(403, {"erro": "Esta sessão não é válida ou já terminou. Volte ao site."})

        c = http.cookies.SimpleCookie()
        nome = "isuva_l%d" % slot
        c[nome] = "%d.%s" % (s.id, selo_cookie(self.no.cfg["segredo"], s))
        c[nome]["path"] = "/"
        c[nome]["httponly"] = True
        c[nome]["secure"] = True
        # SameSite=None: o jogo abre num <iframe> da página do site. Com o nó
        # num subdomínio do site isto nem é um cookie de terceiros.
        c[nome]["samesite"] = "None"
        self.responder(302, None, [("Set-Cookie", c[nome].OutputString()), ("Location", "/")])

    def auth(self):
        """O auth_request do nginx: 204 deixa passar, 401 não."""
        slot = self.lugar()
        s = self.no.no_lugar(slot) if slot is not None else None
        if s and s.estado == "pronta":
            c = http.cookies.SimpleCookie(self.headers.get("Cookie", ""))
            m = c.get("isuva_l%d" % slot)
            if m:
                sid, _, selo = m.value.partition(".")
                if sid == str(s.id) and hmac.compare_digest(selo, selo_cookie(self.no.cfg["segredo"], s)):
                    return self.responder(204)
        return self.responder(401)


def main():
    if len(sys.argv) != 2:
        sys.exit("uso: python3 agente.py config.json")
    cfg = ler_config(sys.argv[1])
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")

    no = No(cfg)
    Pedidos.no = no
    limpar_orfaos(cfg)

    # O systemctl stop manda SIGTERM, não Ctrl+C. Sem isto o agente morria
    # sem passar pelo finally lá em baixo, e os contentores ficavam a correr
    # (e a gastar a placa gráfica) sem ninguém que os parasse.
    def parar(*_):
        raise KeyboardInterrupt
    signal.signal(signal.SIGTERM, parar)
    threading.Thread(target=no.vigiar, daemon=True, name="vigia").start()

    servidor = ThreadingHTTPServer((cfg["escutar"], int(cfg["porta_api"])), Pedidos)
    servidor.daemon_threads = True
    log.info("agente %s a escutar em %s:%d, %d lugar(es), motor %s",
             VERSAO, cfg["escutar"], cfg["porta_api"], cfg["capacidade"], cfg["motor"])
    try:
        servidor.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        # ao sair, nenhum contentor fica a gastar a placa gráfica sem ninguém
        for s in list(no.sessoes.values()):
            no.terminar(s, "O agente foi desligado.")


if __name__ == "__main__":
    main()
