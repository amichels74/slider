<?php
require_once 'api/config.php';

$token = $_GET['token'] ?? '';
if (!$token) die('<p style="font-family:sans-serif;color:red;">Acesso negado.</p>');
$payload = verifyToken($token);
if (!$payload) die('<p style="font-family:sans-serif;color:red;">Token inválido ou expirado.</p>');

$db = getDB();

$campId   = $_GET['camp']   ?? '';
$statuses = $_GET['status'] ?? 'mensalista,avulsa,afastada,organizadora';
$statusArr = array_filter(array_map('trim', explode(',', $statuses)));

// Campeonato
$camp = null;
if ($campId) {
    $s = $db->prepare("SELECT id, nome FROM campeonatos WHERE id = ?");
    $s->execute([$campId]);
    $camp = $s->fetch();
}

// Todos os campeonatos ativos (para header de colunas ou filtro)
$camps = $db->query("SELECT id, nome FROM campeonatos WHERE ativo=1 ORDER BY nome")->fetchAll();

// Mapeamento status → label/cor
$statusMeta = [
    'mensalista'  => ['label'=>'Mensalista',  'bg'=>'#1a3f8f', 'color'=>'white'],
    'avulsa'      => ['label'=>'Avulsa',       'bg'=>'#D1FAE5', 'color'=>'#065F46'],
    'afastada'    => ['label'=>'Afastada',     'bg'=>'#FEE2E2', 'color'=>'#991B1B'],
    'organizadora'=> ['label'=>'Org.',         'bg'=>'#E8F5E9', 'color'=>'#2E7D32'],
];

// Monta placeholders
$inPh = implode(',', array_fill(0, count($statusArr), '?'));

// Query: atletas com inscrição no(s) status solicitados
if ($campId) {
    $params = array_merge([$campId], $statusArr);
    $where  = "i.campeonato_id = ? AND";
} else {
    $params = $statusArr;
    $where  = '';
}

$sql = "
    SELECT a.id, a.nome,
           i.campeonato_id, c.nome AS camp_nome,
           i.tipo, i.status, i.status_desde
    FROM atletas a
    JOIN inscricoes i ON i.atleta_id = a.id
    JOIN campeonatos c ON c.id = i.campeonato_id
    WHERE $where i.status != 'inativa'
      AND (
        (i.tipo IN ($inPh) AND i.status = 'ativa')
        OR (i.status = 'ausente' AND " . (in_array('afastada', $statusArr) ? '1=1' : '1=0') . ")
      )
    ORDER BY i.tipo, a.nome
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Agrupa por status/tipo
$grupos = [];
foreach ($rows as $r) {
    $key = ($r['status'] === 'ausente') ? 'afastada' : $r['tipo'];
    if (!in_array($key, $statusArr)) continue;
    $grupos[$key][] = $r;
}

