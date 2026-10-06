<?php
/*
Tratamento central de erros da aplicação.

Antes disto, os avisos/erros do PHP (ex.: "Warning: foreach() argument
must be of type array|object, null given") apareciam directamente no
ecrã, com caminhos de ficheiros do servidor à mistura — nada bonito
nem seguro de mostrar a um cliente. Agora ficam registados em
lib/logs/erros.log e o utilizador só vê uma mensagem simples.

Isto não substitui corrigir a causa do erro (continua a valer a pena
ver o log), só evita que o utilizador veja algo em bruto entretanto.
*/

define('_LOG_ERROS_', _C_ . _P_ . 'lib' . _P_ . 'logs' . _P_ . 'erros.log');

error_reporting(E_ALL);
ini_set('display_errors', '0');

function _registar_erro($tipo, $mensagem, $ficheiro = '', $linha = '') {
    $linhaLog = sprintf(
        "[%s] %s: %s in %s on line %s\n",
        date('Y-m-d H:i:s'),
        $tipo,
        $mensagem,
        $ficheiro,
        $linha
    );
    @file_put_contents(_LOG_ERROS_, $linhaLog, FILE_APPEND);
}

function _pagina_erro($tipo = '', $mensagem = '', $ficheiro = '', $linha = '') {
    if (!headers_sent()) {
        http_response_code(500);
    }
    //nota: sem <!DOCTYPE>/<html>/<head>/<style> — renderizar() não usa output
    //buffering, por isso um erro fatal a meio de uma view já deixou o
    //<html><head>...<body> do tema enviado ao browser; um segundo documento
    //completo (com <style> dentro de um novo <head>) fica preso dentro do
    //<body> já aberto e o browser descarta-o, mostrando só o texto sem
    //qualquer estilo. Por isso o estilo aqui vai todo inline, para funcionar
    //seja qual for o ponto da página onde o erro acontece.
    echo '
        <div style="max-width:640px;margin:60px auto;padding:32px;text-align:center;font-family:Arial,Helvetica,sans-serif;background:#fff;border:1px solid #e5e5e5;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,.08);">
            <h1 style="margin:0 0 10px;font-size:20px;color:#333;">Ocorreu um erro inesperado</h1>
            <p style="margin:0 0 20px;color:#666;font-size:14px;">A equipa já foi notificada. Tenta novamente dentro de instantes.</p>
            <a href="javascript:history.back()" style="display:inline-block;padding:8px 18px;background:#4361ee;color:#fff;text-decoration:none;border-radius:5px;font-size:14px;">Voltar</a>
    ';
    //o erro completo (tipo, mensagem, ficheiro e linha — o mesmo que vai para
    //lib/logs/erros.log) só aparece para quem tem sessão de administrador
    //($_SESSION['fct_nvl'], escrita no login — sem consultar a BD outra vez
    //aqui, que é arriscado a meio de um pedido já a falhar). Para todos os
    //outros continua só a mensagem genérica de cima: mostrar caminhos de
    //ficheiros do servidor e mensagens de SQL a um cliente qualquer era o
    //problema que este ficheiro resolveu — não faz sentido reabri-lo para
    //toda a gente só para facilitar o debug de quem gere a aplicação.
    if (($_SESSION['fct_nvl'] ?? null) == 1 && $mensagem !== '') {
        echo '
            <div style="margin-top:24px;padding-top:20px;border-top:1px solid #eee;text-align:left;">
                <p style="margin:0 0 8px;font-size:12px;font-weight:bold;color:#999;text-transform:uppercase;letter-spacing:.03em;">Detalhe do erro (visível só para administradores)</p>
                <pre style="margin:0;padding:14px;background:#faf4f4;border:1px solid #f0dede;border-radius:5px;color:#b23b3b;font-size:12px;line-height:1.6;white-space:pre-wrap;word-break:break-word;">'
                    . htmlspecialchars(trim($tipo . ': ' . $mensagem)) . "\n"
                    . htmlspecialchars($ficheiro . ($linha !== '' ? ' na linha ' . $linha : '')) .
                '</pre>
            </div>
        ';
    }
    echo '
        </div>
    ';
}

