<?php
require_once 'api/config.php';

$token = $_GET['token'] ?? '';
if (!$token) {
    die('<p style="font-family:sans-serif;color:red;">Acesso negado. Abra pelo app.</p>');
}
$payload = verifyToken($token);
if (!$payload) {
    die('<p style="font-family:sans-serif;color:red;">Token inválido ou expirado. Faça login novamente.</p>');
}

$db = getDB();

$filtMes  = $_GET['mes']  ?? date('Y-m');
$filtCamp = $_GET['camp'] ?? 'todos';

function fmtMes($m) {
    $meses = ['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
    [$y, $mo] = explode('-', $m);
    return $meses[(int)$mo - 1] . '/' . $y;
}

function R($n) {
    $v = round(floatval($n), 3);
    if (fmod($v * 1000, 10) != 0) {
        return 'R$ ' . number_format($v, 3, ',', '.');
    }
    return 'R$ ' . number_format($v, 2, ',', '.');
}

$camps = $db->query("SELECT id, nome FROM campeonatos WHERE ativo=1 ORDER BY nome")->fetchAll();

$resultado = [];
foreach ($camps as $camp) {
    $cId = $camp['id'];
    if ($filtCamp !== 'todos' && $cId !== $filtCamp) continue;

    // Separa jogos normais e de férias
    $stmtJ = $db->prepare("SELECT id, data_jogo, custo_jogo, custo_tecnico, valor_avulsa, COALESCE(mes_ferias,0) AS mes_ferias FROM jogos WHERE campeonato_id=? AND mes_referencia=? ORDER BY data_jogo");
    $stmtJ->execute([$cId, $filtMes]);
    $todosJogos = $stmtJ->fetchAll();
    if (!$todosJogos) continue;

    $jogos       = array_values(array_filter($todosJogos, fn($j) => !$j['mes_ferias']));
    $jogosFerias = array_values(array_filter($todosJogos, fn($j) =>  $j['mes_ferias']));

    // Custo e receita apenas de jogos normais
    $custoTotal = array_sum(array_map(fn($j) => $j['custo_jogo'] + $j['custo_tecnico'], $jogos));

    // Receita de avulsas — apenas tipo='avulsa' (exclui férias)
    $stmtRec = $db->prepare("SELECT COALESCE(SUM(p.valor),0) FROM participacoes p JOIN jogos j ON j.id=p.jogo_id WHERE j.campeonato_id=? AND j.mes_referencia=? AND p.tipo='avulsa'");
    $stmtRec->execute([$cId, $filtMes]);
    $recAvulsas = floatval($stmtRec->fetchColumn());

    $liquido = $custoTotal - $recAvulsas;

    // Mensalistas (jogos normais)
    $mensalistas = [];
    if ($jogos) {
        $stmtM = $db->prepare("
            SELECT a.id, a.nome, i.status, i.status_desde, i.retorno_desde
            FROM atletas a JOIN inscricoes i ON i.atleta_id=a.id
            WHERE i.campeonato_id=? AND i.tipo='mensalista'
            AND (i.status='ativa' OR (i.status IN ('ausente','inativa') AND i.status_desde IS NOT NULL))
            ORDER BY a.nome
        ");
        $stmtM->execute([$cId]);
        $candidatos = $stmtM->fetchAll();

        $totalJogos = count($jogos);
        $ativos   = [];
        $parciais = [];
        foreach ($candidatos as $a) {
            $desde   = $a['status_desde']  ?? null;
            $retorno = $a['retorno_desde'] ?? null;

            if ($a['status'] === 'ativa' && !$retorno) {
                $ativos[] = $a;
            } elseif ($a['status'] === 'ativa' && $retorno) {
                $jogosForaAusencia = count(array_filter($jogos, function($j) use ($desde, $retorno) {
                    return (!$desde || $j['data_jogo'] < $desde) || $j['data_jogo'] >= $retorno;
                }));
                if ($jogosForaAusencia > 0 && $totalJogos > 0)
                    $parciais[] = ['id' => $a['id'], 'nome' => $a['nome'], 'fracao' => $jogosForaAusencia / $totalJogos, 'tipo_label' => 'Mensalista (retorno)'];
            } else {
                $jogosAntes = $desde ? count(array_filter($jogos, fn($j) => $j['data_jogo'] < $desde)) : 0;
                if ($jogosAntes > 0) {
                    $fracao = $a['status'] === 'inativa' ? 1.0 : ($totalJogos > 0 ? $jogosAntes / $totalJogos : 0);
                    $parciais[] = ['id' => $a['id'], 'nome' => $a['nome'], 'fracao' => $fracao, 'tipo_label' => 'Mensalista (prop.)'];
                }
            }
        }

        $somFracoes = array_sum(array_column($parciais, 'fracao'));
        $divisor    = count($ativos) + $somFracoes;
        $cotaBase   = $divisor > 0 ? $liquido / $divisor : 0;

        $stmtPag = $db->prepare("SELECT atleta_id, pago, valor FROM fechamentos WHERE campeonato_id=? AND mes_referencia=?");
        $stmtPag->execute([$cId, $filtMes]);
        $pagoMap = [];
        foreach ($stmtPag->fetchAll() as $p) $pagoMap[$p['atleta_id']] = $p;

        foreach ($ativos as $a) {
            $pago = isset($pagoMap[$a['id']]) && $pagoMap[$a['id']]['pago'];
            $mensalistas[] = ['nome' => $a['nome'], 'valor' => round($cotaBase, 3), 'pago' => $pago, 'tipo' => 'Mensalista'];
        }
        foreach ($parciais as $p) {
            $valor = round($cotaBase * $p['fracao'], 3);
            if ($valor > 0) {
                $pago = isset($pagoMap[$p['id']]) && $pagoMap[$p['id']]['pago'];
                $mensalistas[] = ['nome' => $p['nome'], 'valor' => $valor, 'pago' => $pago, 'tipo' => $p['tipo_label']];
            }
        }
    } else {
        $cotaBase = 0;
        $stmtPag = $db->prepare("SELECT atleta_id, pago, valor FROM fechamentos WHERE campeonato_id=? AND mes_referencia=?");
        $stmtPag->execute([$cId, $filtMes]);
        $pagoMap = [];
        foreach ($stmtPag->fetchAll() as $p) $pagoMap[$p['atleta_id']] = $p;
    }

    // Avulsas — apenas tipo='avulsa', valor > 0
    $stmtAv = $db->prepare("
        SELECT a.id, a.nome, SUM(p.valor) as total
        FROM participacoes p JOIN atletas a ON a.id=p.atleta_id JOIN jogos j ON j.id=p.jogo_id
        WHERE j.campeonato_id=? AND j.mes_referencia=? AND p.tipo='avulsa'
        GROUP BY a.id, a.nome ORDER BY a.nome
    ");
    $stmtAv->execute([$cId, $filtMes]);
    $avulsas = [];
    foreach ($stmtAv->fetchAll() as $a) {
        if (floatval($a['total']) <= 0) continue;
        $pago = isset($pagoMap[$a['id']]) && $pagoMap[$a['id']]['pago'];
        $avulsas[] = ['nome' => $a['nome'], 'total' => $a['total'], 'pago' => $pago];
    }

    // Cobranças de férias — rateio por presença
    $ferias = [];
    foreach ($jogosFerias as $jf) {
        $stmtPres = $db->prepare("SELECT p.atleta_id, a.nome, ($jf[custo_jogo] + $jf[custo_tecnico]) AS custo_jogo FROM participacoes p JOIN atletas a ON a.id=p.atleta_id WHERE p.jogo_id=? AND p.tipo='ferias'");
        $stmtPres->execute([$jf['id']]);
        $presentes = $stmtPres->fetchAll();
        $n = count($presentes);
        if ($n === 0) continue;
        $parte = ($jf['custo_jogo'] + $jf['custo_tecnico']) / $n;
        foreach ($presentes as $pr) {
            $aid = $pr['atleta_id'];
            if (!isset($ferias[$aid])) $ferias[$aid] = ['nome' => $pr['nome'], 'total' => 0, 'pago' => isset($pagoMap[$aid]) && $pagoMap[$aid]['pago']];
            $ferias[$aid]['total'] += $parte;
        }
    }
    $ferias = array_values($ferias);
    usort($ferias, fn($a,$b) => strcmp($a['nome'],$b['nome']));
    foreach ($ferias as &$f) $f['total'] = round($f['total'], 3);
    unset($f);

    $totalPend = array_sum(array_column(array_filter($mensalistas, fn($m) => !$m['pago']), 'valor'))
               + array_sum(array_column(array_filter($avulsas,    fn($a) => !$a['pago']), 'total'))
               + array_sum(array_column(array_filter($ferias,     fn($f) => !$f['pago']), 'total'));
    $totalPago = array_sum(array_column(array_filter($mensalistas, fn($m) => $m['pago']), 'valor'))
               + array_sum(array_column(array_filter($avulsas,    fn($a) => $a['pago']), 'total'))
               + array_sum(array_column(array_filter($ferias,     fn($f) => $f['pago']), 'total'));

    $nParticipantes = count($mensalistas) + count($avulsas) + count($ferias);

    $resultado[] = [
        'nome'            => $camp['nome'],
        'jogos'           => count($jogos),
        'jogos_ferias'    => count($jogosFerias),
        'custo'           => $custoTotal,
        'avulsas_rec'     => $recAvulsas,
        'cota'            => $cotaBase,
        'n_participantes' => $nParticipantes,
        'mensalistas'     => $mensalistas,
        'avulsas'         => $avulsas,
        'ferias'          => $ferias,
        'total_pend'      => $totalPend,
        'total_pago'      => $totalPago,
    ];
}

$campNome  = $filtCamp === 'todos' ? 'Todos os campeonatos' : ($camps[array_search($filtCamp, array_column($camps, 'id'))]['nome'] ?? $filtCamp);
$geralPend = array_sum(array_column($resultado, 'total_pend'));
$geralPago = array_sum(array_column($resultado, 'total_pago'));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Relatório Vôlei — <?= fmtMes($filtMes) ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: Arial, sans-serif; font-size: 13px; color: #1a1a2e; padding: 24px 32px; }
.header { margin-bottom: 20px; border-bottom: 2px solid #378ADD; padding-bottom: 12px; }
.header h1 { font-size: 20px; color: #185FA5; }
.header .sub { font-size: 13px; color: #888; margin-top: 4px; }
.camp { margin-bottom: 28px; }
.camp h2 { font-size: 15px; color: #185FA5; margin-bottom: 6px; }
.resumo { background: #f0f4fa; padding: 8px 12px; border-radius: 6px; font-size: 12px; color: #555; margin-bottom: 10px; }
.resumo-ferias { background: #FFF8E6; padding: 8px 12px; border-radius: 6px; font-size: 12px; color: #854F0B; margin-bottom: 10px; }
table { width: 100%; border-collapse: collapse; font-size: 12px; margin-bottom: 8px; }
th { text-align: left; padding: 7px 10px; background: #e8eef8; border-bottom: 2px solid #c0cee8; }
td { padding: 6px 10px; border-bottom: 1px solid #f0f0f0; }
tr:last-child td { border-bottom: none; }
.pago { color: #3B6D11; font-weight: 600; }
.pendente { color: #A32D2D; font-weight: 600; }
.camp-resumo { display: flex; gap: 12px; margin-top: 8px; }
.cr-box { flex: 1; padding: 8px 12px; border-radius: 6px; text-align: center; }
.cr-pend { background: #FCEBEB; }
.cr-pago { background: #EAF3DE; }
.cr-box .lbl { font-size: 11px; color: #888; }
.cr-box .val { font-size: 16px; font-weight: 700; margin-top: 2px; }
.geral { margin-top: 24px; padding: 14px 16px; background: #f8f8fa; border-radius: 8px; border: 1px solid #eee; }
.geral h3 { font-size: 14px; margin-bottom: 10px; }
.geral-vals { display: flex; gap: 16px; }
.gv { flex: 1; text-align: center; }
.gv .lbl { font-size: 11px; color: #888; }
.gv .val { font-size: 20px; font-weight: 700; margin-top: 2px; }
.footer { margin-top: 24px; padding-top: 10px; border-top: 1px solid #eee; font-size: 11px; color: #aaa; display: flex; justify-content: space-between; }
.btn-print { display: inline-block; margin-bottom: 16px; padding: 10px 20px; background: #378ADD; color: white; border: none; border-radius: 8px; font-size: 14px; cursor: pointer; font-weight: 600; }
.th-ferias { background: #FFF0C2; }
@media print {
    .btn-print { display: none; }
    body { padding: 10px 16px; }
}
</style>
</head>
<body>

<button class="btn-print" onclick="window.print()">🖨️ Imprimir / Salvar como PDF</button>

<div class="header">
    <h1>🏐 Vôlei Master — Relatório de Cobranças</h1>
    <div class="sub"><?= htmlspecialchars($campNome) ?> &nbsp;·&nbsp; <?= fmtMes($filtMes) ?></div>
</div>

<?php if (empty($resultado)): ?>
    <p style="color:#888;padding:20px 0;">Nenhum jogo registrado neste período.</p>
<?php else: ?>

<?php foreach ($resultado as $c): ?>
<div class="camp">
    <h2>🏆 <?= htmlspecialchars($c['nome']) ?></h2>

    <?php if ($c['jogos'] > 0): ?>
    <div class="resumo">
        <?= $c['n_participantes'] ?> participante<?= $c['n_participantes'] != 1 ? 's' : '' ?> &nbsp;·&nbsp;
        <?= $c['jogos'] ?> jogo(s) &nbsp;·&nbsp;
        Custo: <?= R($c['custo']) ?>
        <?php if ($c['avulsas_rec'] > 0): ?> &nbsp;·&nbsp; Avulsas: -<?= R($c['avulsas_rec']) ?><?php endif; ?>
        &nbsp;·&nbsp; Cota: <?= R($c['cota']) ?> por participante
    </div>
    <?php endif; ?>

    <?php if ($c['jogos_ferias'] > 0): ?>
    <div class="resumo-ferias">
        🏖️ <?= $c['jogos_ferias'] ?> jogo(s) de férias &nbsp;·&nbsp; Custo rateado entre os presentes
    </div>
    <?php endif; ?>

    <?php if (!empty($c['mensalistas']) || !empty($c['avulsas'])): ?>
    <table>
        <tr><th>Atleta</th><th>Tipo</th><th>Valor</th><th>Status</th></tr>
        <?php foreach ($c['mensalistas'] as $m): ?>
        <tr>
            <td><?= htmlspecialchars($m['nome']) ?></td>
            <td><?= $m['tipo'] ?></td>
            <td><?= R($m['valor']) ?></td>
            <td class="<?= $m['pago'] ? 'pago' : 'pendente' ?>"><?= $m['pago'] ? '✅ Pago' : '❌ Pendente' ?></td>
        </tr>
        <?php endforeach; ?>
        <?php foreach ($c['avulsas'] as $a): ?>
        <tr>
            <td><?= htmlspecialchars($a['nome']) ?></td>
            <td>Avulsa</td>
            <td><?= R($a['total']) ?></td>
            <td class="<?= $a['pago'] ? 'pago' : 'pendente' ?>"><?= $a['pago'] ? '✅ Pago' : '❌ Pendente' ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <?php if (!empty($c['ferias'])): ?>
    <table style="margin-top:<?= (!empty($c['mensalistas']) || !empty($c['avulsas'])) ? '12px' : '0' ?>;">
        <tr><th class="th-ferias" colspan="4">🏖️ Mês de Férias — Rateio por presença</th></tr>
        <tr><th>Atleta</th><th>Tipo</th><th>Valor</th><th>Status</th></tr>
        <?php foreach ($c['ferias'] as $f): ?>
        <tr>
            <td><?= htmlspecialchars($f['nome']) ?></td>
            <td>Férias</td>
            <td><?= R($f['total']) ?></td>
            <td class="<?= $f['pago'] ? 'pago' : 'pendente' ?>"><?= $f['pago'] ? '✅ Pago' : '❌ Pendente' ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <div class="camp-resumo">
        <div class="cr-box cr-pend">
            <div class="lbl">Pendente</div>
            <div class="val" style="color:#A32D2D;"><?= R($c['total_pend']) ?></div>
        </div>
        <div class="cr-box cr-pago">
            <div class="lbl">Recebido</div>
            <div class="val" style="color:#3B6D11;"><?= R($c['total_pago']) ?></div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<div class="geral">
    <h3>💰 Resumo Geral</h3>
    <div class="geral-vals">
        <div class="gv"><div class="lbl">Total pendente</div><div class="val" style="color:#A32D2D;"><?= R($geralPend) ?></div></div>
        <div class="gv"><div class="lbl">Total recebido</div><div class="val" style="color:#3B6D11;"><?= R($geralPago) ?></div></div>
        <div class="gv"><div class="lbl">Total geral</div><div class="val"><?= R($geralPend + $geralPago) ?></div></div>
    </div>
</div>

<?php endif; ?>

<div class="footer">
    <span>Vôlei Master</span>
    <span>Gerado em <?= date('d/m/Y H:i') ?></span>
</div>

</body>
</html>