$total = array_sum(array_map('count', $grupos));
$geradoEm = date('d/m/Y \à\s H:i');
$titulo = $camp ? $camp['nome'] : 'Todos os campeonatos';
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Relatório de Atletas</title>
<style>
  :root {
    --blue: #1a3f8f;
    --blue-mid: #2a52b0;
    --blue-light: #e8eef8;
    --border: #e5e7eb;
    --bg: #f4f6fb;
    --white: #fff;
    --gray: #6b7280;
    --radius: 12px;
    --shadow: 0 2px 12px rgba(0,0,0,.08);
  }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: #1f2937; }

  .header {
    background: linear-gradient(135deg, var(--blue) 0%, var(--blue-mid) 100%);
    color: white;
    padding: 28px 40px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    flex-wrap: wrap;
  }
  .header h1 { font-size: 22px; font-weight: 700; }
  .header p  { font-size: 13px; opacity: .75; margin-top: 3px; }
  .stat-pills { display: flex; gap: 16px; flex-wrap: wrap; }
  .pill {
    background: rgba(255,255,255,.15);
    border-radius: 50px;
    padding: 7px 18px;
    text-align: center;
  }
  .pill .val { font-size: 20px; font-weight: 700; }
  .pill .lbl { font-size: 11px; opacity: .8; text-transform: uppercase; }

  .action-bar {
    display: flex;
    justify-content: flex-end;
    padding: 14px 40px;
    background: var(--white);
    border-bottom: 1px solid var(--border);
  }
  .btn-print {
    background: var(--blue);
    color: white;
    border: none;
    border-radius: 8px;
    padding: 8px 20px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
  }
  .btn-print:hover { background: var(--blue-mid); }

  .content { padding: 28px 40px; display: flex; flex-direction: column; gap: 24px; }

  /* Grupo por status */
  .grupo { background: var(--white); border-radius: var(--radius); box-shadow: var(--shadow); overflow: hidden; }
  .grupo-head {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 20px;
    border-bottom: 1px solid var(--border);
  }
  .grupo-head h2 { font-size: 15px; font-weight: 700; flex: 1; }
  .tag {
    font-size: 11px;
    font-weight: 700;
    border-radius: 50px;
    padding: 4px 12px;
    white-space: nowrap;
  }

  .atl-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 20px;
    border-bottom: 1px solid var(--border);
    transition: background .12s;
  }
  .atl-row:last-child { border-bottom: none; }
  .atl-row:hover { background: var(--blue-light); }

  .avatar {
    width: 32px; height: 32px;
    border-radius: 50%;
    background: var(--blue);
    color: white;
    font-size: 12px; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
  }
  .atl-name { font-size: 14px; font-weight: 500; flex: 1; }
  .camp-badge {
    font-size: 11px;
    color: var(--gray);
    background: var(--bg);
    border-radius: 6px;
    padding: 2px 8px;
    white-space: nowrap;
  }
  .since { font-size: 11px; color: var(--gray); }

  .footer {
    text-align: center;
    padding: 20px;
    color: var(--gray);
    font-size: 12px;
    border-top: 1px solid var(--border);
  }

  @media print {
    .action-bar { display: none; }
    body { background: white; }
    .header, .grupo-head { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .tag { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .atl-row:hover { background: transparent; }
    .content { padding: 16px 20px; }
  }
</style>
</head>
<body>

<div class="header">
  <div>
    <h1>👥 Relatório de Atletas</h1>
    <p><?= htmlspecialchars($titulo) ?> · <?= $geradoEm ?></p>
  </div>
  <div class="stat-pills">
    <div class="pill"><div class="val"><?= $total ?></div><div class="lbl">Atletas</div></div>
    <div class="pill"><div class="val"><?= count($grupos) ?></div><div class="lbl">Grupos</div></div>
  </div>
</div>

<div class="action-bar">
  <button class="btn-print" onclick="window.print()">🖨 Imprimir / PDF</button>
</div>

<div class="content">
<?php foreach ($statusArr as $st):
  if (empty($grupos[$st])) continue;
  $meta = $statusMeta[$st] ?? ['label'=>ucfirst($st),'bg'=>'#e5e7eb','color'=>'#374151'];
  $atletas = $grupos[$st];
?>
  <div class="grupo">
    <div class="grupo-head">
      <h2><?= $meta['label'] ?></h2>
      <span class="tag" style="background:<?= $meta['bg'] ?>;color:<?= $meta['color'] ?>;"><?= count($atletas) ?> atleta<?= count($atletas)!==1?'s':'' ?></span>
    </div>
    <?php foreach ($atletas as $a):
      $iniciais = implode('', array_map(fn($p)=>mb_substr($p,0,1), array_slice(explode(' ', $a['nome']), 0, 2)));
      $desde = $a['status_desde'] ? date('d/m/y', strtotime($a['status_desde'])) : null;
    ?>
    <div class="atl-row">
      <div class="avatar"><?= strtoupper($iniciais) ?></div>
      <span class="atl-name"><?= htmlspecialchars($a['nome']) ?></span>
      <?php if (!$campId): ?><span class="camp-badge"><?= htmlspecialchars($a['camp_nome']) ?></span><?php endif; ?>
      <?php if ($desde && $st==='afastada'): ?><span class="since">desde <?= $desde ?></span><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>
<?php if ($total === 0): ?>
  <div style="text-align:center;padding:60px;color:var(--gray);font-size:15px;">Nenhuma atleta encontrada para os filtros selecionados.</div>
<?php endif; ?>
</div>

<div class="footer">VôleiMaster · Relatório de Atletas · <?= $geradoEm ?></div>
</body>
</html>
