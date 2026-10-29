
<?php
require_once __DIR__ . '/config.php';

$apiKey = defined('YOUTUBE_API_KEY') ? YOUTUBE_API_KEY : '';
$erro = '';
$avisoVideos = '';
$resultados = [];
$canal = null;
$videos = [];
$maisVisto = null;
$menosVisto = null;
$busca = trim($_GET['q'] ?? '');
$channelId = trim($_GET['channelId'] ?? '');

function e($valor) {
    return htmlspecialchars(
        (string)($valor ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function numero($valor) {
    if ($valor === null || $valor === '') {
        return 'Não disponível';
    }

    return number_format((float)$valor, 0, ',', '.');
}

function dataFormatada($data) {
    if (!$data) {
        return 'Não informada';
    }

    $tempo = strtotime($data);
    return $tempo ? date('d/m/Y', $tempo) : 'Não informada';
}

function resumo($texto, $limite = 180) {
    $texto = trim((string)$texto);

    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($texto, 0, $limite, '...', 'UTF-8');
    }

    return strlen($texto) > $limite
        ? substr($texto, 0, $limite) . '...'
        : $texto;
}

function ytApi($recurso, $parametros) {
    global $apiKey;

    if (!function_exists('curl_init')) {
        return ['error' => 'A extensão cURL não está ativada no PHP.'];
    }

    $parametros['key'] = $apiKey;

    $url = 'https://www.googleapis.com/youtube/v3/'
         . $recurso . '?' . http_build_query($parametros);

    $curl = curl_init($url);

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'FenixYouTubeExplorer/1.0'
    ]);

    $resposta = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $erroCurl = curl_error($curl);

    curl_close($curl);

    if ($resposta === false) {
        return ['error' => 'Falha de conexão: ' . $erroCurl];
    }

    $dados = json_decode($resposta, true);

    if ($status !== 200) {
        $mensagem = $dados['error']['message']
            ?? 'A API retornou HTTP ' . $status . '.';

        return ['error' => $mensagem];
    }

    if (!is_array($dados)) {
        return ['error' => 'A API retornou uma resposta inválida.'];
    }

    return $dados;
}