/*
A base de dados não respondeu -- e é preciso dizê-lo assim.

PORQUE E QUE ISTO EXISTE

O Con::ecta() apanhava a PDOException da ligação, engolia a mensagem e
fazia `header('Location:instalar')` SEM exit. Três problemas de uma só vez:

  1. sem exit, a execução continuava e o método acabava sem return --
     devolvia null;
  2. quem chamasse fazia `Con::ecta()->prepare(...)` sobre esse null, e o
     que ia parar ao log era "Call to a member function prepare() on null",
     três ficheiros à frente do sítio onde a coisa correu mal;
  3. a rota 'instalar' não existe nesta aplicação -- nunca existiu.

Ou seja: uma password errada no Conecta.php, uma base de dados que ainda
não foi criada ou um utilizador sem permissões davam todos exactamente o
mesmo erro incompreensível, e o motivo real -- que o MySQL até diz em
português claro -- nunca era escrito em lado nenhum.

Isto mostra uma página que diz o que se passou, e grava o motivo REAL no
lib/logs/erros.log. E pára, que é o que falta fazer quando não há base de
dados: sem ela não há página nenhuma para mostrar.
*/
function _pagina_erro_bd($motivo = '') {
    //dois problemas diferentes chegam aqui, e quem os vê precisa de saber
    //qual é: "não liga" resolve-se no app/Conecta.php, "ligou mas está
    //vazia" resolve-se importando o SQL de instalação
    $faltaImportar = (strpos($motivo, '`app_config` nao existe') !== false);

    _registar_erro(
        $faltaImportar ? 'Base de dados por preencher' : 'Base de dados indisponível',
        $motivo,
        $faltaImportar ? 'app/app.mdl/configura.php' : 'app/Conecta.php',
        ''
    );

    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        //um erro de configuração não é para ficar em cache de ninguém
        header('Cache-Control: no-store');
    }

    echo '
        <div style="max-width:640px;margin:60px auto;padding:32px;text-align:center;font-family:Arial,Helvetica,sans-serif;background:#fff;border:1px solid #e5e5e5;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,.08);">
            <h1 style="margin:0 0 10px;font-size:20px;color:#333;">' . ($faltaImportar ? 'Base de dados por preencher' : 'Sem ligação à base de dados') . '</h1>
            <p style="margin:0 0 20px;color:#666;font-size:14px;line-height:1.6;">'
            . ($faltaImportar
                ? 'A ligação à base de dados funciona, mas ela está vazia. Falta importar o ficheiro <strong>instalacao/instalacao.sql</strong> — ou correr o instalador, em <strong>instalar/</strong>.'
                : 'A aplicação está instalada, mas não conseguiu falar com a base de dados. Quem administra o servidor deve confirmar os dados em <strong>app/Conecta.php</strong> e o registo em <strong>lib/logs/erros.log</strong>.')
            . '</p>
        ';

    /*
    O motivo REAL só aparece no ecrã em ambiente de desenvolvimento.

    Não se pode usar aqui a regra do _pagina_erro() (mostrar a quem tem
    sessão de administrador): sem base de dados não há sessão nenhuma para
    consultar, e mesmo que houvesse, a mensagem do MySQL costuma trazer o
    nome do utilizador e da base de dados -- não é coisa para mostrar a
    quem calhar passar pelo endereço enquanto o servidor está em baixo.

    Em produção fica só a mensagem de cima; o motivo está no log.
    */
    $emCasa = in_array(($_SERVER['SERVER_NAME'] ?? ''), ['localhost', '127.0.0.1'], true)
              || (($_SERVER['SERVER_ADDR'] ?? '') === '127.0.0.1');

    if ($emCasa && $motivo !== '') {
        echo '
            <div style="margin-top:24px;padding-top:20px;border-top:1px solid #eee;text-align:left;">
                <p style="margin:0 0 8px;font-size:12px;font-weight:bold;color:#999;text-transform:uppercase;letter-spacing:.03em;">Motivo (visível só em desenvolvimento)</p>
                <pre style="margin:0;padding:14px;background:#faf4f4;border:1px solid #f0dede;border-radius:5px;color:#b23b3b;font-size:12px;line-height:1.6;white-space:pre-wrap;word-break:break-word;">' . htmlspecialchars($motivo) . '</pre>
            </div>
        ';
    }

    echo '</div>';
    exit;
}

set_error_handler(function ($nivel, $mensagem, $ficheiro = '', $linha = 0) {
    //respeita @funcao() e error_reporting() mais restritivo definido noutro sítio
    if (!(error_reporting() & $nivel)) {
        return true;
    }
    $tipos = [
        E_WARNING => 'Aviso', E_USER_WARNING => 'Aviso',
        E_NOTICE => 'Nota', E_USER_NOTICE => 'Nota',
        E_DEPRECATED => 'Obsoleto', E_USER_DEPRECATED => 'Obsoleto',
        E_STRICT => 'Strict',
    ];
    _registar_erro($tipos[$nivel] ?? 'Erro', $mensagem, $ficheiro, $linha);
    return true;
});

set_exception_handler(function ($e) {
    _registar_erro('Excepção não apanhada', $e->getMessage() . ' — ' . $e->getTraceAsString(), $e->getFile(), $e->getLine());
    //no ecrã (para administradores, ver _pagina_erro()) só a mensagem — o
    //stack trace completo fica só no log, é longo demais para a página
    _pagina_erro('Excepção não apanhada', $e->getMessage(), $e->getFile(), $e->getLine());
});

register_shutdown_function(function () {
    $erro = error_get_last();
    if ($erro && in_array($erro['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        _registar_erro('Erro fatal', $erro['message'], $erro['file'], $erro['line']);
        if (!headers_sent()) {
            _pagina_erro('Erro fatal', $erro['message'], $erro['file'], $erro['line']);
        }
    }
});
