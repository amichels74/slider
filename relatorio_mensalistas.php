<?php
require_once 'api/config.php';

$token = $_GET['token'] ?? '';
if (!$token) die('<p style="font-family:sans-serif;color:red;">Acesso negado.</p>');
$payload = verifyToken($token);
if (!$payload) die('<p style="font-family:sans-serif;color:red;">Token inválido ou expirado.</p>');

$db = getDB();

$camps = $db->query("SELECT id, nome FROM campeonatos WHERE ativo=1 ORDER BY nome")->fetchAll();

$dados = [];
foreach ($camps as $camp) {
    $stmt = $db->prepare("
        SELECT a.nome, i.status, i.status_desde, i.retorno_desde, i.tipo
        FROM atletas a
        JOIN inscricoes i ON i.atleta_id = a.id
        WHERE i.campeonato_id = ?
          AND i.tipo = 'mensalista'
          AND i.status IN ('ativa','ausente')
        ORDER BY i.status ASC, a.nome ASC
    ");
    $stmt->execute([$camp['id']]);
    $atletas = $stmt->fetchAll();
    if (!$atletas) continue;

    $ativas    = array_values(array_filter($atletas, fn($a) => $a['status'] === 'ativa'));
    $afastadas = array_values(array_filter($atletas, fn($a) => $a['status'] === 'ausente'));

    $dados[] = [
        'nome'      => $camp['nome'],
        'ativas'    => $ativas,
        'afastadas' => $afastadas,
        'total'     => count($atletas),
    ];
}

$geradoEm = date('d/m/Y \à\s H:i');
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mensalistas por Campeonato</title>
<style>
  :root {
    --blue-dark: #1a3f8f;
    --blue-mid:  #2a52b0;
    --blue-light:#e8eef8;
    --green-bg:  #D1FAE5;
    --green-txt: #065F46;
    --red-bg:    #FEE2E2;
    --red-txt:   #991B1B;
    --gray:      #6b7280;
    --border:    #e5e7eb;
    --bg:        #f4f6fb;
    --white:     #ffffff;
    --radius:    12px;
    --shadow:    0 2px 12px rgba(0,0,0,.08);
  }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: #1f2937; }

  /* ── Header ───────────────────────────────────── */
  .header {
    background: linear-gradient(135deg, var(--blue-dark) 0%, var(--blue-mid) 100%);
    color: white;
    padding: 32px 40px 28px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    flex-wrap: wrap;
  }
  .header-left h1 { font-size: 24px; font-weight: 700; letter-spacing: -.5px; }
  .header-left p  { font-size: 13px; opacity: .75; margin-top: 4px; }
  .header-stats   { display: flex; gap: 24px; flex-wrap: wrap; }
  .stat-pill {
    background: rgba(255,255,255,.15);
    border-radius: 50px;
    padding: 8px 20px;
    text-align: center;
  }
  .stat-pill .val { font-size: 22px; font-weight: 700; }
  .stat-pill .lbl { font-size: 11px; opacity: .8; text-transform: uppercase; letter-spacing: .5px; }

  /* ── Print / Action bar ───────────────────────── */
  .action-bar {
    display: flex;
    justify-content: flex-end;
    padding: 16px 40px;
    background: var(--white);
    border-bottom: 1px solid var(--border);
    gap: 10px;
  }
  .btn-print {
    background: var(--blue-dark);
    color: white;
    border: none;
    border-radius: 8px;
    padding: 9px 22px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
  }
  .btn-print:hover { background: var(--blue-mid); }

  /* ── Grid of cards ────────────────────────────── */
  .grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 24px;
    padding: 32px 40px;
  }

  /* ── Card ─────────────────────────────────────── */
  .card {
    background: var(--white);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    overflow: hidden;
  }
  .card-head {
    background: var(--blue-dark);
    color: white;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
  }
  .card-head h2 { font-size: 15px; font-weight: 700; }
  .badge-count {
    background: rgba(255,255,255,.2);
    border-radius: 50px;
    padding: 3px 12px;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
  }

  /* section divider */
  .section-label {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .8px;
    color: var(--gray);
    padding: 10px 20px 4px;
  }

  /* ── Athlete row ──────────────────────────────── */
  .atl-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 20px;
    border-bottom: 1px solid var(--border);
    transition: background .15s;
  }
  .atl-row:last-child { border-bottom: none; }
  .atl-row:hover { background: var(--blue-light); }

  .avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--blue-dark);
    color: white;
    font-size: 12px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
  }
  .avatar.afastada { background: #c0392b; }

  .atl-name { font-size: 14px; font-weight: 500; flex: 1; }

  .tag {
    font-size: 11px;
    font-weight: 700;
    border-radius: 50px;
    padding: 3px 10px;
    white-space: nowrap;
  }
  .tag.ativa    { background: var(--green-bg); color: var(--green-txt); }
  .tag.afastada { background: var(--red-bg);   color: var(--red-txt); }

  .since {
    font-size: 11px;
    color: var(--gray);
    white-space: nowrap;
  }

  /* ── Empty card ───────────────────────────────── */
  .empty {
    padding: 28px 20px;
    text-align: center;
    color: var(--gray);
    font-size: 13px;
  }

  /* ── Footer ───────────────────────────────────── */
  .footer {
    text-align: center;
    padding: 24px;
    color: var(--gray);
    font-size: 12px;
    border-top: 1px solid var(--border);
  }

  /* ── Print ────────────────────────────────────── */
  @media print {
    .action-bar { display: none; }
    .header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .card-head { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .grid { padding: 20px; gap: 16px; }
    body { background: white; }
    .atl-row:hover { background: transparent; }
    .tag { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  }
</style>
</head>
<body>

<div class="header">
  <div class="header-left">
    <h1>🏐 Mensalistas por Campeonato</h1>
    <p>Gerado em <?= $geradoEm ?></p>
  </div>
  <div class="header-stats">
    <?php
      $totalAtletas = array_sum(array_column($dados, 'total'));
      $totalCamps   = count($dados);
      $totalAfastadas = array_sum(array_map(fn($d) => count($d['afastadas']), $dados));
    ?>
    <div class="stat-pill">
      <div class="val"><?= $totalCamps ?></div>
      <div class="lbl">Campeonatos</div>
    </div>
    <div class="stat-pill">
      <div class="val"><?= $totalAtletas ?></div>
      <div class="lbl">Mensalistas</div>
    </div>
    <div class="stat-pill">
      <div class="val"><?= $totalAfastadas ?></div>
      <div class="lbl">Afastadas</div>
    </div>
  </div>
</div>

<div class="action-bar">
  <button class="btn-print" onclick="window.print()">🖨 Imprimir / Salvar PDF</button>
</div>

<?php if (empty($dados)): ?>
  <div class="empty" style="padding:60px;font-size:16px;">Nenhum campeonato ativo com mensalistas encontrado.</div>
<?php else: ?>
<div class="grid">
<?php foreach ($dados as $camp): ?>
  <div class="card">
    <div class="card-head">
      <h2><?= htmlspecialchars($camp['nome']) ?></h2>
      <span class="badge-count"><?= $camp['total'] ?> mensalista<?= $camp['total'] !== 1 ? 's' : '' ?></span>
    </div>

    <?php if ($camp['ativas']): ?>
      <div class="section-label">Ativas</div>
      <?php foreach ($camp['ativas'] as $a): ?>
        <?php
          $iniciais = implode('', array_map(fn($p)=>mb_substr($p,0,1), array_slice(explode(' ', $a['nome']), 0, 2)));
        ?>
        <div class="atl-row">
          <div class="avatar"><?= strtoupper($iniciais) ?></div>
          <span class="atl-name"><?= htmlspecialchars($a['nome']) ?></span>
          <span class="tag ativa">Ativa</span>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($camp['afastadas']): ?>
      <div class="section-label">Afastadas</div>
      <?php foreach ($camp['afastadas'] as $a): ?>
        <?php
          $iniciais = implode('', array_map(fn($p)=>mb_substr($p,0,1), array_slice(explode(' ', $a['nome']), 0, 2)));
          $desde = $a['status_desde'] ? date('d/m/y', strtotime($a['status_desde'])) : null;
        ?>
        <div class="atl-row">
          <div class="avatar afastada"><?= strtoupper($iniciais) ?></div>
          <span class="atl-name"><?= htmlspecialchars($a['nome']) ?></span>
          <?php if ($desde): ?><span class="since">desde <?= $desde ?></span><?php endif; ?>
          <span class="tag afastada">Afastada</span>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!$camp['ativas'] && !$camp['afastadas']): ?>
      <div class="empty">Nenhuma mensalista ativa.</div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<div class="footer">VôleiMaster · Relatório de Mensalistas · <?= $geradoEm ?></div>

</body>
</html>