if ($apiKey === '' || $apiKey === 'SUA_CHAVE_DA_API') {
    $erro = 'Configure sua chave da YouTube Data API no arquivo config.php.';
} else {
    // Se o usuário informou um canal específico, carrega esse canal.
    if ($channelId !== '') {
        $resposta = ytApi('channels', [
            'part' => 'snippet,statistics,contentDetails,brandingSettings',
            'id' => $channelId
        ]);

        if (isset($resposta['error'])) {
            $erro = $resposta['error'];
        } else {
            $canal = $resposta['items'][0] ?? null;

            if (!$canal) {
                $erro = 'Canal não encontrado.';
            }
        }
    } elseif ($busca !== '') {
        // Reconhece links de canais que usam /channel/ID.
        if (preg_match('~youtube\.com/channel/([A-Za-z0-9_-]+)~i', $busca, $m)) {
            $resposta = ytApi('channels', [
                'part' => 'snippet,statistics,contentDetails,brandingSettings',
                'id' => $m[1]
            ]);

            if (isset($resposta['error'])) {
                $erro = $resposta['error'];
            } else {
                $canal = $resposta['items'][0] ?? null;
            }
        } else {
            // Reconhece @handles, inclusive links youtube.com/@handle.
            $handle = null;

            if (preg_match('~youtube\.com/@([^/?]+)|^@([^/?]+)$~i', $busca, $m)) {
                $handle = $m[1] ?? '';

                if ($handle === '') {
                    $handle = $m[2] ?? '';
                }
            }

            if ($handle !== null && $handle !== '') {
                $resposta = ytApi('channels', [
                    'part' => 'snippet,statistics,contentDetails,brandingSettings',
                    'forHandle' => $handle
                ]);

                if (isset($resposta['error'])) {
                    $erro = $resposta['error'];
                } else {
                    $canal = $resposta['items'][0] ?? null;

                    if (!$canal) {
                        $erro = 'Não encontramos um canal com esse identificador.';
                    }
                }
            } else {
                // Pesquisa por nome.
                $resposta = ytApi('search', [
                    'part' => 'snippet',
                    'type' => 'channel',
                    'q' => $busca,
                    'maxResults' => 8
                ]);

                if (isset($resposta['error'])) {
                    $erro = $resposta['error'];
                } else {
                    $ids = [];

                    foreach (($resposta['items'] ?? []) as $item) {
                        $id = $item['id']['channelId'] ?? '';

                        if ($id !== '') {
                            $ids[] = $id;
                        }
                    }

                    if (count($ids) > 0) {
                        $detalhes = ytApi('channels', [
                            'part' => 'snippet,statistics',
                            'id' => implode(',', $ids)
                        ]);

                        if (isset($detalhes['error'])) {
                            $erro = $detalhes['error'];
                        } else {
                            $porId = [];

                            foreach (($detalhes['items'] ?? []) as $item) {
                                $porId[$item['id']] = $item;
                            }

                            // Preserva a ordem da pesquisa.
                            foreach ($ids as $id) {
                                if (isset($porId[$id])) {
                                    $resultados[] = $porId[$id];
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

// Quando um canal é selecionado, carrega seus vídeos públicos recentes.
if ($canal !== null && $erro === '') {
    $playlistId = $canal['contentDetails']['relatedPlaylists']['uploads']
        ?? '';

    if ($playlistId !== '') {
        $lista = ytApi('playlistItems', [
            'part' => 'snippet,contentDetails',
            'playlistId' => $playlistId,
            'maxResults' => 50
        ]);

        if (isset($lista['error'])) {
            $avisoVideos = $lista['error'];
        } else {
            $idsVideos = [];

            foreach (($lista['items'] ?? []) as $item) {
                $id = $item['contentDetails']['videoId'] ?? '';

                if ($id !== '') {
                    $idsVideos[] = $id;
                }
            }

            if (count($idsVideos) > 0) {
                $detalhesVideos = ytApi('videos', [
                    'part' => 'snippet,statistics,contentDetails',
                    'id' => implode(',', $idsVideos),
                    'maxResults' => 50
                ]);

                if (isset($detalhesVideos['error'])) {
                    $avisoVideos = $detalhesVideos['error'];
                } else {
                    $porId = [];

                    foreach (($detalhesVideos['items'] ?? []) as $video) {
                        $porId[$video['id']] = $video;
                    }

                    // Mantém a ordem dos vídeos recentes do canal.
                    foreach ($idsVideos as $id) {
                        if (isset($porId[$id])) {
                            $videos[] = $porId[$id];
                        }
                    }

                    // Calcula os rankings dentro dos vídeos consultados.
                    $ranking = $videos;

                    usort($ranking, function ($a, $b) {
                        $viewsA = (int)($a['statistics']['viewCount'] ?? 0);
                        $viewsB = (int)($b['statistics']['viewCount'] ?? 0);
                        return $viewsA <=> $viewsB;
                    });

                    if (count($ranking) > 0) {
                        $menosVisto = $ranking[0];
                        $maisVisto = $ranking[count($ranking) - 1];
                    }
                }
            }
        }
    } else {
        $avisoVideos = 'Não foi possível localizar a lista de vídeos públicos deste canal.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Fenix YouTube Explorer</title>

    <style>
        :root {
            --fundo: #0f0f12;
            --painel: #19191f;
            --painel2: #222229;
            --borda: #34343e;
            --texto: #f6f6f8;
            --suave: #aaaab5;
            --vermelho: #ff0033;
            --vermelho2: #d9002b;
            --rosa: #ff526e;
            --verde: #4ade80;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background:
                radial-gradient(ellipse at top, #301018 0%, transparent 42%),
                var(--fundo);
            color: var(--texto);
            font-family: Arial, Helvetica, sans-serif;
            min-height: 100vh;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .topo {
            border-bottom: 1px solid #3b2028;
            background: rgba(15, 15, 18, 0.92);
            padding: 20px;
        }

        .topo-interno {
            max-width: 1250px;
            margin: auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        .marca {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 20px;
            font-weight: 800;
            letter-spacing: -0.5px;
        }

        .marca-icone {
            width: 42px;
            height: 42px;
            display: grid;
            place-items: center;
            background: var(--vermelho);
            border-radius: 13px;
            box-shadow: 0 0 25px #ff003344;
        }

        .marca-icone svg {
            width: 22px;
            fill: white;
        }

        .marca span {
            color: var(--vermelho);
        }

        .etiqueta {
            color: #ff8da1;
            background: #ff003318;
            border: 1px solid #ff003344;
            padding: 8px 12px;
            border-radius: 999px;
            font-size: 12px;
        }

        .hero {
            text-align: center;
            padding: 65px 20px 40px;
        }

        .sobretitulo {
            display: inline-block;
            color: #ff8298;
            background: #ff003315;
            border: 1px solid #ff003344;
            padding: 8px 13px;
            border-radius: 999px;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            font-weight: bold;
        }

        .hero h1 {
            font-size: clamp(32px, 5vw, 58px);
            letter-spacing: -2px;
            margin: 22px 0 14px;
        }

        .hero h1 span {
            color: var(--vermelho);
        }

        .hero p {
            color: var(--suave);
            line-height: 1.7;
            max-width: 650px;
            margin: auto;
        }

        .busca {
            display: flex;
            max-width: 760px;
            margin: 30px auto 0;
            padding: 7px;
            gap: 8px;
            border: 1px solid #49404a;
            border-radius: 15px;
            background: #19191f;
            box-shadow: 0 15px 55px #00000055;
        }

        .busca input {
            flex: 1;
            min-width: 0;
            padding: 15px;
            background: transparent;
            border: 0;
            outline: none;
            color: white;
            font-size: 15px;
        }

        .busca input::placeholder {
            color: #858590;
        }

        .botao {
            background: var(--vermelho);
            color: white;
            border: 0;
            border-radius: 10px;
            padding: 14px 22px;
            cursor: pointer;
            font-weight: bold;
            transition: 0.2s;
        }

        .botao:hover {
            background: #ff3159;
            box-shadow: 0 0 24px #ff003344;
            transform: translateY(-1px);
        }

        main {
            max-width: 1250px;
            padding: 0 20px 70px;
            margin: auto;
        }

        .secao-titulo {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin: 30px 0 18px;
        }

        .secao-titulo h2 {
            margin: 0;
            font-size: 23px;
        }

        .secao-titulo p {
            color: var(--suave);
            margin: 7px 0 0;
            font-size: 13px;
        }

        .grade-canais {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 18px;
        }

        .canal-card {
            background: linear-gradient(145deg, #202027, #17171c);
            border: 1px solid var(--borda);
            border-radius: 17px;
            padding: 22px;
            transition: 0.25s;
        }

        .canal-card:hover {
            border-color: #ff003399;
            transform: translateY(-4px);
            box-shadow: 0 12px 35px #00000040;
        }

        .avatar {
            width: 78px;
            height: 78px;
            border-radius: 50%;
            object-fit: cover;
            background: var(--painel2);
            border: 2px solid #ff003366;
        }

        .canal-card h3 {
            margin: 15px 0 8px;
            font-size: 18px;
        }

        .descricao {
            color: var(--suave);
            font-size: 13px;
            line-height: 1.6;
        }

        .mini-estatisticas {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            margin: 17px 0;
            color: #dddde4;
            font-size: 12px;
        }

        .abrir {
            display: block;
            text-align: center;
            padding: 12px;
            border-radius: 10px;
            background: #ff00331b;
            color: #ff8da1;
            border: 1px solid #ff003344;
            font-weight: bold;
            font-size: 13px;
        }

        .abrir:hover {
            background: var(--vermelho);
            color: white;
        }

        .mensagem {
            margin: 25px 0;
            padding: 18px 20px;
            background: var(--painel);
            border: 1px solid var(--borda);
            border-left: 4px solid var(--vermelho);
            border-radius: 12px;
            line-height: 1.6;
            color: #dedee5;
        }

        .mensagem.erro {
            border-left-color: #ff526e;
        }

        .banner {
            position: relative;
            height: 230px;
            border-radius: 20px;
            overflow: hidden;
            background:
                radial-gradient(ellipse at 20% 20%, #ff003355, transparent 55%),
                linear-gradient(115deg, #42101d, #17171d 70%);
            border: 1px solid #4b2731;
            margin-top: 20px;
        }

        .banner img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            opacity: 0.6;
        }

        .banner::after {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(0deg, #111116 0%, transparent 90%);
        }

        .perfil {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: end;
            gap: 22px;
            margin: -68px 25px 28px;
        }

        .perfil-avatar {
            width: 135px;
            height: 135px;
            object-fit: cover;
            border-radius: 50%;
            background: var(--painel2);
            border: 5px solid var(--fundo);
            box-shadow: 0 5px 25px #0008;
        }

        .perfil-info {
            padding-bottom: 8px;
            min-width: 0;
        }

        .perfil-info h2 {
            font-size: clamp(22px, 4vw, 34px);
            margin: 0 0 8px;
            overflow-wrap: anywhere;
        }

        .perfil-info p {
            color: var(--suave);
            line-height: 1.5;
            margin: 0;
            overflow-wrap: anywhere;
        }

        .pais {
            display: inline-flex;
            margin-top: 10px;
            padding: 7px 10px;
            background: #4ade8015;
            color: #8df5ae;
            border: 1px solid #4ade8033;
            border-radius: 8px;
            font-size: 12px;
        }

        .pais.desconhecido {
            background: #ffffff0a;
            border-color: var(--borda);
            color: var(--suave);
        }

        .estatisticas {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 15px;
            margin: 24px 0 35px;
        }

        .stat {
            background: linear-gradient(145deg, #202027, #17171c);
            border: 1px solid var(--borda);
            padding: 22px;
            border-radius: 15px;
        }

        .stat-topo {
            color: var(--suave);
            font-size: 13px;
        }

        .stat-valor {
            display: block;
            margin-top: 12px;
            font-size: clamp(22px, 3vw, 30px);
            font-weight: 800;
            overflow-wrap: anywhere;
        }

        .stat-destaque {
            color: #ff526e;
        }

        .sobre {
            background: var(--painel);
            border: 1px solid var(--borda);
            padding: 22px;
            border-radius: 15px;
            color: #d4d4dc;
            line-height: 1.7;
            white-space: pre-line;
            overflow-wrap: anywhere;
        }

        .rank-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .rank-card {
            background: var(--painel);
            border: 1px solid var(--borda);
            border-radius: 15px;
            overflow: hidden;
        }

        .rank-card h3 {
            font-size: 15px;
            padding: 17px 18px;
            margin: 0;
            border-bottom: 1px solid var(--borda);
        }

        .rank-card h3 span {
            color: #ff526e;
            margin-right: 7px;
        }

        .video-destaque {
            display: flex;
            gap: 15px;
            padding: 16px;
        }

        .video-destaque img {
            width: 48%;
            aspect-ratio: 16 / 9;
            object-fit: cover;
            border-radius: 9px;
            background: var(--painel2);
        }

        .video-destaque h4 {
            font-size: 14px;
            line-height: 1.5;
            margin: 0 0 10px;
        }

        .video-destaque p {
            color: var(--suave);
            font-size: 12px;
            margin: 0 0 8px;
        }

        .lista-videos {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(225px, 1fr));
            gap: 18px;
        }

        .video-card {
            overflow: hidden;
            background: var(--painel);
            border: 1px solid var(--borda);
            border-radius: 13px;
            transition: 0.2s;
        }

        .video-card:hover {
            border-color: #ff003388;
            transform: translateY(-3px);
        }

        .video-card img {
            display: block;
            width: 100%;
            aspect-ratio: 16 / 9;
            object-fit: cover;
            background: var(--painel2);
        }

        .video-corpo {
            padding: 14px;
        }

        .video-corpo h3 {
            font-size: 14px;
            line-height: 1.5;
            margin: 0 0 12px;
        }

        .video-corpo p {
            font-size: 12px;
            color: var(--suave);
            margin: 7px 0;
        }

        .rodape {
            text-align: center;
            padding: 28px 20px;
            border-top: 1px solid var(--borda);
            color: #777783;
            font-size: 12px;
            line-height: 1.7;
        }

        @media (max-width: 650px) {
            .topo-interno {
                flex-wrap: wrap;
            }

            .etiqueta {
                display: none;
            }

            .hero {
                padding-top: 45px;
            }

            .busca {
                flex-direction: column;
            }

            .busca .botao {
                width: 100%;
            }

            .perfil {
                flex-direction: column;
                align-items: flex-start;
                margin-top: -55px;
                gap: 12px;
            }

            .perfil-avatar {
                width: 110px;
                height: 110px;
            }

            .banner {
                height: 170px;
            }

            .rank-grid {
                grid-template-columns: 1fr;
            }

            .video-destaque {
                flex-direction: column;
            }

            .video-destaque img {
                width: 100%;
            }
        }
    </style>
</head>

<body>
<header class="topo">
    <div class="topo-interno">
        <a href="index.php" class="marca">
            <span class="marca-icone">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M23.5 6.2a3 3 0 0 0-2.1-2.1C19.5 3.6 12 3.6 12 3.6s-7.5 0-9.4.5A3 3 0 0 0 .5 6.2 31 31 0 0 0 0 12a31 31 0 0 0 .5 5.8 3 3 0 0 0 2.1 2.1c1.9.5 9.4.5 9.4.5s7.5 0 9.4-.5a3 3 0 0 0 2.1-2.1A31 31 0 0 0 24 12a31 31 0 0 0-.5-5.8ZM9.6 15.6V8.4l6.3 3.6-6.3 3.6Z"/>
                </svg>
            </span>
            FENIX <span>YT</span>
        </a>

        <span class="etiqueta">CHANNEL INTELLIGENCE</span>
    </div>
</header>

<section class="hero">
    <span class="sobretitulo">YouTube Analytics Explorer</span>
    <h1>Explore canais.<br><span>Descubra números.</span></h1>
    <p>
        Pesquise canais do YouTube e explore seus dados públicos,
        estatísticas e vídeos em um painel moderno.
    </p>

    <form class="busca" method="GET" action="index.php">
        <input
            type="search"
            name="q"
            placeholder="Nome, @handle ou link do canal..."
            value="<?= e($busca) ?>"
            required
        >
        <button class="botao" type="submit">⌕ Pesquisar canal</button>
    </form>
</section>

<main>
    <?php if ($erro !== ''): ?>
        <div class="mensagem erro">
            <strong>Não foi possível concluir a consulta.</strong>
            <p><?= e($erro) ?></p>
            <p>
                Confira sua chave de API, se a YouTube Data API v3 está
                ativada e se a cota disponível não foi excedida.
            </p>
        </div>
    <?php endif; ?>

    <?php if ($busca !== '' && count($resultados) > 0): ?>
        <div class="secao-titulo">
            <div>
                <h2>Resultados da pesquisa</h2>
                <p>Escolha um canal para ver a análise completa.</p>
            </div>
            <span class="etiqueta"><?= count($resultados) ?> canais</span>
        </div>

        <section class="grade-canais">
            <?php foreach ($resultados as $item): ?>
                <?php
                    $id = $item['id'] ?? '';
                    $sn = $item['snippet'] ?? [];
                    $st = $item['statistics'] ?? [];
                    $avatar = $sn['thumbnails']['high']['url']
                        ?? $sn['thumbnails']['default']['url'] ?? '';
                ?>

                <article class="canal-card">
                    <?php if ($avatar !== ''): ?>
                        <img class="avatar" src="<?= e($avatar) ?>"
                             alt="Imagem de <?= e($sn['title'] ?? 'canal') ?>">
                    <?php endif; ?>

                    <h3><?= e($sn['title'] ?? 'Canal sem título') ?></h3>
                    <p class="descricao">
                        <?= e(resumo($sn['description'] ?? '', 150)) ?>
                    </p>

                    <div class="mini-estatisticas">
                        <span>👥 <?= e(numero($st['subscriberCount'] ?? null)) ?> inscritos</span>
                        <span>▶ <?= e(numero($st['videoCount'] ?? null)) ?> vídeos</span>
                    </div>

                    <a class="abrir" href="?channelId=<?= e($id) ?>">
                        Analisar canal →
                    </a>
                </article>
            <?php endforeach; ?>
        </section>
    <?php elseif ($busca !== '' && $erro === '' && $canal === null): ?>
        <div class="mensagem">
            Nenhum canal encontrado para <strong><?= e($busca) ?></strong>.
            Tente outro nome ou o identificador @handle.
        </div>
    <?php endif; ?>

    <?php if ($canal !== null && $erro === ''): ?>
        <?php
            $sn = $canal['snippet'] ?? [];
            $st = $canal['statistics'] ?? [];
            $branding = $canal['brandingSettings']['image'] ?? [];
            $avatar = $sn['thumbnails']['high']['url']
                ?? $sn['thumbnails']['medium']['url']
                ?? $sn['thumbnails']['default']['url']
                ?? '';
            $banner = $branding['bannerExternalUrl'] ?? '';
            $pais = $sn['country'] ?? '';
            $inscritosOcultos = $st['hiddenSubscriberCount'] ?? false;
            $canalUrl = 'https://www.youtube.com/channel/' . ($canal['id'] ?? '');
            $descricao = $sn['description'] ?? '';
        ?>

        <div class="secao-titulo">
            <div>
                <h2>Visão geral do canal</h2>
                <p>Dados públicos retornados pela YouTube Data API.</p>
            </div>
            <a class="etiqueta" href="<?= e($canalUrl) ?>"
               target="_blank" rel="noopener noreferrer">Abrir no YouTube ↗</a>
        </div>

       <div class="banner">
            <?php if ($banner !== ''): ?>
                <img
                    src="<?= e($banner) ?>"
                    alt=""
                    onerror="this.remove()"
                >
            <?php endif; ?>
        </div>

        <section class="perfil">
            <?php if ($avatar !== ''): ?>
                <img
                    class="perfil-avatar"
                    src="<?= e($avatar) ?>"
                    alt="Imagem do canal"
                    onerror="this.style.visibility='hidden'"
                >
            <?php endif; ?>

            <div class="perfil-info">
                <h2><?= e($sn['title'] ?? 'Canal do YouTube') ?></h2>
                <p>
                    Criado em <?= e(dataFormatada($sn['publishedAt'] ?? '')) ?>
                    · ID: <?= e($canal['id'] ?? '') ?>
                </p>

                <?php if ($pais === 'BR'): ?>
                    <span class="pais">🇧🇷 País informado: Brasil</span>
                <?php elseif ($pais !== ''): ?>
                    <span class="pais">🌎 País informado: <?= e($pais) ?></span>
                <?php else: ?>
                    <span class="pais desconhecido">🌐 País não informado</span>
                <?php endif; ?>
            </div>
        </section>

        <section class="estatisticas">
            <article class="stat">
                <span class="stat-topo">Inscritos</span>
                <strong class="stat-valor stat-destaque">
                    <?php if ($inscritosOcultos): ?>
                        Ocultos
                    <?php else: ?>
                        <?= e(numero($st['subscriberCount'] ?? null)) ?>
                    <?php endif; ?>
                </strong>
            </article>

            <article class="stat">
                <span class="stat-topo">Vídeos públicos</span>
                <strong class="stat-valor">
                    <?= e(numero($st['videoCount'] ?? null)) ?>
                </strong>
            </article>

            <article class="stat">
                <span class="stat-topo">Visualizações totais</span>
                <strong class="stat-valor">
                    <?= e(numero($st['viewCount'] ?? null)) ?>
                </strong>
            </article>

            <article class="stat">
                <span class="stat-topo">Vídeos analisados</span>
                <strong class="stat-valor">
                    <?= e(numero(count($videos))) ?>
                </strong>
            </article>
        </section>

        <div class="secao-titulo">
            <div>
                <h2>Sobre o canal</h2>
                <p>Descrição fornecida publicamente pelo canal.</p>
            </div>
        </div>

        <div class="sobre">
            <?= e($descricao !== '' ? $descricao : 'Este canal não possui descrição pública.') ?>
        </div>

        <div class="secao-titulo">
            <div>
                <h2>Recordes de visualizações</h2>
                <p>Comparação dos vídeos públicos recentes que conseguimos consultar.</p>
            </div>
        </div>

        <?php if ($avisoVideos !== ''): ?>
            <div class="mensagem">
                Não foi possível carregar todos os dados dos vídeos:
                <?= e($avisoVideos) ?>
            </div>
        <?php endif; ?>

        <?php if ($maisVisto !== null && $menosVisto !== null): ?>
            <section class="rank-grid">
                <?php foreach ([
                    ['titulo' => '🔥 Mais visto entre os analisados', 'video' => $maisVisto],
                    ['titulo' => '📉 Menos visto entre os analisados', 'video' => $menosVisto]
                ] as $destaque): ?>
                    <?php
                        $v = $destaque['video'];
                        $vs = $v['snippet'] ?? [];
                        $thumb = $vs['thumbnails']['high']['url']
                            ?? $vs['thumbnails']['medium']['url']
                            ?? $vs['thumbnails']['default']['url'] ?? '';
                    ?>

                    <article class="rank-card">
                        <h3><?= e($destaque['titulo']) ?></h3>
                        <div class="video-destaque">
                            <a href="https://www.youtube.com/watch?v=<?= e($v['id']) ?>"
                               target="_blank" rel="noopener noreferrer">
                                <?php if ($thumb !== ''): ?>
                                    <img src="<?= e($thumb) ?>" alt="Miniatura do vídeo">
                                <?php endif; ?>
                            </a>

                            <div>
                                <h4>
                                    <a href="https://www.youtube.com/watch?v=<?= e($v['id']) ?>"
                                       target="_blank" rel="noopener noreferrer">
                                        <?= e($vs['title'] ?? 'Vídeo sem título') ?>
                                    </a>
                                </h4>
                                <p>👁 <?= e(numero($v['statistics']['viewCount'] ?? 0)) ?> visualizações</p>
                                <p>👍 <?= e(numero($v['statistics']['likeCount'] ?? null)) ?> curtidas</p>
                                <p>📅 <?= e(dataFormatada($vs['publishedAt'] ?? '')) ?></p>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php elseif ($avisoVideos === ''): ?>
            <div class="mensagem">Não encontramos vídeos públicos para analisar.</div>
        <?php endif; ?>

        <div class="secao-titulo">
            <div>
                <h2>Vídeos recentes</h2>
                <p>Até 50 vídeos públicos recentes retornados pela API.</p>
            </div>
            <span class="etiqueta"><?= count($videos) ?> carregados</span>
        </div>

        <?php if (count($videos) > 0): ?>
            <section class="lista-videos">
                <?php foreach ($videos as $v): ?>
                    <?php
                        $vs = $v['snippet'] ?? [];
                        $thumb = $vs['thumbnails']['high']['url']
                            ?? $vs['thumbnails']['medium']['url']
                            ?? $vs['thumbnails']['default']['url'] ?? '';
                    ?>

                    <article class="video-card">
                        <a href="https://www.youtube.com/watch?v=<?= e($v['id']) ?>"
                           target="_blank" rel="noopener noreferrer">
                            <?php if ($thumb !== ''): ?>
                                <img src="<?= e($thumb) ?>" loading="lazy"
                                     alt="Miniatura de <?= e($vs['title'] ?? 'vídeo') ?>">
                            <?php endif; ?>
                        </a>

                        <div class="video-corpo">
                            <h3>
                                <a href="https://www.youtube.com/watch?v=<?= e($v['id']) ?>"
                                   target="_blank" rel="noopener noreferrer">
                                    <?= e($vs['title'] ?? 'Vídeo sem título') ?>
                                </a>
                            </h3>

                            <p>👁 <?= e(numero($v['statistics']['viewCount'] ?? 0)) ?> visualizações</p>
                            <p>👍 <?= e(numero($v['statistics']['likeCount'] ?? null)) ?> curtidas</p>
                            <p>💬 <?= e(numero($v['statistics']['commentCount'] ?? null)) ?> comentários</p>
                            <p>📅 <?= e(dataFormatada($vs['publishedAt'] ?? '')) ?></p>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <div class="mensagem">
            <strong>Como interpretar os números:</strong>
            o ranking de mais e menos vistos considera apenas os vídeos públicos
            recentes que foram carregados nesta consulta, e não necessariamente
            todo o histórico do canal. A contagem de inscritos fornecida pela API
            pode ser arredondada pelo YouTube.
        </div>
    <?php endif; ?>
</main>

<footer class="rodape">
    FENIX YT · YouTube Channel Explorer<br>
    Projeto independente para consulta de dados públicos.
    Não é afiliado nem patrocinado pelo YouTube ou Google.
</footer>
</body>
</html>
